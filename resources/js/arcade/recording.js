import { DRILL_SECONDS, STEP_SECONDS } from './simulation.js';

export const RECORDING_VERSION = 1;
export const RECORDING_LIMITS = Object.freeze({
    seconds: DRILL_SECONDS, tickRate: Math.round(1 / STEP_SECONDS), sampleRate: 30,
    events: 128, frames: DRILL_SECONDS * 30 + 130,
});
export const FULL_TEAM_RECORDING_LIMITS = Object.freeze({
    seconds: 20, tickRate: 60, sampleRate: 30, events: 256, frames: 858,
});
const POSITION_SCALE = 100, ANGLE_SCALE = 1000, TIME_SCALE = 1000000;
const POSES = ['idle', 'run', 'juke', 'tackled', 'throw'];
const BALL_PHASES = ['held', 'flight', 'dead'];
const PLAYER_FIELDS = ['id', 'name', 'firstname', 'lastname', 'number', 'role', 'position', 'side',
    'skin_tone', 'height_inches', 'weight_pounds', 'appearance'];
const finite = (value, fallback = 0) => Number.isFinite(value) ? value : fallback;
const quantize = (value, scale = POSITION_SCALE) => Math.round(finite(value) * scale) || 0;
const clone = value => JSON.parse(JSON.stringify(value));
function freeze(value) {
    if (value && typeof value === 'object') {
        Object.values(value).forEach(freeze);
        Object.freeze(value);
    }
    return value;
}
function actorsFor(state) {
    if (state.fullTeam) return state.actors.map(actor => [actor.key, actor]);
    return state.mode === 'passing'
        ? [['qb', state.quarterback], ...state.receivers.map((actor, index) => [`receiver:${index}`, actor]),
            ...state.defenders.map((actor, index) => [`defender:${index}`, actor])]
        : [['runner', state.runner], ...state.defenders.map((actor, index) => [`defender:${index}`, actor])];
}

/**
 * Local, bounded playback data, not a second simulation or a persisted game.
 * v1 frames: [tick, timeUs, controlledActor, players, ball]. Players are in the
 * fixed actors[] order: [x, z, facing, pose, speed, gaitPhase, jukeLean].
 * x/z/speed and ball x/y/z use 100 units per yard; angles/gait use 1000 units.
 * Ball: [x, y, z, phase, holderActor]. Unknown/no actor is -1.
 * Events: [tick, timeUs, type, actor, targetActor, data]. Pose/phase names live
 * in the header. Initial, 30 Hz, accepted event, and terminal frames survive.
 * Call capture after EVERY fixed step, including the terminal fractional step.
 * Export is detached, deeply frozen JSON; create a new recorder on every retry.
 */
