// Headless fixed-step full-unit gameplay. The server owns personnel, game state,
// official accounting and session validation. Legacy drill runtimes stay intact.
import { STEP_SECONDS, FIELD_WIDTH, DRILL_SECONDS, CONTACT_RADIUS, JUKE_COOLDOWN,
    createActor, moveActor, nextRandom, sweptContactTime } from './simulation.js';
import { DEFAULT_SPEED_SCALE, clamp, finite, rating, actorSpeed, actorAcceleration } from './tuning.js';
import { passPosition, PASS_GRAVITY } from './passing.js';
import { FULL_TEAM_VERSION, FULL_TEAM_CALLS, OFFENSIVE_ROLES, DEFENSIVE_ROLES,
    ELIGIBLE_ROLES, fullTeamRoute, defensiveAssignment } from './full-team-plays.js';

export { FULL_TEAM_VERSION, FULL_TEAM_CALLS } from './full-team-plays.js';
const point = actor => ({ x: actor.x, z: actor.z });
const lerp = (a, b, t) => a + (b - a) * t;
const distance = (a, b) => Math.hypot(a.x - b.x, a.z - b.z);
const angle = value => Math.atan2(Math.sin(value), Math.cos(value));
const isLine = role => ['LT', 'LG', 'C', 'RG', 'RT'].includes(role);
const own = (state, role) => state.actors.find(actor => actor.side === state.offenseSide && actor.role === role);
const other = (state, role) => state.defenders.find(actor => actor.role === role);
const at = (before, actor, t) => ({ x: lerp(before.x, actor.x, t), z: lerp(before.z, actor.z, t) });

export function createFullTeamPlay(setup) {
    const { players, call, offenseSide, direction, seed = 1, exhibition } = setup;
    if (!FULL_TEAM_CALLS.includes(call) || ![1, -1].includes(direction) || !['home', 'away'].includes(offenseSide)
        || !exhibition || !['startX', 'goalX', 'rearX'].every(key => Number.isFinite(exhibition[key]))
        || !Array.isArray(players) || players.length !== 22 || (setup.speedScale ?? .925) !== .925) {
        throw new Error('Full-team play requires a valid server-selected 22-player setup at .925 speed.');
    }
    const seen = new Set(), keys = new Set();
    for (const player of players) {
        const offense = player.side === offenseSide;
        if (!['home', 'away'].includes(player.side) || !Number.isInteger(player.id) || player.id <= 0 || seen.has(player.id)
            || keys.has(player.key) || player.key !== `${offense ? 'offense' : 'defense'}_${player.role}`
            || !(offense ? OFFENSIVE_ROLES : DEFENSIVE_ROLES).includes(player.role)
            || !Number.isFinite(player.x) || player.x < 0 || player.x > 120 || !Number.isFinite(player.z) || player.z < 0 || player.z > FIELD_WIDTH) {
            throw new Error('Full-team actors must be unique real saved identities with valid roles and positions.');
        }
        seen.add(player.id); keys.add(player.key);
    }
    if (players.filter(player => player.side === offenseSide).length !== 11) throw new Error('Exactly eleven players per side are required.');
    const state = { fullTeam: true, mode: call.endsWith('_run') ? 'running' : 'passing', kind: call.endsWith('_run') ? 'run' : 'pass', call,
        contractVersion: 'live-recording-2', routeSet: call.endsWith('_run') ? null : call, catalogVersion: FULL_TEAM_VERSION, tuningVersion: FULL_TEAM_VERSION, speedScale: DEFAULT_SPEED_SCALE,
        status: 'ready', tick: 0, elapsed: 0, direction, offenseSide, seed: seed >>> 0, randomState: seed >>> 0,
        ...exhibition, exhibition: true, result: null, passOutcome: null, events: [], brokenTackles: 0,
        handoffComplete: false, blocks: [], pressureKeys: [], observations: [], actors: [] };
    state.actors = players.map(player => ({ ...createActor(player, player.x, player.z, player.side === offenseSide ? (direction === 1 ? 0 : Math.PI) : (direction === 1 ? Math.PI : 0)),
        key: player.key, origin: point(player), assignment: player.side === offenseSide ? (isLine(player.role) ? 'block' : 'route') : defensiveAssignment(player.role),
        observed: null, nextReadTick: 0, readCount: 0, shedProgress: 0, blockTicks: 0, blockedBy: null, blockingKey: null,
        shedUntil: 0, shedFrom: null, route: { name: call.replaceAll('_', ' '), index: 0, points: fullTeamRoute(call, player.role, state.startX, direction) } }));
    state.actorByKey = Object.fromEntries(state.actors.map(actor => [actor.key, actor]));
    state.quarterback = own(state, 'QB'); state.runner = state.quarterback;
    state.defenders = state.actors.filter(actor => actor.side !== offenseSide);
    state.receivers = state.actors.filter(actor => actor.side === offenseSide && ELIGIBLE_ROLES.includes(actor.role));
    if (state.mode === 'running') {
        const rb = own(state, 'RB'), qb = state.quarterback;
        const mesh = {x: (qb.x + rb.x) / 2, z: (qb.z + rb.z) / 2 + (call === 'outside_run' ? 1.5 : 0)};
        qb.facing = Math.atan2(mesh.z - qb.z, mesh.x - qb.x);
        rb.facing = Math.atan2(mesh.z - rb.z, mesh.x - rb.x);
    }
    state.controlledKey = state.quarterback.key;
    state.eligibleReceiverKeys = state.receivers.map(actor => actor.key);
    state.ball = { x: state.runner.x, y: 1.65, z: state.runner.z, phase: 'held', holderKey: state.runner.key, flight: null };
    captureObservation(state);
    delete state._previous;
    return state;
}

