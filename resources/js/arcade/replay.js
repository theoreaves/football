// The v1 playback contract is deliberately independent of simulation and input code.
export const REPLAY_SPEEDS = Object.freeze([.25, .5, 1, 2]);
export const REPLAY_LIMITS = Object.freeze({ bytes: 1000000, frames: 730, events: 128, actors: 6, seconds: 20 });
const POSES = ['idle', 'run', 'juke', 'tackled', 'throw'];
const BALL_PHASES = ['held', 'flight', 'dead'];
const fail = message => { throw new Error(`Invalid replay: ${message}`); };
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
const integer = (value, min, max) => Number.isSafeInteger(value) && value >= min && value <= max;
const finite = (value, min, max) => Number.isFinite(value) && value >= min && value <= max;
const clamp = (value, max) => Math.max(0, Math.min(max, value));
const lerp = (a, b, amount) => a + (b - a) * amount;
function freeze(value) {
    if (value && typeof value === 'object') {
        Object.values(value).forEach(freeze);
        Object.freeze(value);
    }
    return value;
}

// Bound object inputs too, without invoking arbitrary toJSON methods or traversing cycles.
function copyJson(input) {
    let nodes = 0, characters = 0;
    const ancestors = new Set();
    function copy(value, depth) {
        if (++nodes > 100000 || depth > 12) fail('payload is too complex.');
        if (value === null || typeof value === 'boolean') return value;
        if (typeof value === 'number' && Number.isFinite(value)) return value;
        if (typeof value === 'string') {
            characters += value.length;
            if (value.length > 4096 || characters > 100000) fail('text exceeds the size limit.');
            return value;
        }
        if (typeof value !== 'object' || ancestors.has(value)) fail('expected finite JSON data.');
        if (!Array.isArray(value) && ![Object.prototype, null].includes(Object.getPrototypeOf(value))) fail('expected plain JSON objects.');
        ancestors.add(value);
        const result = Array.isArray(value) ? [] : {};
        if (Object.keys(value).length > 1000) fail('collection exceeds the size limit.');
        if (Array.isArray(value) && (value.length > 1000 || Object.keys(value).length !== value.length)) fail('expected a bounded dense array.');
        for (const key of Object.keys(value)) {
            characters += key.length;
            if (key.length > 128 || characters > 200000) fail('object keys exceed the size limit.');
            if (['__proto__', 'constructor', 'prototype'].includes(key)) fail('unsafe object key.');
            const descriptor = Object.getOwnPropertyDescriptor(value, key);
            if (!descriptor || !('value' in descriptor)) fail('expected JSON values, not accessors.');
            result[key] = copy(descriptor.value, depth + 1);
        }
        ancestors.delete(value);
        return result;
    }
    return copy(input, 0);
}

// PHP serializes empty associative maps as []; keep persisted exports valid.
const emptyMap = value => object(value) || (Array.isArray(value) && value.length === 0);
const scalar = value => value === null || ['string', 'number', 'boolean'].includes(typeof value);
function validateAppearance(value, label) {
    if (!emptyMap(value)) fail(`invalid ${label}.`);
    for (const entry of Object.values(value)) {
        if (!scalar(entry) || (typeof entry === 'string' && Object.hasOwn(Object.prototype, entry))) fail(`invalid ${label} value.`);
    }
}

