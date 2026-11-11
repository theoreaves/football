// Headless, fixed-step passing drill. No Coach, roster, game or persistence imports.
import { STEP_SECONDS, FIELD_WIDTH, DRILL_SECONDS, DRILL_RETREAT_YARDS, CONTACT_RADIUS,
    JUKE_COOLDOWN, createActor, moveActor, nextRandom, sweptContactTime } from './simulation.js';
import { GAMEPLAY_TUNING_VERSION, DEFAULT_SPEED_SCALE, clamp, finite, rating,
    normalizeSpeedScale, actorSpeed, actorAcceleration } from './tuning.js';

export const PASS_GRAVITY = 14;
const RELEASE_HEIGHT = 1.65;
const TARGET_HEIGHT = 1.2;
const MAX_PASS_DISTANCE = 42;
const center = FIELD_WIDTH / 2;
const position = actor => ({ x: actor.x, z: actor.z });
const lerp = (a, b, t) => a + (b - a) * t;
const actors = state => [state.quarterback, ...state.receivers, ...state.defenders];

function record(state, type, details = {}, fraction = 1) {
    state.events.push({ id: state.events.length, tick: state.tick,
        time: Math.max(0, (state.tick - 1 + fraction) * STEP_SECONDS), type, ...details });
}

function makeActor(player, key, x, z, direction) {
    return { ...createActor(player, x, z, direction === 1 ? 0 : Math.PI), key };
}

export function createPassingDrill({ quarterback, receivers, defenders, defenderCount = 1,
    routeSet = 'slants', direction = 1, seed = 1, speedScale = DEFAULT_SPEED_SCALE }) {
    if (!quarterback || !Array.isArray(receivers) || !receivers.length || receivers.length > 3
        || receivers.some(player => !player) || !Array.isArray(defenders)
        || ![1, 2].includes(defenderCount) || defenders.slice(0, defenderCount).some(player => !player)
        || defenders.length < defenderCount) {
        throw new Error('A roster quarterback, one to three receivers, and one or two defenders are required.');
    }
    if (![1, -1].includes(direction)) throw new Error('Direction must be 1 or -1.');
    if (!['slants', 'comeback'].includes(routeSet)) throw new Error('Unknown passing route set.');
    const startX = direction === 1 ? 80 : 40;
    const qb = makeActor(quarterback, 'qb', startX - direction * 3, center, direction);
    const targets = receivers.map((player, index) => {
        const offset = receivers.length === 1 ? -8 : (index - (receivers.length - 1) / 2) * 16 / (receivers.length - 1);
        const z = center + offset, side = offset >= 0 ? 1 : -1;
        const receiver = makeActor(player, `receiver:${index}`, startX + direction, z, direction);
        const point = (yards, lane) => ({ x: startX + direction * yards, z: clamp(lane, 2, FIELD_WIDTH - 2) });
        receiver.route = { name: routeSet === 'slants' ? 'Slant' : 'Comeback', index: 1,
            points: [position(receiver), ...(routeSet === 'slants'
                ? [point(7, z - side * 4), point(18, z - side * 7), point(28, z - side * 7)]
                : [point(13, z), point(8, z + side * 3)])] };
        return receiver;
    });
    return { mode: 'passing', tuningVersion: GAMEPLAY_TUNING_VERSION, speedScale: normalizeSpeedScale(speedScale),
        status: 'ready', tick: 0, elapsed: 0, direction, routeSet, startX,
        goalX: direction === 1 ? 110 : 10, rearX: startX - direction * DRILL_RETREAT_YARDS,
        seed: finite(seed, 1) >>> 0, randomState: finite(seed, 1) >>> 0,
        result: null, passOutcome: null, brokenTackles: 0, events: [], controlledKey: 'qb',
        quarterback: qb, runner: qb, receivers: targets,
        defenders: defenders.slice(0, defenderCount).map((player, index) => {
            const mark = targets[index % targets.length];
            return { ...makeActor(player, `defender:${index}`, startX + direction * (5 + index),
                mark.z + (index && targets.length === 1 ? 5 : 0), -direction),
            markKey: mark.key, coverageTarget: position(mark), assignment: 'coverage' };
        }),
        ball: { x: qb.x, y: RELEASE_HEIGHT, z: qb.z, phase: 'held', holderKey: 'qb', flight: null },
    };
}