export function startFullTeamPlay(state) {
    if (state.status === 'ready' || state.status === 'paused') state.status = 'running';
    return state;
}
export function pauseFullTeamPlay(state) {
    if (state.status === 'running') state.status = 'paused';
    return state;
}
function record(state, type, details = {}, fraction = 1) {
    const snapshot = eventSnapshot(state, fraction);
    state.events.push({ id: state.events.length, tick: state.tick, time: Math.max(0, (state.tick - 1 + fraction) * STEP_SECONDS), type, ...details, snapshot });
}

function eventSnapshot(state, fraction) {
    const actors = state.actors.map(actor => ({ key: actor.key, ...point(actor), vx: actor.vx, vz: actor.vz, facing: actor.facing,
        jukeRemaining: actor.jukeRemaining, blockingKey: actor.blockingKey, blockedBy: actor.blockedBy, handoffRemaining: actor.handoffRemaining ?? 0,
        ...(state.status !== 'finished' && state._previous?.[actor.key] ? at(state._previous[actor.key], actor, fraction) : {}) }));
    const ball = { x: state.ball.x, y: state.ball.y, z: state.ball.z, phase: state.ball.phase, holderKey: state.ball.holderKey };
    const holder = actors.find(actor => actor.key === ball.holderKey);
    if (holder) Object.assign(ball, point(holder), { y: 1.65 });
    return { actors, ball, controlledKey: state.controlledKey };
}

