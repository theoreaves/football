// Isolated local drill state. Nothing in this module writes a roster or official game.
import { GAMEPLAY_TUNING_VERSION, DEFAULT_SPEED_SCALE, clamp, finite, rating,
    normalizeSpeedScale, actorSpeed as speed, actorAcceleration as acceleration } from './tuning.js';
export const STEP_SECONDS = 1 / 60;
export const FIELD_WIDTH = 53.33;
export const DRILL_SECONDS = 20;
export const DRILL_RETREAT_YARDS = 6;
export const CONTACT_RADIUS = 0.9;
export const JUKE_COOLDOWN = 1.15;

export function createActor(player, x, z, facing) {
    return { ...structuredClone(player), ratings: { ...player.ratings }, x, z, vx: 0, vz: 0, facing,
        jukeRemaining: 0, jukeCooldown: 0, jukeDirection: 0, contactLatched: false };
}

export function createDrill({ runner, defenders, defenderCount = 1, direction = 1, seed = 1, speedScale = DEFAULT_SPEED_SCALE }) {
    if (!runner || !Array.isArray(defenders) || ![1, 2].includes(defenderCount) || defenders.length < defenderCount) {
        throw new Error('A roster runner and one or two roster defenders are required.');
    }
    if (![1, -1].includes(direction)) throw new Error('Direction must be 1 or -1.');
    const startX = direction === 1 ? 80 : 40;
    return { mode: 'running', tuningVersion: GAMEPLAY_TUNING_VERSION, speedScale: normalizeSpeedScale(speedScale),
        status: 'ready', tick: 0, elapsed: 0, direction, startX, goalX: direction === 1 ? 110 : 10,
        rearX: startX - direction * DRILL_RETREAT_YARDS,
        seed: finite(seed, 1) >>> 0, randomState: finite(seed, 1) >>> 0, result: null, brokenTackles: 0,
        runner: createActor(runner, startX, FIELD_WIDTH / 2, direction === 1 ? 0 : Math.PI),
        defenders: defenders.slice(0, defenderCount).map((player, index) => createActor(player,
            startX + direction * (10 + index * 8), FIELD_WIDTH / 2 + (index ? 6 : 0), direction === 1 ? Math.PI : 0)) };
}

export function startDrill(state) {
    if (state.status === 'ready' || state.status === 'paused') state.status = 'running';
    return state;
}

export function pauseDrill(state) {
    if (state.status === 'running') state.status = 'paused';
    return state;
}

export function nextRandom(state) {
    state.randomState = (Math.imul(state.randomState, 1664525) + 1013904223) >>> 0;
    return state.randomState / 4294967296;
}

export function moveActor(player, targetX, targetZ, rate) {
    const dx = targetX - player.vx, dz = targetZ - player.vz;
    const length = Math.hypot(dx, dz);
    const blend = length ? Math.min(1, rate * STEP_SECONDS / length) : 0;
    player.vx += dx * blend;
    player.vz += dz * blend;
    player.x += player.vx * STEP_SECONDS;
    player.z += player.vz * STEP_SECONDS;
    if (Math.hypot(player.vx, player.vz) > 0.05) player.facing = Math.atan2(player.vz, player.vx);
}

// First time two moving circles touch, including crossings between fixed ticks.
export function sweptContactTime(a0, a1, b0, b1, radius = CONTACT_RADIUS) {
    const x = a0.x - b0.x, z = a0.z - b0.z;
    const dx = a1.x - a0.x - (b1.x - b0.x), dz = a1.z - a0.z - (b1.z - b0.z);
    const c = x * x + z * z - radius * radius;
    if (c <= 0) return 0;
    const a = dx * dx + dz * dz;
    if (a < 1e-12) return null;
    const b = 2 * (x * dx + z * dz), discriminant = b * b - 4 * a * c;
    if (discriminant < 0) return null;
    const time = (-b - Math.sqrt(discriminant)) / (2 * a);
    return time >= 0 && time <= 1 ? time : null;
}

function finish(state, reason, fraction, previous) {
    const actors = [state.runner, ...state.defenders];
    actors.forEach((player, index) => {
        player.x = previous[index].x + (player.x - previous[index].x) * fraction;
        player.z = previous[index].z + (player.z - previous[index].z) * fraction;
        player.vx = 0;
        player.vz = 0;
    });
    state.elapsed = (state.tick - 1 + fraction) * STEP_SECONDS;
    state.status = 'finished';
    state.result = { reason, yards: (state.runner.x - state.startX) * state.direction, time: state.elapsed };
}