function validPass(state, target) {
    if (!target || !Number.isFinite(target.x) || !Number.isFinite(target.z)
        || !['ready', 'running', 'paused'].includes(state.status)
        || state.controlledKey !== 'qb' || state.ball.phase !== 'held' || state.ball.flight
        || (state.quarterback.x - state.startX) * state.direction > 0) return false;
    const distance = Math.hypot(target.x - state.quarterback.x, target.z - state.quarterback.z);
    return target.x >= 0 && target.x <= 120 && target.z >= 0 && target.z <= FIELD_WIDTH
        && (target.x - state.quarterback.x) * state.direction >= 1 && distance >= 2 && distance <= MAX_PASS_DISTANCE;
}

function planPass(state, target) {
    const qb = state.quarterback, distance = Math.hypot(target.x - qb.x, target.z - qb.z);
    const duration = clamp(distance / (21 + rating(qb, 'throwing') * 0.1), 0.4, 1.8);
    return { start: { x: qb.x, y: RELEASE_HEIGHT, z: qb.z }, target: { ...target }, duration,
        elapsed: 0, startedAt: state.elapsed, gravity: PASS_GRAVITY,
        vx: (target.x - qb.x) / duration, vz: (target.z - qb.z) / duration,
        vy: (TARGET_HEIGHT - RELEASE_HEIGHT) / duration + PASS_GRAVITY * duration / 2 };
}

export function passPosition(flight, time) {
    return { x: flight.start.x + flight.vx * time, z: flight.start.z + flight.vz * time,
        y: flight.start.y + flight.vy * time - flight.gravity * time * time / 2 };
}

// Nominal, rating-aware preview. Sampling never consumes RNG or changes the drill.
export function getPassTrajectory(state, target, { samples = 32 } = {}) {
    if (!validPass(state, target)) return [];
    const flight = planPass(state, target), count = clamp(Math.floor(finite(samples, 32)), 2, 128);
    return Array.from({ length: count }, (_, index) => passPosition(flight, index * flight.duration / (count - 1)));
}

function releasePass(state, target) {
    if (!validPass(state, target)) return;
    const qb = state.quarterback;
    const distance = Math.hypot(target.x - qb.x, target.z - qb.z);
    const moving = Math.hypot(qb.vx, qb.vz) / actorSpeed(qb, state.speedScale);
    const spread = (1 - rating(qb, 'throwing') / 100) * (0.35 + distance * 0.055)
        + moving * (1 - rating(qb, 'awareness') / 100) * 0.65;
    const actual = { x: target.x + (nextRandom(state) * 2 - 1) * spread * state.direction,
        z: target.z + (nextRandom(state) * 2 - 1) * spread };
    const flight = { ...planPass(state, actual), attemptedKeys: [] };
    state.ball = { ...passPosition(flight, 0), phase: 'flight', holderKey: null, flight };
    qb.facing = Math.atan2(actual.z - qb.z, actual.x - qb.x);
    record(state, 'throw', { actorKey: 'qb', target: { ...target }, actualTarget: actual }, 0);
}

function moveControlled(state, input) {
    const runner = state.runner;
    runner.jukeCooldown = Math.max(0, runner.jukeCooldown - STEP_SECONDS);
    runner.jukeRemaining = Math.max(0, runner.jukeRemaining - STEP_SECONDS);
    if ((input.juke === -1 || input.juke === 1) && runner.jukeCooldown === 0) {
        runner.jukeDirection = input.juke; runner.jukeRemaining = 0.22; runner.jukeCooldown = JUKE_COOLDOWN;
    }
    const x = clamp(finite(input.x), -1, 1), z = clamp(finite(input.z), -1, 1);
    const magnitude = Math.max(1, Math.hypot(x, z)), speed = actorSpeed(runner, state.speedScale);
    moveActor(runner, x / magnitude * speed,
        runner.jukeRemaining ? runner.jukeDirection * speed * 1.25 : z / magnitude * speed,
        actorAcceleration(runner) * (runner.jukeRemaining ? 2 : 1));
}

function steer(state, player, target, bounded = false) {
    const dx = target.x - player.x, dz = target.z - player.z, distance = Math.hypot(dx, dz);
    const speed = Math.min(actorSpeed(player, state.speedScale), distance * 5);
    let facing = distance > 0.01 ? Math.atan2(dz, dx) : player.facing;
    if (bounded) {
        const turn = (2.3 + rating(player, 'awareness') / 50) * STEP_SECONDS * state.speedScale;
        facing = player.facing + clamp(Math.atan2(Math.sin(facing - player.facing), Math.cos(facing - player.facing)), -turn, turn);
    }
    moveActor(player, Math.cos(facing) * speed, Math.sin(facing) * speed, actorAcceleration(player));
    if (bounded) player.facing = facing;
}