function validate(input) {
    if (typeof input === 'string') {
        if (input.length > REPLAY_LIMITS.bytes || new TextEncoder().encode(input).length > REPLAY_LIMITS.bytes) fail('file exceeds 1 MB.');
        try { input = JSON.parse(input); } catch { fail('file is not valid JSON.'); }
    }
    const data = copyJson(input);
    if (!object(data)) fail('expected a recording object.');
    if (data.version !== 1) fail('unsupported recording version.');
    if (!['running', 'passing'].includes(data.mode) || ![1, -1].includes(data.direction)) fail('unknown drill mode or direction.');
    if (data.tickRate !== 60 || data.sampleRate !== 30 || !object(data.scales)
        || data.scales.position !== 100 || data.scales.angle !== 1000 || data.scales.time !== 1000000
        || data.scales.gait !== 1000) fail('unsupported v1 timing or scales.');
    if (JSON.stringify(data.poses) !== JSON.stringify(POSES)
        || JSON.stringify(data.ballPhases) !== JSON.stringify(BALL_PHASES)) fail('unknown pose or ball phase table.');
    if (!object(data.field) || !['startX', 'goalX', 'rearX'].every(key => finite(data.field[key], -1000, 1000))) fail('invalid field.');
    if (!Array.isArray(data.actors) || data.actors.length < 1 || data.actors.length > REPLAY_LIMITS.actors) fail('invalid actor count.');
    const keys = new Set();
    data.actors.forEach(actor => {
        if (!object(actor) || typeof actor.key !== 'string' || !/^(runner|qb|receiver:[0-2]|defender:[0-1])$/.test(actor.key)
            || keys.has(actor.key)) fail('invalid or duplicate actor key.');
        for (const field of ['name', 'firstname', 'lastname', 'role', 'position', 'side', 'skin_tone']) {
            if (actor[field] != null && (typeof actor[field] !== 'string' || actor[field].length > 255)) fail(`invalid actor ${field}.`);
        }
        if (actor.side != null && !['home', 'away'].includes(actor.side)) fail('invalid actor side.');
        if (actor.id != null && !(integer(actor.id, 0, Number.MAX_SAFE_INTEGER)
            || (typeof actor.id === 'string' && actor.id.length <= 128))) fail('invalid actor id.');
        if (actor.number != null && !(integer(actor.number, 0, 99)
            || (typeof actor.number === 'string' && /^\d{1,2}$/.test(actor.number)))) fail('invalid actor number.');
        for (const field of ['height_inches', 'weight_pounds']) {
            if (actor[field] != null && !finite(actor[field], 0, 1000)) fail(`invalid actor ${field}.`);
        }
        if (actor.appearance != null) validateAppearance(actor.appearance, 'actor appearance');
        keys.add(actor.key);
    });
    if (!keys.has(data.mode === 'running' ? 'runner' : 'qb')) fail('missing starting actor.');
    if (typeof data.truncated !== 'boolean' || !object(data.appearance)) fail('invalid recording metadata.');
    for (const side of ['home', 'away']) {
        const team = data.appearance[side];
        if (team == null) continue;
        if (!emptyMap(team)) fail('invalid team appearance.');
        for (const [field, value] of Object.entries(team)) {
            if (field === 'uniform') {
                if (value != null) validateAppearance(value, 'uniform');
            } else if (['audio', 'stadium', 'cheerleaders', 'mascot', 'staff'].includes(field)) {
                // Preserved export metadata, not consumed by the replay renderer.
                if (value != null && !object(value) && !Array.isArray(value)) fail('invalid team metadata.');
            } else if (!scalar(value)) fail('invalid team appearance value.');
        }
    }
    if (data.result !== null && (!object(data.result) || typeof data.result.reason !== 'string')) fail('invalid result.');
    if (!Array.isArray(data.frames) || !data.frames.length || data.frames.length > REPLAY_LIMITS.frames) fail('invalid frame count.');
    const actorIndex = index => integer(index, -1, data.actors.length - 1);
    let previousTime = -1, previousTick = -1;
    data.frames.forEach(frame => {
        if (!Array.isArray(frame) || frame.length !== 5 || !integer(frame[0], 0, 1200)
            || !integer(frame[1], 0, 20000000) || frame[0] < previousTick || frame[1] < previousTime
            || !actorIndex(frame[2]) || !Array.isArray(frame[3]) || frame[3].length !== data.actors.length) fail('invalid or unordered frame.');
        previousTick = frame[0]; previousTime = frame[1];
        frame[3].forEach(player => {
            if (!Array.isArray(player) || player.length !== 7 || !player.every(value => integer(value, -1000000, 1000000))
                || !integer(player[2], -3142, 3142) || !integer(player[3], 0, POSES.length - 1)
                || player[4] < 0 || player[5] < 0 || !integer(player[6], -1000, 1000)) fail('invalid player pose.');
        });
        const ball = frame[4];
        if (!Array.isArray(ball) || ball.length !== 5 || !ball.slice(0, 3).every(value => integer(value, -100000, 100000))
            || !integer(ball[3], 0, BALL_PHASES.length - 1) || !actorIndex(ball[4])) fail('invalid ball.');
    });
    if (data.frames[0][1] !== 0) fail('missing initial frame.');
    if (!Array.isArray(data.events) || data.events.length > REPLAY_LIMITS.events) fail('invalid event count.');
    data.events.forEach(event => {
        if (!Array.isArray(event) || event.length !== 6 || !integer(event[0], 0, 1200)
            || !integer(event[1], 0, 20000000) || typeof event[2] !== 'string' || !event[2].length || event[2].length > 64
            || !actorIndex(event[3]) || !actorIndex(event[4]) || !emptyMap(event[5])) fail('invalid event.');
        // Normalize only the detached top-level event map, not legitimate nested lists.
        if (Array.isArray(event[5])) event[5] = {};
    });
    return freeze(data);
}

