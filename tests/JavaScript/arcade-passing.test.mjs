import test from 'node:test';
import assert from 'node:assert/strict';
import { createPassingDrill, stepPassingDrill, getPassTrajectory, passPosition } from '../../resources/js/arcade/passing.js';
import { startDrill, pauseDrill, STEP_SECONDS, FIELD_WIDTH } from '../../resources/js/arcade/simulation.js';
import { DEFAULT_SPEED_SCALE, GAMEPLAY_TUNING_VERSION, actorSpeed } from '../../resources/js/arcade/tuning.js';

const player = (id, ratings = {}) => ({ id, name: id, role: id === 'QB' ? 'QB' : 'WR',
    ratings: { speed: 70, acceleration: 70, throwing: 100, catching: 90, awareness: 70,
        coverage: 70, strength: 60, tackling: 70, ...ratings } });
const options = (extra = {}) => ({ quarterback: player('QB'), receivers: [player('WR1'), player('WR2')],
    defenders: [player('CB'), player('S')], ...extra });
const drill = extra => startDrill(createPassingDrill(options(extra)));
const near = (a, b, tolerance = 1e-8) => assert.ok(Math.abs(a - b) < tolerance, `${a} != ${b}`);
function advance(state, count, input = {}) {
    for (let i = 0; i < count; i++) stepPassingDrill(state, typeof input === 'function' ? input(state, i) : input);
    return state;
}
function isolatedCatch(ratings = {}, extra = {}) {
    const state = drill({ receivers: [player('WR', ratings)], ...extra });
    state.defenders = [];
    const receiver = state.receivers[0];
    receiver.x = 88; receiver.z = state.quarterback.z;
    receiver.route = { name: 'Fixture', index: 1,
        points: [{ x: receiver.x, z: receiver.z }, { x: receiver.x, z: receiver.z }] };
    return state;
}
function throwAt(state, target = { x: 88, z: FIELD_WIDTH / 2 }) {
    stepPassingDrill(state, { pass: target });
    return state;
}

test('passing uses cloned real identities, stable actor keys and configured local tuning', () => {
    const input = options(), original = structuredClone(input), state = createPassingDrill(input);
    assert.equal(state.mode, 'passing'); assert.equal(state.tuningVersion, GAMEPLAY_TUNING_VERSION);
    assert.equal(state.speedScale, DEFAULT_SPEED_SCALE); assert.equal(state.controlledKey, 'qb');
    assert.equal(state.runner, state.quarterback);
    assert.deepEqual([state.quarterback, ...state.receivers, ...state.defenders].map(p => p.key), ['qb', 'receiver:0', 'receiver:1', 'defender:0']);
    startDrill(state); advance(state, 60); state.receivers[0].ratings.speed = 0;
    assert.deepEqual(input, original);
});

test('preview is pure, finite, ballistic, bounded, and ends at the aimed catch location', () => {
    const state = drill(), before = structuredClone(state), target = { x: 100, z: 20 };
    const points = getPassTrajectory(state, target);
    assert.equal(points.length, 32); assert.deepEqual(state, before);
    near(points[0].x, state.quarterback.x); near(points[0].y, 1.65);
    near(points.at(-1).x, target.x); near(points.at(-1).z, target.z); near(points.at(-1).y, 1.2);
    assert.ok(Math.max(...points.map(p => p.y)) > 2);
    assert.ok(points.every(p => Object.values(p).every(Number.isFinite)));
    assert.equal(getPassTrajectory(state, target, { samples: 1e9 }).length, 128);
    assert.equal(getPassTrajectory(state, target, { samples: NaN }).length, 32);
    throwAt(state, target);
    assert.deepEqual(getPassTrajectory(state, target), []);
});