function moveReceivers(state) {
    for (const receiver of state.receivers) {
        if (receiver.key === state.controlledKey) continue;
        const route = receiver.route;
        let target = route.points[route.index];
        if (route.index < route.points.length - 1 && Math.hypot(target.x - receiver.x, target.z - receiver.z) < 0.8) {
            route.index++; target = route.points[route.index];
        }
        const flight = state.ball.phase === 'flight' && state.ball.flight;
        // A small, physical adjustment to a nearby pass, never a teleport or scripted catch.
        if (flight && flight.duration - flight.elapsed < 0.85
            && Math.hypot(flight.target.x - receiver.x, flight.target.z - receiver.z) < 6) target = flight.target;
        steer(state, receiver, target);
    }
}

function moveDefenders(state) {
    state.defenders.forEach((defender, index) => {
        const awareness = rating(defender, 'awareness') / 100, coverage = rating(defender, 'coverage') / 100;
        if (state.passOutcome === 'complete' || (state.ball.phase === 'held' && state.elapsed > 4 + index)) {
            defender.assignment = state.passOutcome === 'complete' ? 'pursuit' : 'rush';
            steer(state, defender, { x: state.runner.x + state.runner.vx * (0.1 + awareness * 0.35),
                z: state.runner.z + state.runner.vz * (0.1 + awareness * 0.35) }, true);
        } else {
            defender.assignment = 'coverage';
            const receiver = state.receivers[index % state.receivers.length];
            const cadence = 3 + Math.floor((1 - awareness) * 9);
            if (state.tick % cadence === 0) {
                const lead = 0.05 + awareness * 0.15;
                defender.coverageTarget = { x: receiver.x + receiver.vx * lead - state.direction * (0.65 + (1 - coverage) * 1.2),
                    z: receiver.z + receiver.vz * lead + (index % 2 ? 1 : -1) * (1 - coverage) * 1.3 };
                const flight = state.ball.phase === 'flight' && state.ball.flight;
                if (flight && flight.duration - flight.elapsed < 0.8
                    && Math.hypot(flight.target.x - defender.x, flight.target.z - defender.z) < 8) {
                    defender.coverageTarget.x = lerp(defender.coverageTarget.x, flight.target.x, coverage * awareness);
                    defender.coverageTarget.z = lerp(defender.coverageTarget.z, flight.target.z, coverage * awareness);
                }
            }
            steer(state, defender, defender.coverageTarget, true);
        }
        if (Math.hypot(defender.x - state.runner.x, defender.z - state.runner.z) > CONTACT_RADIUS + 0.4) defender.contactLatched = false;
    });
}

function groundTime(flight, height = 0) {
    const root = flight.vy * flight.vy + 2 * flight.gravity * (flight.start.y - height);
    return root < 0 ? null : (flight.vy + Math.sqrt(root)) / flight.gravity;
}

// Intersection of a swept horizontal catch cylinder and the descending ball's height window.
// Checking only the final point would tunnel through players on fast throws.
function catchTime(ball0, ball1, oldActor, player, radius, flight) {
    const x = ball0.x - oldActor.x, z = ball0.z - oldActor.z;
    const dx = ball1.x - ball0.x - (player.x - oldActor.x), dz = ball1.z - ball0.z - (player.z - oldActor.z);
    const a = dx * dx + dz * dz, b = 2 * (x * dx + z * dz), c = x * x + z * z - radius * radius;
    let enter = 0, exit = 1;
    if (a < 1e-12) { if (c > 0) return null; }
    else {
        const discriminant = b * b - 4 * a * c;
        if (discriminant < 0) return null;
        enter = Math.max(0, (-b - Math.sqrt(discriminant)) / (2 * a));
        exit = Math.min(1, (-b + Math.sqrt(discriminant)) / (2 * a));
    }
    const descend = Math.max(0, flight.vy / flight.gravity);
    const top = groundTime(flight, 2.55) ?? descend;
    enter = Math.max(enter, (Math.max(top, descend) - flight.elapsed) / STEP_SECONDS);
    exit = Math.min(exit, ((groundTime(flight, 0.45) ?? 0) - flight.elapsed) / STEP_SECONDS);
    return enter <= exit && enter <= 1 && exit >= 0 ? clamp(enter, 0, 1) : null;
}

function syncHeldBall(state) {
    if (state.ball.phase !== 'held') return;
    const holder = actors(state).find(player => player.key === state.ball.holderKey);
    if (holder) Object.assign(state.ball, { x: holder.x, y: RELEASE_HEIGHT, z: holder.z });
}