export function stepDrill(state, input = {}) {
    if (state.status !== 'running') return state;
    const runner = state.runner;
    const previous = [runner, ...state.defenders].map(player => ({ x: player.x, z: player.z }));
    runner.jukeCooldown = Math.max(0, runner.jukeCooldown - STEP_SECONDS);
    runner.jukeRemaining = Math.max(0, runner.jukeRemaining - STEP_SECONDS);
    if ((input.juke === -1 || input.juke === 1) && runner.jukeCooldown === 0) {
        runner.jukeDirection = input.juke;
        runner.jukeRemaining = 0.22;
        runner.jukeCooldown = JUKE_COOLDOWN;
    }
    let x = clamp(finite(input.x), -1, 1), z = clamp(finite(input.z), -1, 1);
    const magnitude = Math.max(1, Math.hypot(x, z));
    x /= magnitude;
    z /= magnitude;
    const bursting = runner.jukeRemaining > 0;
    moveActor(runner, x * speed(runner, state.speedScale), bursting ? runner.jukeDirection * speed(runner, state.speedScale) * 1.25 : z * speed(runner, state.speedScale),
        acceleration(runner) * (bursting ? 2 : 1));

    state.defenders.forEach((defender, index) => {
        const awareness = rating(defender, 'awareness') / 100;
        const lead = 0.1 + awareness * 0.35;
        const dx = previous[0].x + runner.vx * lead - defender.x;
        const dz = previous[0].z + runner.vz * lead - defender.z;
        const desired = Math.atan2(dz, dx);
        const difference = Math.atan2(Math.sin(desired - defender.facing), Math.cos(desired - defender.facing));
        // Preserve pursuit turn radius when reducing both teams' running speed.
        const turn = (2.3 + awareness * 2) * STEP_SECONDS * state.speedScale;
        const facing = defender.facing + clamp(difference, -turn, turn);
        moveActor(defender, Math.cos(facing) * speed(defender, state.speedScale), Math.sin(facing) * speed(defender, state.speedScale), acceleration(defender));
        // Heading is the bounded pursuit direction, independent of acceleration lag.
        defender.facing = facing;
        if (Math.hypot(previous[0].x - previous[index + 1].x, previous[0].z - previous[index + 1].z) > CONTACT_RADIUS + 0.4) {
            defender.contactLatched = false;
        }
    });
    state.tick++;
    state.elapsed = state.tick * STEP_SECONDS;

    const events = [];
    const crossing = (start, end, boundary, reason) => {
        if (end !== start) events.push({ time: clamp((boundary - start) / (end - start), 0, 1), reason });
    };
    if ((runner.x - state.goalX) * state.direction >= 0) crossing(previous[0].x, runner.x, state.goalX, 'touchdown');
    if ((runner.x - state.rearX) * state.direction <= 0) crossing(previous[0].x, runner.x, state.rearX, 'out_of_bounds');
    if (runner.z <= 0) crossing(previous[0].z, runner.z, 0, 'out_of_bounds');
    if (runner.z >= FIELD_WIDTH) crossing(previous[0].z, runner.z, FIELD_WIDTH, 'out_of_bounds');
    if (runner.x <= 0) crossing(previous[0].x, runner.x, 0, 'out_of_bounds');
    if (runner.x >= 120) crossing(previous[0].x, runner.x, 120, 'out_of_bounds');
    state.defenders.forEach((defender, index) => {
        if (defender.contactLatched) return;
        const time = sweptContactTime(previous[0], runner, previous[index + 1], defender);
        if (time !== null) events.push({ time, defender, index });
    });
    events.sort((a, b) => a.time - b.time);
    for (const event of events) {
        if (event.reason) {
            finish(state, event.reason, event.time, previous);
            return state;
        }
        const defender = event.defender;
        const oldDefender = previous[event.index + 1];
        const nx = oldDefender.x + (defender.x - oldDefender.x) * event.time - (previous[0].x + (runner.x - previous[0].x) * event.time);
        const nz = oldDefender.z + (defender.z - oldDefender.z) * event.time - (previous[0].z + (runner.z - previous[0].z) * event.time);
        const distance = Math.hypot(nx, nz);
        const runnerSpeed = Math.hypot(runner.vx, runner.vz);
        const squareContact = distance && runnerSpeed ? Math.abs((nx * runner.vx + nz * runner.vz) / (distance * runnerSpeed)) : 1;
        // Glancing contact and strength help; a juke only changes geometry, never grants immunity.
        const escapeChance = clamp(0.16 + (rating(runner, 'strength') - rating(defender, 'tackling')) * 0.006
            + (1 - squareContact) * 0.42, 0.02, 0.85);
        if (nextRandom(state) >= escapeChance) {
            finish(state, 'tackle', event.time, previous);
            return state;
        }
        defender.contactLatched = true;
        defender.vx *= 0.35;
        defender.vz *= 0.35;
        runner.vx *= 0.85;
        runner.vz *= 0.85;
        state.brokenTackles++;
    }
    if (state.elapsed >= DRILL_SECONDS) finish(state, 'timeout', 1, previous);
    return state;
}