// Saved fatigue is already reflected in reserved ratings. Do not apply a second
// stamina/speed reduction here; canonical participation updates fatigue once.
function move(state, actor, vx, vz, boost = 1) {
    moveActor(actor, vx, vz, actorAcceleration(actor) * boost);
    actor.facing = angle(actor.facing);
}
function steer(state, actor, target, factor = 1, strafe = false) {
    let dx = target.x - actor.x, dz = target.z - actor.z;
    for (const teammate of state.actors) {
        if (teammate.key === actor.key || teammate.side !== actor.side || teammate.blockingKey || teammate.blockedBy) continue;
        const gap = distance(actor, teammate);
        if (gap > .01 && gap < 1.2 && dx * (teammate.x - actor.x) + dz * (teammate.z - actor.z) > 0) {
            const avoid = (1.2 - gap) * 2; dx += (actor.x - teammate.x) / gap * avoid; dz += (actor.z - teammate.z) / gap * avoid;
        }
    }
    const length = Math.hypot(dx, dz);
    const desired = length > .02 ? Math.atan2(dz, dx) : actor.facing;
    const maxTurn = (2.3 + rating(actor, 'awareness') / 50) * STEP_SECONDS * state.speedScale;
    const heading = angle(actor.facing + clamp(angle(desired - actor.facing), -maxTurn, maxTurn));
    const speed = Math.min(actorSpeed(actor) * factor, length * 4);
    move(state, actor, strafe && length > .01 ? dx / length * speed : Math.cos(heading) * speed, strafe && length > .01 ? dz / length * speed : Math.sin(heading) * speed);
    actor.facing = heading;
}
function controlled(state, input) {
    const actor = state.runner;
    actor.jukeRemaining = Math.max(0, actor.jukeRemaining - STEP_SECONDS);
    actor.jukeCooldown = Math.max(0, actor.jukeCooldown - STEP_SECONDS);
    if ([1, -1].includes(input.juke) && actor.jukeCooldown === 0) {
        actor.jukeRemaining = .22; actor.jukeCooldown = JUKE_COOLDOWN; actor.jukeDirection = input.juke;
        record(state, 'juke', { actorKey: actor.key });
    }
    const x = clamp(finite(input.x), -1, 1), z = clamp(finite(input.z), -1, 1), magnitude = Math.max(1, Math.hypot(x, z));
    move(state, actor, x / magnitude * actorSpeed(actor), actor.jukeRemaining ? actor.jukeDirection * actorSpeed(actor) * 1.25 : z / magnitude * actorSpeed(actor), actor.jukeRemaining ? 2 : 1);
}
function captureObservation(state) {
    state.observations.push({ tick: state.tick, holderKey: state.ball.holderKey, ball: { x: state.ball.x, y: state.ball.y, z: state.ball.z,
        phase: state.ball.phase, vx: state.ball.flight?.vx ?? state.runner.vx, vy: state.ball.flight ? state.ball.flight.vy - PASS_GRAVITY * state.ball.flight.elapsed : 0, vz: state.ball.flight?.vz ?? state.runner.vz },
        actors: Object.fromEntries(state.actors.map(actor => [actor.key, { ...point(actor), vx: actor.vx, vz: actor.vz }])) });
    if (state.observations.length > 40) state.observations.shift();
}
function observe(state, defender) {
    if (state.tick < defender.nextReadTick && defender.observed) return defender.observed;
    const delay = Math.round(4 + (1 - rating(defender, 'awareness') / 100) * 15);
    defender.observed = state.observations.findLast(sample => sample.tick <= state.tick - delay) ?? state.observations[0];
    defender.nextReadTick = state.tick + Math.round(3 + (1 - rating(defender, 'awareness') / 100) * 9);
    defender.readCount++;
    return defender.observed;
}
function pursuit(state, defender, observed) {
    const runner = observed.actors[observed.holderKey] ?? observed.actors[state.quarterback.key];
    const dx = runner.x - defender.x, dz = runner.z - defender.z, speed = actorSpeed(defender);
    const a = runner.vx ** 2 + runner.vz ** 2 - speed ** 2;
    const b = 2 * (dx * runner.vx + dz * runner.vz), c = dx * dx + dz * dz;
    let intercept = .2;
    if (Math.abs(a) < 1e-8) { if (b < 0) intercept = -c / b; }
    else {
        const disc = b * b - 4 * a * c;
        if (disc >= 0) {
            const positive = [(-b - Math.sqrt(disc)) / (2 * a), (-b + Math.sqrt(disc)) / (2 * a)].filter(time => time >= 0);
            if (positive.length) intercept = Math.min(...positive);
        }
    }
    // Bounded geometric interception from delayed observed motion, not a speed
    // boost. Deep support takes an angle instead of chasing the runner's feet.
    const lead = clamp(intercept, .08, .35 + rating(defender, 'awareness') * .012);
    return { x: runner.x + runner.vx * lead, z: runner.z + runner.vz * lead };
}
function defend(state, defender) {
    const seen = observe(state, defender), awareness = rating(defender, 'awareness') / 100, coverage = rating(defender, 'coverage') / 100;
    const readReady = state.tick >= Math.round(8 + (1 - awareness) * 22);
    const holder = seen.actors[seen.holderKey];
    const runRead = seen.holderKey && seen.holderKey !== state.quarterback.key || (holder && (holder.x - state.startX) * state.direction > 0);
    let target;
    if (readReady && runRead) {
        defender.assignment = 'pursuit'; target = pursuit(state, defender, seen);
    } else if (/^(DE|DT)/.test(defender.role)) {
        defender.assignment = 'rush';
        const qb = seen.actors[state.quarterback.key];
        target = { x: qb.x, z: /^DE/.test(defender.role) && (defender.x - state.startX) * state.direction > -2 ? defender.origin.z : qb.z };
    } else if (/^CB/.test(defender.role)) {
        defender.assignment = 'man';
        const receiver = own(state, defender.role === 'CB1' ? 'WR1' : 'WR2'), observed = seen.actors[receiver.key];
        const lead = .05 + awareness * .15;
        target = { x: observed.x + observed.vx * lead + state.direction * (.5 + (1 - coverage) * 2), z: observed.z + observed.vz * lead + (defender.role === 'CB1' ? -1 : 1) * (1 - coverage) * 1.5 };
    } else {
        const deep = /^S/.test(defender.role); defender.assignment = deep ? 'deep_zone' : 'short_zone';
        const zone = { x: state.startX + state.direction * (deep ? 13 : 4.5), z: defender.origin.z };
        const candidates = state.receivers.map(receiver => seen.actors[receiver.key]).filter(receiver => distance(receiver, zone) < (deep ? 14 : 7));
        const nearest = candidates.sort((a, b) => distance(a, zone) - distance(b, zone))[0];
        target = nearest && readReady ? { x: lerp(zone.x, nearest.x + state.direction * (deep ? 2 : .7), coverage), z: lerp(zone.z, nearest.z, coverage) } : zone;
    }
    // React only to delayed observed flight, never its hidden intended/actual target.
    if (readReady && seen.ball.phase === 'flight' && seen.ball.vy < 0 && seen.ball.y <= 4 && distance(defender, seen.ball) < 10) {
        const look = .18 + awareness * .18;
        target = { x: seen.ball.x + seen.ball.vx * look, z: seen.ball.z + seen.ball.vz * look };
    }
    if (defender.shedUntil > state.elapsed) target = { x: defender.x - state.direction * 2, z: defender.z + defender.shedSide * 2 };
    steer(state, defender, target, readReady ? 1 : .35, ['man', 'short_zone', 'deep_zone'].includes(defender.assignment));
}
function blockerTarget(state, actor) {
    const mapping = { LT: 'DE1', LG: 'DT1', C: 'DT2', RG: 'LB2', RT: 'DE2', TE: 'LB3', WR1: 'CB1', WR2: 'CB2', WR3: 'LB1', QB: 'LB2' };
    if (state.call === 'outside_run') { mapping.C = 'LB2'; mapping.RG = 'DT2'; }
    const assigned = other(state, mapping[actor.role]);
    const threats = state.defenders.filter(defender => !defender.blockedBy || defender.blockedBy === actor.key);
    const defender = assigned && threats.includes(assigned) ? assigned : threats.sort((a, b) => distance(a, actor) - distance(b, actor))[0];
    if (!defender) return actor.origin;
    if (state.mode === 'passing' && state.ball.phase !== 'held' && !state.passOutcome) return actor.origin;
    if (state.mode === 'passing' && !state.passOutcome) return { x: clamp(defender.x - state.direction * .8, state.startX - 5, state.startX + 5), z: defender.z };
    return { x: defender.x - state.direction * .5, z: defender.z + (state.call === 'outside_run' ? 1.1 : 0) };
}
function offense(state, actor) {
    if (state.mode === 'running' && !state.handoffComplete && [state.quarterback.key, own(state, 'RB').key].includes(actor.key)) {
        // Both players physically meet at a fixed mesh point. User RB steering begins on transfer.
        const rb = own(state, 'RB'), mesh = { x: (state.quarterback.origin.x + rb.origin.x) / 2, z: (state.quarterback.origin.z + rb.origin.z) / 2 + (state.call === 'outside_run' ? 1.5 : 0) };
        steer(state, actor, mesh); return;
    }
    const blocking = isLine(actor.role) || state.mode === 'running' || state.passOutcome === 'complete';
    if (blocking) { steer(state, actor, blockerTarget(state, actor)); return; }
    const route = actor.route;
    if (!route.points.length) { steer(state, actor, actor.origin); return; }
    if (route.index < route.points.length - 1 && distance(actor, route.points[route.index]) < .8) route.index++;
    let target = route.points[route.index];
    // Eligible receivers can adjust to visible ball motion, not its RNG target.
    if (state.ball.phase === 'flight' && distance(actor, state.ball) < 8) {
        const flight = state.ball.flight, future = passPosition(flight, flight.elapsed + .2);
        target = { x: future.x, z: future.z };
    }
    steer(state, actor, target);
}