test('accurate open throw catches from live geometry and automatically hands control to receiver', () => {
    const state = isolatedCatch(), originalReceiver = state.receivers[0];
    throwAt(state); advance(state, 80);
    assert.equal(state.passOutcome, 'complete'); assert.equal(state.controlledKey, 'receiver:0');
    assert.equal(state.runner, originalReceiver); assert.equal(state.runner.id, 'WR');
    assert.equal(state.ball.holderKey, 'receiver:0'); assert.equal(state.ball.phase, 'held');
    assert.ok(state.events.some(event => event.type === 'control_changed' && event.targetKey === 'receiver:0'));
    const x = state.runner.x; advance(state, 30, { x: 1 }); assert.ok(state.runner.x > x + 1);
    advance(state, 600, { x: 1 });
    assert.equal(state.result.reason, 'touchdown'); near(state.result.yards, 30);
    near(state.runner.x, state.goalX); assert.equal(state.result.passOutcome, 'complete');
});

test('unreachable pass becomes incomplete at ground impact, never a scripted catch', () => {
    const state = isolatedCatch(); throwAt(state, { x: 100, z: 40 }); advance(state, 200);
    assert.equal(state.result.reason, 'incomplete'); assert.equal(state.passOutcome, 'incomplete');
    near(state.ball.y, 0); assert.equal(state.ball.phase, 'dead'); assert.equal(state.result.yards, 0);
    assert.equal(state.controlledKey, 'qb'); assert.ok(state.result.time < 2);
    const before = structuredClone(state); advance(state, 50, { pass: { x: 88, z: 20 } }); assert.deepEqual(state, before);
});

test('a nearby defender can intercept a descending throw and end the drill once', () => {
    const state = isolatedCatch();
    state.defenders = drill({ defenders: [player('CB', { catching: 100, coverage: 100, awareness: 100 })] }).defenders;
    const defender = state.defenders[0];
    defender.x = 86; defender.z = state.quarterback.z; defender.coverageTarget = { x: 86, z: defender.z };
    state.receivers[0].x = 105; state.receivers[0].route.points[1].x = 105;
    throwAt(state); advance(state, 200);
    assert.equal(state.result.reason, 'interception'); assert.equal(state.ball.holderKey, 'defender:0');
    assert.equal(state.result.passOutcome, 'interception'); assert.equal(state.ball.phase, 'dead');
    near(state.ball.x, defender.x); near(state.ball.z, defender.z); near(state.ball.y, 1.65);
    near(state.ball.flight.elapsed, state.result.time - state.ball.flight.startedAt);
    assert.equal(state.events.filter(event => event.type === 'interception').length, 1);
});

test('receivers outside either sideline cannot claim a catch using their reach radius', () => {
    for (const side of [-1, 1]) {
        const state = isolatedCatch(), receiver = state.receivers[0];
        const boundary = side === -1 ? 0 : FIELD_WIDTH;
        receiver.z = boundary + side * 0.1;
        receiver.route.points = [position(receiver), position(receiver)];
        state.quarterback.z = boundary - side * 2;
        throwAt(state, { x: 88, z: boundary }); advance(state, 120);
        assert.equal(state.passOutcome, 'incomplete'); assert.equal(state.result.reason, 'incomplete');
        assert.equal(state.result.yards, 0);
        assert.equal(state.events.filter(e => e.type === 'catch_attempt').length, 0);
    }
});

test('catching ratings affect a real shared-seed catch attempt, including a drop', () => {
    const low = throwAt(isolatedCatch({ catching: 0 })), high = throwAt(isolatedCatch({ catching: 100 }));
    advance(low, 100); advance(high, 100);
    assert.equal(low.result.reason, 'incomplete'); assert.equal(low.events.find(e => e.type === 'incomplete').cause, 'drop');
    assert.equal(high.passOutcome, 'complete');
    assert.ok(low.events.find(e => e.type === 'catch_attempt').chance < high.events.find(e => e.type === 'catch_attempt').chance);
});

