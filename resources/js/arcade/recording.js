import { DRILL_SECONDS, STEP_SECONDS } from './simulation.js';

export const RECORDING_VERSION = 1;
export const RECORDING_LIMITS = Object.freeze({
    seconds: DRILL_SECONDS, tickRate: Math.round(1 / STEP_SECONDS), sampleRate: 30,
    events: 128, frames: DRILL_SECONDS * 30 + 130,
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
    const entries = actorsFor(initialState);
    const actorIndex = new Map(entries.map(([key], index) => [key, index]));
    const indexFor = key => actorIndex.get(key) ?? -1;
    const recording = {
        version: RECORDING_VERSION, mode: initialState.mode || 'running', direction: initialState.direction,
        seed: initialState.seed ?? null, tuningVersion: initialState.tuningVersion ?? null,
        speedScale: initialState.speedScale ?? null, routeSet: initialState.routeSet ?? null,
        tickRate: RECORDING_LIMITS.tickRate, sampleRate: RECORDING_LIMITS.sampleRate,
        scales: { position: POSITION_SCALE, angle: ANGLE_SCALE, time: TIME_SCALE, gait: ANGLE_SCALE },
        poses: [...POSES], ballPhases: [...BALL_PHASES],
        field: { startX: initialState.startX, goalX: initialState.goalX, rearX: initialState.rearX },
        actors: entries.map(([key, actor]) => ({ key, ...Object.fromEntries(PLAYER_FIELDS
            .filter(field => actor[field] !== undefined).map(field => [field, clone(actor[field])])) })),
        appearance: clone(appearance), frames: [], events: [], result: null, truncated: false,
    };
    const gait = entries.map(() => 0), juking = entries.map(() => false);
    let lastTick = -1, lastTime = 0, eventCursor = 0, brokenTackles = 0, closed = false;
    function addEvent(state, type, actorKey, targetKey, data = {}, time = state.elapsed, terminal = false) {
        // Keep one event slot available for the final result, even after a flood.
        if (recording.events.length >= RECORDING_LIMITS.events - (terminal ? 0 : 1)) {
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
        const controlledKey = state.controlledKey || 'runner';
        let eventAdded = false;
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
            eventAdded = addEvent({ tick: event.tick ?? state.tick }, event.type, event.actorKey, event.targetKey,
                data, event.time ?? state.elapsed) || eventAdded;
        }
        actors.forEach(([key, actor], index) => {
            const isJuking = actor.jukeRemaining > 0;
            if (isJuking && !juking[index]) eventAdded = addEvent(state, 'juke', key, null,
                { direction: Math.sign(actor.jukeDirection || actor.vz || 1) }) || eventAdded;
            juking[index] = isJuking;
            gait[index] += Math.max(0, state.elapsed - lastTime) * Math.hypot(finite(actor.vx), finite(actor.vz)) / 7;
        });
        if (state.mode !== 'passing' && state.brokenTackles > brokenTackles) {
            eventAdded = addEvent(state, 'broken_tackle', controlledKey, null,
                { count: state.brokenTackles - brokenTackles }) || eventAdded;
        }
        brokenTackles = finite(state.brokenTackles);
        if (state.result) {
            recording.result = clone(state.result);
            eventAdded = addEvent(state, 'finished', controlledKey, null, { reason: state.result.reason }, state.elapsed, true) || eventAdded;
            closed = true;
        }
        const sample = lastTick < 0 || state.tick % 2 === 0 || eventAdded || closed;
        lastTick = state.tick;
        lastTime = state.elapsed;
        if (!sample) return false;
        const players = actors.map(([key, actor], index) => {
            const speed = Math.hypot(finite(actor.vx), finite(actor.vz));
            const tackled = ['tackle', 'sack'].includes(state.result?.reason) && key === controlledKey;
            const throwing = key === 'qb' && state.ball?.flight && state.elapsed >= state.ball.flight.startedAt
                && state.elapsed - state.ball.flight.startedAt < .3;
            const pose = tackled ? 3 : throwing ? 4 : juking[index] ? 2 : speed > .08 ? 1 : 0;
            const facing = Math.atan2(Math.sin(finite(actor.facing)), Math.cos(finite(actor.facing)));
            return [quantize(actor.x), quantize(actor.z), quantize(facing, ANGLE_SCALE), pose,
                quantize(speed), quantize(gait[index], ANGLE_SCALE),
                pose === 2 ? quantize(Math.sign(actor.vz || 1) * .22, ANGLE_SCALE) : 0];
        });
        const ball = state.ball || { x: state.runner.x, y: 1.28, z: state.runner.z, phase: 'held', holderKey: 'runner' };
        const frame = [state.tick, quantize(state.elapsed, TIME_SCALE), indexFor(controlledKey), players,
            [quantize(ball.x), quantize(ball.y), quantize(ball.z), BALL_PHASES.indexOf(ball.phase), indexFor(ball.holderKey)]];
        if (recording.frames.length < RECORDING_LIMITS.frames) recording.frames.push(frame);
        else {
            recording.truncated = true;
            if (closed) recording.frames[recording.frames.length - 1] = frame;
        }
        return true;
    }
    capture(initialState);
    return { capture, export: () => freeze(clone(recording)) };
}