function advanceBlocks(state) {
    const held = new Set();
    for (const block of state.blocks) {
        const blocker = state.actorByKey[block.blocker], defender = state.actorByKey[block.defender];
        if (block.ended) continue;
        const progress = .3 + rating(defender, 'strength') * .009 + rating(defender, 'awareness') * .002 - rating(blocker, 'blocking') * .006 - rating(blocker, 'strength') * .003;
        defender.shedProgress += STEP_SECONDS * clamp(progress, .16, 1.5);
        if (defender.shedProgress >= 1 || distance(blocker, defender) > 1.45 || blocker.key === state.controlledKey) {
            block.ended = true; blocker.blockingKey = null; defender.blockedBy = null;
            defender.shedFrom = blocker.key; defender.shedUntil = state.elapsed + .65; defender.shedSide = defender.origin.z < FIELD_WIDTH / 2 ? -1 : 1;
            record(state, 'block_shed', { actorKey: blocker.key, targetKey: defender.key }); continue;
        }
        const push = clamp((rating(blocker, 'strength') + rating(blocker, 'blocking') - rating(defender, 'strength') * 2) / 100, -.8, .8);
        const vx = push * state.direction, vz = state.call === 'outside_run' ? (defender.z > FIELD_WIDTH / 2 ? -.5 : .5) : 0;
        // Shared bounded contact velocity, not a positional displacement or teleport.
        const commonX = (blocker.vx + defender.vx) / 2, commonZ = (blocker.vz + defender.vz) / 2;
        blocker.vx = defender.vx = commonX; blocker.vz = defender.vz = commonZ;
        const rate = Math.min(actorAcceleration(blocker), actorAcceleration(defender));
        for (const actor of [blocker, defender]) {
            moveActor(actor, vx, vz, rate); actor.facing = angle(actor.facing); actor.blockTicks++;
            actor.exertion = (actor.exertion ?? 0) + STEP_SECONDS;
            held.add(actor.key);
        }
    }
    return held;
}
function engageBlocks(state, previous) {
    const blockers = state.actors.filter(actor => actor.side === state.offenseSide && actor.key !== state.controlledKey
        && (isLine(actor.role) || state.mode === 'running' && actor.role !== 'RB' || state.passOutcome === 'complete'));
    const contacts = [];
    for (const blocker of blockers) for (const defender of state.defenders) {
        if (blocker.blockingKey || defender.blockedBy || defender.shedFrom === blocker.key && defender.shedUntil > state.elapsed) continue;
        const time = sweptContactTime(previous[blocker.key], blocker, previous[defender.key], defender, 1.05);
        if (time !== null) contacts.push({ blocker, defender, time });
    }
    contacts.sort((a, b) => a.time - b.time || a.blocker.key.localeCompare(b.blocker.key) || a.defender.key.localeCompare(b.defender.key));
    for (const { blocker, defender, time } of contacts) {
        if (blocker.blockingKey || defender.blockedBy) continue;
        Object.assign(blocker, at(previous[blocker.key], blocker, time)); Object.assign(defender, at(previous[defender.key], defender, time));
        blocker.blockingKey = defender.key; defender.blockedBy = blocker.key; defender.shedProgress = 0;
        state.blocks.push({ blocker: blocker.key, defender: defender.key, ended: false });
        record(state, 'block', { actorKey: blocker.key, targetKey: defender.key });
    }
}