/** Decode actual recorded motion. Discrete values change only at their recorded frame. */
export function createReplayTimeline(input) {
    const recording = validate(input), frames = recording.frames;
    const duration = frames.at(-1)[1] / recording.scales.time;
    const actorKey = index => recording.actors[index]?.key ?? null;
    const events = freeze(recording.events.map((event, index) => ({ id: index, tick: event[0], time: event[1] / 1000000,
        type: event[2], actorKey: actorKey(event[3]), targetKey: actorKey(event[4]), data: event[5] }))
        .sort((a, b) => a.time - b.time || a.id - b.id));
    function sample(time = 0) {
        if (!Number.isFinite(time)) fail('playback time must be finite.');
        const elapsed = clamp(time, duration), microseconds = elapsed * 1000000;
        let low = 0, high = frames.length;
        while (low < high) {
            const middle = (low + high) >>> 1;
            if (frames[middle][1] <= microseconds + .000001) low = middle + 1;
            else high = middle;
        }
        const left = frames[Math.max(0, low - 1)], right = frames[Math.min(low, frames.length - 1)];
        const amount = right[1] > left[1] ? Math.max(0, (microseconds - left[1]) / (right[1] - left[1])) : 0;
        const actors = recording.actors.map((metadata, index) => {
            const a = left[3][index], b = right[3][index], facing = a[2] / 1000;
            const delta = Math.atan2(Math.sin((b[2] - a[2]) / 1000), Math.cos((b[2] - a[2]) / 1000));
            return { ...metadata, x: lerp(a[0], b[0], amount) / 100, z: lerp(a[1], b[1], amount) / 100,
                facing: facing + delta * amount, pose: POSES[a[3]], speed: lerp(a[4], b[4], amount) / 100,
                gaitPhase: lerp(a[5], b[5], amount) / 1000, jukeLean: lerp(a[6], b[6], amount) / 1000 };
        });
        const a = left[4], b = right[4];
        return freeze({ mode: recording.mode, direction: recording.direction, elapsed, field: recording.field, actors,
            ball: { x: lerp(a[0], b[0], amount) / 100, y: lerp(a[1], b[1], amount) / 100,
                z: lerp(a[2], b[2], amount) / 100, phase: BALL_PHASES[a[3]], holderKey: actorKey(a[4]) },
            controlledKey: actorKey(left[2]), events: events.filter(event => event.time <= elapsed),
            result: elapsed >= duration ? recording.result : null, truncated: recording.truncated });
    }
    return Object.freeze({ recording, duration, sample });
}

/** Caller owns the animation clock; seeking never reruns simulation or dispatches events. */
export function createReplayPlayback(timeline) {
    if (!timeline || typeof timeline.sample !== 'function' || !finite(timeline.duration, 0, REPLAY_LIMITS.seconds)) fail('invalid timeline.');
    let time = 0, playing = false, speed = 1;
    return Object.freeze({
        get time() { return time; }, get playing() { return playing; }, get speed() { return speed; },
        get duration() { return timeline.duration; },
        play() { if (time >= timeline.duration) time = 0; playing = timeline.duration > 0; },
        pause() { playing = false; },
        restart() { time = 0; playing = timeline.duration > 0; },
        seek(value) {
            if (!Number.isFinite(value)) fail('playback time must be finite.');
            time = clamp(value, timeline.duration);
            if (time >= timeline.duration) playing = false;
        },
        setSpeed(value) {
            if (!REPLAY_SPEEDS.includes(value)) fail('unsupported playback speed.');
            speed = value;
        },
        advance(seconds) {
            if (!Number.isFinite(seconds) || seconds < 0) fail('clock step must be finite and nonnegative.');
            if (playing) {
                time = clamp(time + seconds * speed, timeline.duration);
                if (time >= timeline.duration) playing = false;
            }
            return timeline.sample(time);
        },
        sample() { return timeline.sample(time); },
    });
}