test('throwing and awareness ratings influence flight speed and moving accuracy', () => {
    const thrower = ratings => drill({ quarterback: player('QB', ratings) });
    const low = thrower({ throwing: 0 }), high = thrower({ throwing: 100 });
    const target = { x: 100, z: 30 };
    throwAt(low, target); throwAt(high, target);
    const error = state => Math.hypot(state.ball.flight.target.x - target.x, state.ball.flight.target.z - target.z);
    assert.ok(error(low) > 0.5); near(error(high), 0);
    assert.ok(low.ball.flight.duration > high.ball.flight.duration);
    const unaware = thrower({ throwing: 100, awareness: 0 }), aware = thrower({ throwing: 100, awareness: 100 });
    advance(unaware, 20, { z: 1 }); advance(aware, 20, { z: 1 }); throwAt(unaware, target); throwAt(aware, target);
    assert.ok(error(unaware) > error(aware)); near(error(aware), 0);
});

test('slants and comeback routes move at rating-driven speed without changing route geometry', () => {
    const slants = drill(), comeback = drill({ routeSet: 'comeback' });
    const shape = structuredClone(comeback.receivers[0].route.points);
    advance(slants, 100); advance(comeback, 100);
    assert.notEqual(slants.receivers[0].z, comeback.receivers[0].z);
    const peak = comeback.receivers[0].x;
    advance(comeback, 140);
    assert.ok(comeback.receivers[0].route.index > 1);
    assert.ok(comeback.receivers[0].x < peak);
    assert.deepEqual(comeback.receivers[0].route.points, shape);
    const slow = drill({ receivers: [player('WR', { speed: 0 })] }), fast = drill({ receivers: [player('WR', { speed: 100 })] });
    advance(slow, 90); advance(fast, 90); assert.ok(fast.receivers[0].x > slow.receivers[0].x + 3);
});

test('coverage and awareness affect CPU tracking, while speed and acceleration stay bounded', () => {
    const low = drill({ defenders: [player('CB', { coverage: 0, awareness: 0 })] });
    const high = drill({ defenders: [player('CB', { coverage: 100, awareness: 100 })] });
    for (let tick = 0; tick < 120; tick++) {
        const defender = high.defenders[0], previous = { vx: defender.vx, vz: defender.vz, facing: defender.facing };
        stepPassingDrill(low); stepPassingDrill(high);
        assert.ok(Math.hypot(defender.vx, defender.vz) <= actorSpeed(defender, high.speedScale) + 1e-8);
        assert.ok(Math.hypot(defender.vx - previous.vx, defender.vz - previous.vz) <= 30 * STEP_SECONDS + 1e-8);
        assert.ok(Math.abs(defender.facing - previous.facing) <= 4.3 * STEP_SECONDS + 1e-8);
    }
    assert.notDeepEqual(low.defenders[0].coverageTarget, high.defenders[0].coverageTarget);
    assert.notEqual(low.defenders[0].x, high.defenders[0].x);
});

test('passing keeps proportional speed tuning with responsive QB acceleration and braking', () => {
    const full = drill({ speedScale: 1 }), tuned = drill({ speedScale: 0.925 });
    full.defenders = []; tuned.defenders = [];
    stepPassingDrill(full, { z: 1 }); stepPassingDrill(tuned, { z: 1 });
    near(full.runner.vz, tuned.runner.vz);
    advance(full, 50, { z: 1 }); advance(tuned, 50, { z: 1 });
    near(tuned.runner.vz / full.runner.vz, 0.925);
    advance(tuned, 50); near(tuned.runner.vz, 0);
});

test('ready, pause, resume and retry preserve deterministic state including a flight', () => {
    const setup = options(), state = createPassingDrill(setup), ready = structuredClone(state);
    stepPassingDrill(state, { pass: { x: 90, z: 20 } }); assert.deepEqual(state, ready);
    startDrill(state); advance(state, 30); throwAt(state, { x: 95, z: 25 }); pauseDrill(state);
    const paused = structuredClone(state); advance(state, 100, { x: 1 }); assert.deepEqual(state, paused);
    startDrill(state); stepPassingDrill(state); assert.equal(state.tick, paused.tick + 1);
    assert.deepEqual(createPassingDrill(setup), ready);
});