function validPass(state, target) {
    return state.mode === 'passing' && state.controlledKey === state.quarterback.key && state.ball.phase === 'held' && !state.ball.flight
        && Number.isFinite(target?.x) && Number.isFinite(target?.z) && target.x >= 0 && target.x <= 120 && target.z >= 0 && target.z <= FIELD_WIDTH
        && (state.quarterback.x - state.startX) * state.direction <= 0 && (target.x - state.quarterback.x) * state.direction >= 1
        && distance(state.quarterback, target) >= 2 && distance(state.quarterback, target) <= 42;
}
function plan(state, target) {
    const qb = state.quarterback, duration = clamp(distance(qb, target) / (21 + rating(qb, 'throwing') * .1), .4, 1.8);
    return { start: { x: qb.x, y: 1.65, z: qb.z }, target: { ...target }, duration, elapsed: 0, gravity: PASS_GRAVITY,
        vx: (target.x - qb.x) / duration, vz: (target.z - qb.z) / duration, vy: (1.2 - 1.65) / duration + PASS_GRAVITY * duration / 2 };
}
export function getFullTeamPassTrajectory(state, target, { samples = 32 } = {}) {
    if (!validPass(state, target)) return [];
    const flight = plan(state, target), count = clamp(Math.floor(finite(samples, 32)), 2, 128);
    return Array.from({ length: count }, (_, index) => passPosition(flight, index * flight.duration / (count - 1)));
}
function release(state, target) {
    if (!validPass(state, target)) return;
    const qb = state.quarterback, moving = Math.hypot(qb.vx, qb.vz) / actorSpeed(qb);
    const spread = (1 - rating(qb, 'throwing') / 100) * (.35 + distance(qb, target) * .055) + moving * (1 - rating(qb, 'awareness') / 100) * .65;
    const actual = { x: target.x + (nextRandom(state) * 2 - 1) * spread * state.direction, z: target.z + (nextRandom(state) * 2 - 1) * spread };
    const flight = { ...plan(state, actual), attemptedKeys: [] };
    state.ball = { ...passPosition(flight, 0), phase: 'flight', holderKey: null, flight };
    record(state, 'throw', { actorKey: qb.key, target: { ...target }, actualTarget: actual }, 0);
}
function finish(state, reason, fraction, previous) {
    for (const actor of state.actors) { Object.assign(actor, at(previous[actor.key], actor, fraction)); actor.vx = 0; actor.vz = 0; }
    state.elapsed = (state.tick - 1 + fraction) * STEP_SECONDS;
    if (['tackle', 'sack', 'out_of_bounds'].includes(reason) && (state.runner.x - state.rearX) * state.direction <= 0) reason = 'safety';
    state.status = 'finished'; state.result = { reason, yards: ['incomplete', 'interception'].includes(reason) ? 0 : (state.runner.x - state.startX) * state.direction,
        time: state.elapsed, passOutcome: state.passOutcome };
    if (state.ball.holderKey) Object.assign(state.ball, point(state.actorByKey[state.ball.holderKey]), { y: 1.65 });
    state.ball.phase = 'dead';
    state.events = state.events.filter(event => event.time <= state.elapsed + 1e-10);
    record(state, 'finished', { reason, actorKey: state.controlledKey }, fraction);
}
function groundTime(flight, height = 0) {
    return (flight.vy + Math.sqrt(Math.max(0, flight.vy ** 2 + 2 * PASS_GRAVITY * (flight.start.y - height)))) / PASS_GRAVITY;
}
// Analytic horizontal interval + descending height window, including between-tick crossings.
export function fullTeamCatchTime(ball0, ball1, oldActor, actor, radius, flight) {
    const x = ball0.x - oldActor.x, z = ball0.z - oldActor.z;
    const dx = ball1.x - ball0.x - actor.x + oldActor.x, dz = ball1.z - ball0.z - actor.z + oldActor.z;
    const a = dx * dx + dz * dz, b = 2 * (x * dx + z * dz), c = x * x + z * z - radius * radius;
    let enter = 0, exit = 1;
    if (a < 1e-12) { if (c > 0) return null; }
    else { const disc = b * b - 4 * a * c; if (disc < 0) return null; enter = Math.max(0, (-b - Math.sqrt(disc)) / (2 * a)); exit = Math.min(1, (-b + Math.sqrt(disc)) / (2 * a)); }
    enter = Math.max(enter, (Math.max(flight.vy / PASS_GRAVITY, groundTime(flight, 2.55)) - flight.elapsed) / STEP_SECONDS);
    exit = Math.min(exit, (groundTime(flight, .45) - flight.elapsed) / STEP_SECONDS);
    return enter <= exit && enter <= 1 && exit >= 0 ? clamp(enter, 0, 1) : null;
}
function flightStep(state, previous) {
    const flight = state.ball.flight, start = passPosition(flight, flight.elapsed), end = passPosition(flight, flight.elapsed + STEP_SECONDS), events = [];
    const ground = groundTime(flight);
    if (ground <= flight.elapsed + STEP_SECONDS) events.push({ time: clamp((ground - flight.elapsed) / STEP_SECONDS, 0, 1), cause: 'ground' });
    for (const [axis, lo, hi] of [['x', 0, 120], ['z', 0, FIELD_WIDTH]]) if (end[axis] <= lo || end[axis] >= hi) events.push({ time: clamp(((end[axis] <= lo ? lo : hi) - start[axis]) / (end[axis] - start[axis]), 0, 1), cause: 'out' });
    for (const actor of [...state.receivers, ...state.defenders]) {
        if (flight.attemptedKeys.includes(actor.key) || actor.blockedBy || actor.blockingKey) continue;
        const receiver = actor.side === state.offenseSide;
        const radius = receiver ? 1.3 + rating(actor, 'catching') * .004 : .7 + rating(actor, 'coverage') * .006;
        const time = fullTeamCatchTime(start, end, previous[actor.key], actor, radius, flight);
        if (time !== null) {
            const pos = at(previous[actor.key], actor, time);
            if (pos.x > 0 && pos.x < 120 && pos.z > 0 && pos.z < FIELD_WIDTH) events.push({ time, actor, receiver });
        }
    }
    events.sort((a, b) => a.time - b.time || (a.actor?.key ?? '').localeCompare(b.actor?.key ?? ''));
    for (const event of events) {
        const contact = passPosition(flight, flight.elapsed + event.time * STEP_SECONDS);
        Object.assign(state.ball, contact);
        if (event.cause) { state.passOutcome = 'incomplete'; record(state, 'incomplete', { cause: event.cause }, event.time); finish(state, 'incomplete', event.time, previous); return null; }
        const actor = event.actor; flight.attemptedKeys.push(actor.key);
        const nearby = event.receiver ? state.defenders.reduce((max, defender) => Math.max(max, clamp(1 - distance(at(previous[actor.key], actor, event.time), at(previous[defender.key], defender, event.time)) / 2.5, 0, 1) * rating(defender, 'coverage') / 100), 0) : 0;
        const chance = event.receiver ? clamp(.4 + rating(actor, 'catching') * .005 + rating(actor, 'awareness') * .0008 - nearby * .25, .1, .98)
            : clamp(.1 + rating(actor, 'catching') * .003 + rating(actor, 'coverage') * .0035 + rating(actor, 'awareness') * .0015, .05, .95);
        const success = nextRandom(state) < chance;
        record(state, 'catch_attempt', { actorKey: actor.key, success, chance, contact }, event.time);
        if (!success && !event.receiver) {
            // A second contact-only roll distinguishes a physical breakup from a missed reach.
            if (nextRandom(state) >= .15 + rating(actor, 'coverage') * .005) continue;
            state.passOutcome = 'incomplete'; record(state, 'pass_breakup', { actorKey: actor.key, contact }, event.time);
            record(state, 'incomplete', { actorKey: actor.key, cause: 'breakup', contact }, event.time);
            finish(state, 'incomplete', event.time, previous); return null;
        }
        if (!success) { state.passOutcome = 'incomplete'; record(state, 'incomplete', { actorKey: actor.key, cause: 'drop', contact }, event.time); finish(state, 'incomplete', event.time, previous); return null; }
        state.ball.holderKey = actor.key; state.ball.phase = 'held';
        if (!event.receiver) { state.passOutcome = 'interception'; record(state, 'interception', { actorKey: actor.key, contact }, event.time); finish(state, 'interception', event.time, previous); return null; }
        state.passOutcome = 'complete'; state.runner = actor; state.controlledKey = actor.key;
        state.catchPoint = { ...contact }; state.catchTime = (state.tick - 1 + event.time) * STEP_SECONDS;
        record(state, 'catch', { actorKey: actor.key, contact }, event.time);
        record(state, 'control_changed', { actorKey: state.quarterback.key, targetKey: actor.key }, event.time);
        if ((contact.x - state.goalX) * state.direction >= 0) { finish(state, 'touchdown', event.time, previous); state.result.yards = (state.goalX - state.startX) * state.direction; return null; }
        return event.time;
    }
    flight.elapsed += STEP_SECONDS; Object.assign(state.ball, end); return null;
}
function carrierContacts(state, previous, after = 0) {
    const runner = state.runner, begin = at(previous[runner.key], runner, after), events = [];
    const boundary = (axis, value, reason) => { const delta = runner[axis] - previous[runner.key][axis]; if (delta) { const time = (value - previous[runner.key][axis]) / delta; if (time >= after && time <= 1) events.push({ time, reason }); } };
    if ((runner.x - state.goalX) * state.direction >= 0) {
        if ((begin.x - state.goalX) * state.direction >= 0) events.push({ time: after, reason: 'touchdown' }); else boundary('x', state.goalX, 'touchdown');
    }
    if (runner.z <= 0) boundary('z', 0, 'out_of_bounds');
    if (runner.z >= FIELD_WIDTH) boundary('z', FIELD_WIDTH, 'out_of_bounds');
    if (runner.x <= 0) boundary('x', 0, 'out_of_bounds');
    if (runner.x >= 120) boundary('x', 120, 'out_of_bounds');
    for (const defender of state.defenders) {
        if (distance(previous[runner.key], previous[defender.key]) > CONTACT_RADIUS + .4) defender.contactLatched = false;
        if (defender.contactLatched) continue;
        const time = sweptContactTime(begin, runner, at(previous[defender.key], defender, after), defender);
        if (time !== null) events.push({ time: after + time * (1 - after), defender });
    }
    events.sort((a, b) => a.time - b.time || (a.defender?.key ?? '').localeCompare(b.defender?.key ?? ''));
    for (const event of events) {
        if (event.reason) { finish(state, event.reason, event.time, previous); return; }
        const defender = event.defender, r = at(previous[runner.key], runner, event.time), d = at(previous[defender.key], defender, event.time);
        const length = distance(r, d), velocity = Math.hypot(runner.vx, runner.vz);
        const square = length && velocity ? Math.abs(((d.x - r.x) * runner.vx + (d.z - r.z) * runner.vz) / (length * velocity)) : 1;
        const escape = clamp(.16 + (rating(runner, 'strength') - rating(defender, 'tackling')) * .006 + (1 - square) * .42, .02, .85);
        if (nextRandom(state) >= escape) {
            record(state, 'tackle', { actorKey: runner.key, targetKey: defender.key }, event.time);
            const sack = state.mode === 'passing' && !state.ball.flight && runner.key === state.quarterback.key && (r.x - state.startX) * state.direction <= 0;
            finish(state, sack ? 'sack' : 'tackle', event.time, previous); return;
        }
        defender.contactLatched = true; state.brokenTackles++;
        record(state, 'broken_tackle', { actorKey: runner.key, targetKey: defender.key }, event.time);
    }
}