export function createDrillRecording(initialState, { appearance = initialState.appearance ?? {} } = {}) {
    const fullTeam = initialState.fullTeam === true;
    const limits = fullTeam ? FULL_TEAM_RECORDING_LIMITS : RECORDING_LIMITS;
    const poses = fullTeam ? [...POSES, 'block', 'handoff'] : POSES;
    const entries = actorsFor(initialState);
    const actorIndex = new Map(entries.map(([key], index) => [key, index]));
    const indexFor = key => actorIndex.get(key) ?? -1;
    const recording = {
        version: fullTeam ? 2 : RECORDING_VERSION,
        ...(fullTeam ? { contractVersion: 'live-recording-2', catalogVersion: 'arcade-fullteam-1', fullTeam: true, call: initialState.call } : {}), mode: initialState.mode || 'running', direction: initialState.direction,
        seed: initialState.seed ?? null, tuningVersion: initialState.tuningVersion ?? null,
        speedScale: initialState.speedScale ?? null, routeSet: initialState.routeSet ?? null,
        tickRate: RECORDING_LIMITS.tickRate, sampleRate: RECORDING_LIMITS.sampleRate,
        scales: { position: POSITION_SCALE, angle: ANGLE_SCALE, time: TIME_SCALE, gait: ANGLE_SCALE },
        poses: [...poses], ballPhases: [...BALL_PHASES],
        field: { startX: initialState.startX, goalX: initialState.goalX, rearX: initialState.rearX },
        actors: entries.map(([key, actor]) => ({ key, ...Object.fromEntries(PLAYER_FIELDS
            .filter(field => actor[field] !== undefined).map(field => [field, clone(actor[field])])) })),
        appearance: clone(appearance), frames: [], events: [], result: null, truncated: false,
    };
    const gait = entries.map(() => 0), juking = entries.map(() => false);
    let lastTick = -1, lastTime = 0, eventCursor = 0, brokenTackles = 0, closed = false, lastBallBoundary = null;
    function addEvent(state, type, actorKey, targetKey, data = {}, time = state.elapsed, terminal = false) {
        // Keep one event slot available for the final result, even after a flood.
        if (recording.events.length >= limits.events - (terminal ? 0 : 1)) {
            recording.truncated = true;
            return false;
        }
        recording.events.push([state.tick, quantize(time, TIME_SCALE), String(type).slice(0, 64),
            indexFor(actorKey), indexFor(targetKey), data]);
        return true;
    }
    function capture(state) {
        if (closed || state.tick < lastTick || (state.tick === lastTick && !state.result)) return false;
        if (!Number.isInteger(state.tick) || state.tick < 0 || state.tick > DRILL_SECONDS / STEP_SECONDS
            || !Number.isFinite(state.elapsed) || state.elapsed < lastTime || state.elapsed > DRILL_SECONDS) {
            recording.truncated = true;
            return false;
        }
        const actors = actorsFor(state);
        if (actors.length !== entries.length || actors.some(([key], index) => key !== entries[index][0])) {
            throw new Error('A new drill roster requires a new recording.');
        }
        const controlledKey = state.controlledKey || (fullTeam ? 'offense_QB' : 'runner');
        let eventAdded = false;
        const boundaries = [], gaitBefore = [...gait], previousTime = lastTime;
        if (lastTick < 0) eventAdded = addEvent(state, 'start', controlledKey);
        const events = state.events || [];
        for (; eventCursor < events.length; eventCursor++) {
            const event = events[eventCursor];
            // Final result is added below exactly once and always remains last.
            if (event.type === 'finished') continue;
            const data = {};
            for (const field of ['reason', 'cause', 'success', 'yards', 'chance', 'roll', 'receiverIndex', 'defenderIndex']) {
                const value = event[field];
                if ((typeof value === 'number' && Number.isFinite(value)) || typeof value === 'boolean') data[field] = value;
                else if (typeof value === 'string') data[field] = value.slice(0, 64);
            }
            for (const field of ['target', 'actualTarget']) {
                if (event[field]) data[field] = [quantize(event[field].x), quantize(event[field].z)];
            }
            if (event.contact) {
                data.contact = [quantize(event.contact.x), quantize(event.contact.y), quantize(event.contact.z)];
                if (state.exhibition && [event.contact.x, event.contact.y, event.contact.z].every(Number.isFinite)) {
                    data.contactExact = [event.contact.x, event.contact.y, event.contact.z];
                }
            }
            const accepted = addEvent({ tick: event.tick ?? state.tick }, event.type, event.actorKey, event.targetKey,
                data, event.time ?? state.elapsed);
            if (fullTeam && accepted && event.snapshot) boundaries.push(event);
            eventAdded = accepted || eventAdded;
        }
        actors.forEach(([key, actor], index) => {
            const isJuking = actor.jukeRemaining > 0;
            if (!fullTeam && isJuking && !juking[index]) eventAdded = addEvent(state, 'juke', key, null,
                { direction: Math.sign(actor.jukeDirection || actor.vz || 1) }) || eventAdded;
            juking[index] = isJuking;
            gait[index] += Math.max(0, state.elapsed - lastTime) * Math.hypot(finite(actor.vx), finite(actor.vz)) / 7;
        });
        if (state.mode !== 'passing' && !state.events && state.brokenTackles > brokenTackles) {
            eventAdded = addEvent(state, 'broken_tackle', controlledKey, null,
                { count: state.brokenTackles - brokenTackles }) || eventAdded;
        }
        brokenTackles = finite(state.brokenTackles);
        if (state.result) {
            recording.result = clone(state.result);
            eventAdded = addEvent(state, 'finished', controlledKey, null, { reason: state.result.reason, ...(state.exhibition && [state.runner.x, state.runner.z].every(Number.isFinite) ? {position: [state.runner.x, state.runner.z]} : {}) }, state.elapsed, true) || eventAdded;
            closed = true;
        }
        const ballBoundary = `${state.ball?.phase}:${state.ball?.holderKey}:${state.controlledKey}`;
        const sample = lastTick < 0 || state.tick % 2 === 0 || closed
            || (eventAdded && (!fullTeam || boundaries.length === 0))
            || (fullTeam && boundaries.length === 0 && ballBoundary !== lastBallBoundary);
        lastBallBoundary = ballBoundary;
        lastTick = state.tick;
        lastTime = state.elapsed;
        if (!sample && boundaries.length === 0) return false;
        function frameFor(snapshot, time, tick, terminal = false) {
            const controlled = snapshot.controlledKey || controlledKey;
            const sourceActors = snapshot === state ? actors : snapshot.actors.map(actor => [actor.key, actor]);
            if (sourceActors.length !== entries.length || sourceActors.some(([key], index) => key !== entries[index][0])) {
                throw new Error('Event snapshots must retain the full recording roster.');
            }
            const throwTime = fullTeam ? events.findLast(event => event.type === 'throw' && event.time <= time)?.time : state.ball?.flight?.startedAt;
            const players = sourceActors.map(([key, actor], index) => {
                const speed = Math.hypot(finite(actor.vx), finite(actor.vz));
                const tackled = terminal && ['tackle', 'sack'].includes(state.result?.reason) && key === controlled;
                const throwing = key === (fullTeam ? 'offense_QB' : 'qb') && (fullTeam || state.ball?.flight)
                    && time >= throwTime && time - throwTime < .3;
                const pose = tackled ? 3 : throwing ? 4 : fullTeam && actor.handoffRemaining > 0 ? 6
                    : fullTeam && (actor.blockingKey || actor.blockedBy) ? 5 : actor.jukeRemaining > 0 ? 2 : speed > .08 ? 1 : 0;
                const facing = Math.atan2(Math.sin(finite(actor.facing)), Math.cos(finite(actor.facing)));
                const amount = state.elapsed > previousTime ? Math.max(0, Math.min(1, (time - previousTime) / (state.elapsed - previousTime))) : 1;
                const phase = gaitBefore[index] + (gait[index] - gaitBefore[index]) * amount;
                return [quantize(actor.x), quantize(actor.z), quantize(facing, ANGLE_SCALE), pose,
                    quantize(speed), quantize(phase, ANGLE_SCALE),
                    pose === 2 ? quantize(Math.sign(actor.vz || 1) * .22, ANGLE_SCALE) : 0];
            });
            const ball = snapshot.ball || { x: state.runner.x, y: 1.28, z: state.runner.z, phase: 'held', holderKey: 'runner' };
            return [tick, quantize(time, TIME_SCALE), indexFor(controlled), players,
                [quantize(ball.x), quantize(ball.y), quantize(ball.z), BALL_PHASES.indexOf(ball.phase), indexFor(ball.holderKey)]];
        }
        function appendFrame(frame, terminal = false) {
            const last = recording.frames.at(-1);
            // Several contacts can share an instant. Keep their final presentation,
            // but retain the tick-zero initial frame when release occurs at time zero.
            if (fullTeam && last?.[0] === frame[0] && last?.[1] === frame[1]) recording.frames[recording.frames.length - 1] = frame;
            else if (recording.frames.length < limits.frames) recording.frames.push(frame);
            else {
                recording.truncated = true;
                if (terminal) recording.frames[recording.frames.length - 1] = frame;
            }
        }
        for (const event of boundaries) appendFrame(frameFor(event.snapshot, event.time, event.tick));
        if (sample) appendFrame(frameFor(state, state.elapsed, state.tick, Boolean(state.result)), closed);
        return true;
    }
    capture(initialState);
    return { capture, export: () => freeze(clone(recording)) };
}