test('same seed and recorded fixed-tick inputs exactly reproduce actors, ball, events and result', () => {
    const a = drill({ defenderCount: 2, seed: 832 }), b = drill({ defenderCount: 2, seed: 832 });
    for (let tick = 0; tick < 1200; tick++) {
        const input = tick === 60 ? { pass: { x: 94, z: 24 } } : { x: a.passOutcome === 'complete' ? 1 : 0, juke: tick === 150 ? -1 : 0 };
        stepPassingDrill(a, input); stepPassingDrill(b, input);
    }
    assert.deepEqual(a, b); assert.ok(a.result);
    assert.deepEqual(a.events.map(e => e.id), a.events.map((_, i) => i));
    assert.ok(a.events.every((e, i) => !i || e.time >= a.events[i - 1].time));
    assert.equal(a.events.at(-1).type, 'finished'); near(a.events.at(-1).time, a.result.time);
});

test('invalid, repeated and beyond-scrimmage throws do not change the flight or consume RNG', () => {
    const state = drill(), randomState = state.randomState;
    for (const target of [{ x: NaN, z: 20 }, { x: 90, z: Infinity }, { x: 121, z: 20 }, { x: 90, z: -1 }, { x: 70, z: 20 }, {}, 'bad']) {
        stepPassingDrill(state, { pass: target, x: NaN, z: Infinity });
        assert.equal(state.ball.phase, 'held'); assert.equal(state.randomState, randomState);
    }
    state.quarterback.x = state.startX + 0.01;
    throwAt(state, { x: 90, z: 20 }); assert.equal(state.ball.phase, 'held');
    state.quarterback.x = state.startX - 1; throwAt(state, { x: 95, z: 20 });
    const flight = state.ball.flight, target = { ...flight.target }, rng = state.randomState;
    advance(state, 5, { pass: { x: 100, z: 25 } });
    assert.equal(state.ball.flight, flight); assert.deepEqual(flight.target, target); assert.equal(state.randomState, rng);
    assert.equal(state.events.filter(e => e.type === 'throw').length, 1);
});

test('bounded malformed movement cannot poison state and a stationary isolated drill times out at 20s', () => {
    const state = drill(); state.defenders = [];
    for (const input of [null, undefined, {}, { x: NaN, z: Infinity }, { x: 'bad', z: -Infinity }]) stepPassingDrill(state, input);
    advance(state, 1200);
    assert.equal(state.result.reason, 'timeout'); near(state.result.time, 20);
    assert.ok(Number.isFinite(state.quarterback.x));
    assert.equal(createPassingDrill(options({ speedScale: -100 })).speedScale, 0.75);
    assert.equal(createPassingDrill(options({ speedScale: 100 })).speedScale, 1.1);
    assert.equal(createPassingDrill(options({ speedScale: NaN })).speedScale, DEFAULT_SPEED_SCALE);
});

test('a held ball permits a sack; after release QB contact cannot end the pass', () => {
    const prepare = () => {
        const state = drill({ quarterback: player('QB', { strength: 0 }), defenders: [player('CB', { tackling: 100 })] });
        state.defenders[0].x = state.quarterback.x + 0.1; state.defenders[0].z = state.quarterback.z;
        return state;
    };
    const sack = prepare(); stepPassingDrill(sack); assert.equal(sack.result.reason, 'sack');
    const thrown = prepare(); throwAt(thrown, { x: 100, z: 40 }); assert.equal(thrown.result, null);
    advance(thrown, 200); assert.equal(thrown.result.reason, 'incomplete');
});

test('off-ball QB retreat is bounded without cancelling a legitimate pass in either direction', () => {
    for (const direction of [1, -1]) {
        const state = drill({ direction }); state.defenders = [];
        throwAt(state, { x: direction === 1 ? 115 : 5, z: 40 });
        for (let i = 0; i < 100 && state.ball.phase === 'flight'; i++) {
            stepPassingDrill(state, { x: -direction });
            assert.ok((state.quarterback.x - state.rearX) * direction >= 0);
        }
        assert.equal(state.result.reason, 'incomplete');
        near(state.quarterback.x, state.rearX);
    }
});