function finish(state, reason, fraction, previous) {
    actors(state).forEach(player => {
        player.x = lerp(previous[player.key].x, player.x, fraction);
        player.z = lerp(previous[player.key].z, player.z, fraction);
        player.vx = 0; player.vz = 0;
    });
    state.elapsed = (state.tick - 1 + fraction) * STEP_SECONDS;
    state.status = 'finished';
    if (state.ball.phase === 'flight') {
        const time = state.elapsed - state.ball.flight.startedAt;
        Object.assign(state.ball, passPosition(state.ball.flight, time));
        state.ball.flight.elapsed = time;
    } else syncHeldBall(state);
    state.ball.phase = 'dead';
    state.ball.y = Math.max(0, state.ball.y);
    state.result = { reason, yards: ['incomplete', 'interception'].includes(reason) ? 0 : (state.runner.x - state.startX) * state.direction,
        time: state.elapsed, passOutcome: state.passOutcome };
    record(state, 'finished', { reason, actorKey: state.controlledKey }, fraction);
}

function resolveFlight(state, previous) {
    const ball = state.ball, flight = ball.flight;
    const end = passPosition(flight, flight.elapsed + STEP_SECONDS), events = [];
    const ground = groundTime(flight);
    if (ground <= flight.elapsed + STEP_SECONDS) events.push({ time: clamp((ground - flight.elapsed) / STEP_SECONDS, 0, 1), type: 'ground' });
    for (const [axis, low, high] of [['x', 0, 120], ['z', 0, FIELD_WIDTH]]) {
        if (end[axis] < low || end[axis] > high) {
            const boundary = end[axis] < low ? low : high;
            events.push({ time: clamp((boundary - ball[axis]) / (end[axis] - ball[axis]), 0, 1), type: 'out' });
        }
    }
    for (const player of [...state.receivers, ...state.defenders]) {
        if (flight.attemptedKeys.includes(player.key)) continue;
        const receiver = player.key.startsWith('receiver:');
        const radius = receiver ? 1.3 + rating(player, 'catching') * 0.004 : 0.7 + rating(player, 'coverage') * 0.006;
        const time = catchTime(ball, end, previous[player.key], player, radius, flight);
        if (time !== null) {
            const x = lerp(previous[player.key].x, player.x, time);
            const z = lerp(previous[player.key].z, player.z, time);
            if (x > 0 && x < 120 && z > 0 && z < FIELD_WIDTH) events.push({ time, player, receiver });
        }
    }
    events.sort((a, b) => a.time - b.time || (a.player?.key ?? '').localeCompare(b.player?.key ?? ''));
    for (const event of events) {
        if (!event.player) {
            state.passOutcome = 'incomplete';
            record(state, 'incomplete', { cause: event.type }, event.time);
            finish(state, 'incomplete', event.time, previous); return null;
        }
        const player = event.player;
        flight.attemptedKeys.push(player.key);
        const pressure = event.receiver ? state.defenders.reduce((worst, defender) => {
            const distance = Math.hypot(lerp(previous[player.key].x, player.x, event.time) - lerp(previous[defender.key].x, defender.x, event.time),
                lerp(previous[player.key].z, player.z, event.time) - lerp(previous[defender.key].z, defender.z, event.time));
            return Math.max(worst, clamp(1 - distance / 2.5, 0, 1) * rating(defender, 'coverage') / 100);
        }, 0) : 0;
        const chance = event.receiver
            ? clamp(0.4 + rating(player, 'catching') * 0.005 + rating(player, 'awareness') * 0.0008 - pressure * 0.25, 0.1, 0.98)
            : clamp(0.1 + rating(player, 'catching') * 0.003 + rating(player, 'coverage') * 0.0035 + rating(player, 'awareness') * 0.0015, 0.05, 0.95);
        const success = nextRandom(state) < chance;
        record(state, 'catch_attempt', { actorKey: player.key, success, chance }, event.time);
        if (!success && !event.receiver) continue;
        if (!success) {
            state.passOutcome = 'incomplete';
            record(state, 'incomplete', { cause: 'drop', actorKey: player.key }, event.time);
            finish(state, 'incomplete', event.time, previous); return null;
        }
        ball.holderKey = player.key; ball.phase = 'held';
        flight.elapsed += event.time * STEP_SECONDS;
        if (!event.receiver) {
            state.passOutcome = 'interception';
            record(state, 'interception', { actorKey: player.key }, event.time);
            finish(state, 'interception', event.time, previous); return null;
        }
        state.passOutcome = 'complete';
        state.controlledKey = player.key; state.runner = player;
        state.quarterback.vx = 0; state.quarterback.vz = 0;
        record(state, 'catch', { actorKey: player.key }, event.time);
        record(state, 'control_changed', { actorKey: 'qb', targetKey: player.key }, event.time);
        syncHeldBall(state);
        return event.time;
    }
    flight.elapsed += STEP_SECONDS; Object.assign(ball, end);
    return null;
}