export function stepFullTeamPlay(state, input = {}) {
    if (state.status !== 'running') return state;
    const previous = Object.fromEntries(state.actors.map(actor => [actor.key, point(actor)]));
    state._previous = previous;
    for (const actor of state.actors) actor.handoffRemaining = Math.max(0, (actor.handoffRemaining ?? 0) - STEP_SECONDS);
    state.tick++; state.elapsed = state.tick * STEP_SECONDS;
    if (input.pass) release(state, input.pass);
    const held = advanceBlocks(state);
    for (const actor of state.actors) {
        if (held.has(actor.key)) continue;
        if (actor.key === state.controlledKey && !(state.mode === 'running' && !state.handoffComplete)) controlled(state, input);
        else if (actor.side === state.offenseSide) offense(state, actor);
        else defend(state, actor);
    }
    engageBlocks(state, previous);
    if (state.mode === 'running' && !state.handoffComplete) {
        // Resolve pre-handoff QB contact first, so a tackled QB cannot credit the RB.
        carrierContacts(state, previous);
        if (state.status === 'finished') return finalizeTick(state);
        const rb = own(state, 'RB'), qb = state.quarterback;
        if (distance(qb, rb) <= 1) {
            qb.handoffRemaining = rb.handoffRemaining = .25;
            state.handoffComplete = true; state.runner = rb; state.controlledKey = rb.key; state.ball.holderKey = rb.key;
            record(state, 'handoff', { actorKey: qb.key, targetKey: rb.key });
            record(state, 'control_changed', { actorKey: qb.key, targetKey: rb.key });
            carrierContacts(state, previous, 1);
        }
    } else if (state.ball.phase === 'flight') {
        const caughtAt = flightStep(state, previous);
        if (state.status === 'running' && caughtAt !== null) carrierContacts(state, previous, caughtAt);
    } else carrierContacts(state, previous);
    if (state.status === 'running' && state.mode === 'passing' && state.ball.phase === 'held' && !state.passOutcome) {
        for (const defender of state.defenders) if (!state.pressureKeys.includes(defender.key) && distance(defender, state.quarterback) <= 3.5) {
            state.pressureKeys.push(defender.key); record(state, 'pressure', { actorKey: defender.key, targetKey: state.quarterback.key });
        }
    }
    if (state.status === 'running' && state.elapsed >= DRILL_SECONDS) finish(state, 'timeout', 1, previous);
    return finalizeTick(state);
}
function finalizeTick(state) {
    // Defensive/route movement stays within the physical field; carrier crossings
    // are terminally interpolated before this bound is applied.
    for (const actor of state.actors) { actor.x = clamp(actor.x, 0, 120); actor.z = clamp(actor.z, 0, FIELD_WIDTH); }
    if (state.ball.holderKey) Object.assign(state.ball, point(state.actorByKey[state.ball.holderKey]), { y: 1.65 });
    const endSnapshot = eventSnapshot(state, 1);
    for (const event of state.events) if (event.tick === state.tick && Math.abs(event.time - state.elapsed) < 1e-9) event.snapshot = endSnapshot;
    state.events.sort((a, b) => a.time - b.time || a.id - b.id);
    captureObservation(state);
    delete state._previous;
    return state;
}