test('swept ball geometry catches a fast segment that crosses a receiver between fixed ticks', () => {
    const state = isolatedCatch(); throwAt(state);
    const flight = state.ball.flight;
    Object.assign(flight, { start: { x: 77, y: 1.5, z: FIELD_WIDTH / 2 }, vx: 1000, vy: -1, vz: 0,
        elapsed: 0, startedAt: state.elapsed, duration: 0.02, target: { x: 100, z: FIELD_WIDTH / 2 } });
    Object.assign(state.ball, passPosition(flight, 0));
    stepPassingDrill(state);
    assert.equal(state.passOutcome, 'complete');
    const caught = state.events.find(event => event.type === 'catch');
    assert.ok(caught.time > STEP_SECONDS && caught.time < 2 * STEP_SECONDS);
});

test('receiver contact after the catch resolves tackle using live positions', () => {
    const state = throwAt(isolatedCatch({ strength: 0 }));
    while (state.ball.phase === 'flight' && !state.result) stepPassingDrill(state);
    const defender = drill({ defenders: [player('CB', { tackling: 100 })] }).defenders[0];
    defender.x = state.runner.x + 0.2; defender.z = state.runner.z; state.defenders = [defender];
    stepPassingDrill(state, { x: 1 });
    assert.equal(state.result.reason, 'tackle'); assert.equal(state.result.passOutcome, 'complete');
});

test('route and flight mirror correctly for reverse attack direction', () => {
    const a = drill(), b = drill({ direction: -1 });
    for (let i = 0; i < 300; i++) {
        stepPassingDrill(a, i === 60 ? { pass: { x: 94, z: 24 } } : { x: a.passOutcome === 'complete' ? 1 : 0 });
        stepPassingDrill(b, i === 60 ? { pass: { x: 26, z: 24 } } : { x: b.passOutcome === 'complete' ? -1 : 0 });
        near(a.runner.x + b.runner.x, 120); near(a.runner.z, b.runner.z);
        near(a.ball.x + b.ball.x, 120); near(a.ball.y, b.ball.y);
    }
    assert.equal(a.result.reason, b.result.reason); near(a.result.yards, b.result.yards);
});

test('natural slant lead is playable against coverage and comeback catches remain attainable', () => {
    const slants = drill();
    advance(slants, 600, (state, tick) => tick === 60 ? { pass: { x: 94, z: 24 } } : { x: state.passOutcome === 'complete' ? 1 : 0 });
    assert.equal(slants.result.reason, 'touchdown'); assert.equal(slants.passOutcome, 'complete');
    const comeback = drill({ routeSet: 'comeback', seed: 1 });
    // The second receiver is uncovered in the introductory one-defender drill.
    advance(comeback, 160); const receiver = comeback.receivers[1];
    throwAt(comeback, { x: receiver.route.points.at(-1).x, z: receiver.route.points.at(-1).z });
    advance(comeback, 150);
    assert.equal(comeback.passOutcome, 'complete'); assert.equal(comeback.controlledKey, 'receiver:1');
});

test('a lofted ball cannot be caught by a player under its horizontal path above reach', () => {
    const state = isolatedCatch();
    const receiver = state.receivers[0]; receiver.x = 96; receiver.z = state.quarterback.z;
    receiver.route.points = [position(receiver), position(receiver)];
    throwAt(state, { x: 117, z: state.quarterback.z });
    // Keep this receiver stationary rather than letting route adjustment chase the distant landing.
    advance(state, 200);
    assert.equal(state.result.reason, 'incomplete');
    assert.equal(state.events.filter(e => e.type === 'catch_attempt').length, 0);
    assert.ok(passPosition(state.ball.flight, (96 - 77) / state.ball.flight.vx).y > 3);
});
function position(player) { return { x: player.x, z: player.z }; }

test('passing rejects unsupported setup rather than generating placeholder roster actors', () => {
    for (const input of [{ quarterback: null }, { receivers: [] }, { receivers: [null] }, { defenders: [] },
        { defenderCount: 3 }, { routeSet: 'unknown' }, { direction: 0 }, { receivers: Array(4).fill(player('WR')) }]) {
        assert.throws(() => createPassingDrill(options(input)));
    }
});