function resolveRun(state, previous, after = 0) {
    const runner = state.runner, old = previous[runner.key], events = [];
    const crossing = (axis, boundary, reason) => {
        const delta = runner[axis] - old[axis];
        const time = delta ? clamp((boundary - old[axis]) / delta, 0, 1) : 0;
        events.push({ time: Math.max(after, time), reason });
    };
    if ((runner.x - state.goalX) * state.direction >= 0) crossing('x', state.goalX, 'touchdown');
    if ((runner.x - state.rearX) * state.direction <= 0) crossing('x', state.rearX, 'out_of_bounds');
    if (runner.z <= 0) crossing('z', 0, 'out_of_bounds');
    if (runner.z >= FIELD_WIDTH) crossing('z', FIELD_WIDTH, 'out_of_bounds');
    for (const defender of state.defenders) {
        if (defender.contactLatched) continue;
        const begin = { x: lerp(old.x, runner.x, after), z: lerp(old.z, runner.z, after) };
        const oldDefender = previous[defender.key];
        const otherBegin = { x: lerp(oldDefender.x, defender.x, after), z: lerp(oldDefender.z, defender.z, after) };
        const time = sweptContactTime(begin, runner, otherBegin, defender);
        if (time !== null) events.push({ time: after + time * (1 - after), defender });
    }
    events.sort((a, b) => a.time - b.time);
    for (const event of events) {
        if (event.reason) { finish(state, event.reason, event.time, previous); return; }
        const defender = event.defender, oldDefender = previous[defender.key];
        const nx = lerp(oldDefender.x, defender.x, event.time) - lerp(old.x, runner.x, event.time);
        const nz = lerp(oldDefender.z, defender.z, event.time) - lerp(old.z, runner.z, event.time);
        const distance = Math.hypot(nx, nz), speed = Math.hypot(runner.vx, runner.vz);
        const square = distance && speed ? Math.abs((nx * runner.vx + nz * runner.vz) / (distance * speed)) : 1;
        const chance = clamp(0.16 + (rating(runner, 'strength') - rating(defender, 'tackling')) * 0.006 + (1 - square) * 0.42, 0.02, 0.85);
        if (nextRandom(state) >= chance) {
            finish(state, state.controlledKey === 'qb' && (runner.x - state.startX) * state.direction <= 0 ? 'sack' : 'tackle', event.time, previous); return;
        }
        defender.contactLatched = true; defender.vx *= 0.35; defender.vz *= 0.35;
        runner.vx *= 0.85; runner.vz *= 0.85; state.brokenTackles++;
        record(state, 'broken_tackle', { actorKey: runner.key, targetKey: defender.key }, event.time);
    }
}

export function stepPassingDrill(state, input = {}) {
    if (state.status !== 'running') return state;
    input = input && typeof input === 'object' ? input : {};
    const previous = Object.fromEntries(actors(state).map(player => [player.key, position(player)]));
    state.tick++;
    if (input.pass) releasePass(state, input.pass);
    moveControlled(state, input);
    if (state.ball.phase === 'flight') {
        // Releasing the ball does not end QB control, but off-ball retreat must stay in view.
        const qb = state.quarterback;
        const x = clamp(qb.x, Math.min(state.rearX, state.goalX), Math.max(state.rearX, state.goalX));
        const z = clamp(qb.z, 0, FIELD_WIDTH);
        if (x !== qb.x) { qb.x = x; qb.vx = 0; }
        if (z !== qb.z) { qb.z = z; qb.vz = 0; }
    }
    moveReceivers(state); moveDefenders(state);
    const wasFlying = state.ball.phase === 'flight';
    const caughtAt = wasFlying ? resolveFlight(state, previous) : null;
    if (state.status === 'finished') return state;
    if (state.ball.phase === 'held') resolveRun(state, previous, caughtAt ?? 0);
    if (state.status === 'finished') return state;
    state.elapsed = state.tick * STEP_SECONDS;
    syncHeldBall(state);
    if (state.elapsed >= DRILL_SECONDS) finish(state, 'timeout', 1, previous);
    return state;
}
