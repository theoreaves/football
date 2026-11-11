import test from 'node:test';
import assert from 'node:assert/strict';
import { createDrill, startDrill, pauseDrill, stepDrill, sweptContactTime, STEP_SECONDS, JUKE_COOLDOWN, FIELD_WIDTH, DRILL_RETREAT_YARDS } from '../../resources/js/arcade/simulation.js';
import { DEFAULT_SPEED_SCALE, GAMEPLAY_TUNING_VERSION } from '../../resources/js/arcade/tuning.js';

const player = (ratings = {}, id = 'runner') => ({ id, name: id, role: 'RB', ratings: { speed: 70, acceleration: 70, strength: 60, tackling: 60, awareness: 70, ...ratings } });
function drill(options = {}) {
    return startDrill(createDrill({ runner: player(), defenders: [player({}, 'LB1'), player({}, 'S1')], ...options }));
}
function alone(state) {
    // Test fixture isolates movement from pursuit, without a separate gameplay mode.
    state.defenders = [];
    return state;
}
function advance(state, count, input = { x: 1 }) {
    for (let tick = 0; tick < count; tick++) stepDrill(state, input);
    return state;
}
const near = (a, b, tolerance = 1e-9) => assert.ok(Math.abs(a - b) < tolerance, `${a} != ${b}`);

test('identical roster, seed and tick inputs reproduce state and terminal outcome', () => {
    const a = drill({ defenderCount: 2, seed: 832 }), b = drill({ defenderCount: 2, seed: 832 });
    for (let tick = 0; tick < 1200; tick++) {
        const input = { x: 1, z: Math.sin(tick / 90) * 0.2, juke: tick === 60 ? 1 : 0 };
        stepDrill(a, input); stepDrill(b, input);
    }
    assert.deepEqual(a, b);
    assert.ok(a.result);
});

test('real roster identity survives and source objects are never mutated', () => {
    const runner = player(), defenders = [player({}, 'CB')];
    const original = structuredClone({ runner, defenders });
    const state = advance(drill({ runner, defenders }), 240);
    assert.equal(state.runner.id, runner.id);
    assert.equal(state.defenders[0].id, 'CB');
    state.runner.ratings.speed = 0;
    assert.deepEqual({ runner, defenders }, original);
});

test('speed and acceleration ratings separately affect live movement', () => {
    const slow = alone(drill({ runner: player({ speed: 0 }) }));
    const fast = alone(drill({ runner: player({ speed: 100 }) }));
    advance(slow, 90); advance(fast, 90);
    assert.ok(fast.runner.x > slow.runner.x + 3);
    const sluggish = alone(drill({ runner: player({ acceleration: 0 }) }));
    const responsive = alone(drill({ runner: player({ acceleration: 100 }) }));
    advance(sluggish, 10); advance(responsive, 10);
    assert.ok(responsive.runner.vx > sluggish.runner.vx * 1.9);
});

test('normalization prevents diagonal boost and releasing input brakes', () => {
    const straight = advance(alone(drill()), 60, { x: 1 });
    const diagonal = advance(alone(drill()), 60, { x: 1, z: 1 });
    near(Math.hypot(diagonal.runner.vx, diagonal.runner.vz), straight.runner.vx);
    near(Math.hypot(diagonal.runner.x - 80, diagonal.runner.z - FIELD_WIDTH / 2), straight.runner.x - 80);
    advance(straight, 60, {});
    near(straight.runner.vx, 0);
});

test('juke is a responsive lateral burst with cooldown, not retriggered every tick', () => {
    const state = alone(drill());
    stepDrill(state, { x: 1, juke: -1 });
    assert.ok(state.runner.vz < 0);
    near(state.runner.jukeCooldown, JUKE_COOLDOWN);
    advance(state, 20, { juke: 1 });
    assert.equal(state.runner.jukeDirection, -1);
    assert.equal(state.runner.jukeRemaining, 0);
    advance(state, 60, { juke: 1 });
    assert.equal(state.runner.jukeDirection, 1);
    assert.ok(state.runner.jukeCooldown > 0);
});

test('defender pursuit has bounded heading, acceleration and speed', () => {
    const state = drill();
    const defender = state.defenders[0];
    defender.facing = 0;
    defender.x = 105; // Keep the movement-limit fixture away from terminal contacts.
    for (let tick = 0; tick < 100; tick++) {
        const facing = defender.facing, vx = defender.vx, vz = defender.vz;
        stepDrill(state, { z: 1 });
        assert.equal(state.status, 'running');
        assert.ok(Math.abs(defender.facing - facing) <= 4.3 * STEP_SECONDS + 1e-10);
        assert.ok(Math.hypot(defender.vx - vx, defender.vz - vz) <= 30 * STEP_SECONDS + 1e-10);
        assert.ok(Math.hypot(defender.vx, defender.vz) <= 10 + 1e-10);
    }
});

test('swept contact catches crossing paths, initial overlap, and rejects misses', () => {
    near(sweptContactTime({ x: 0, z: 0 }, { x: 10, z: 0 }, { x: 5, z: 0 }, { x: 5, z: 0 }, 1), 0.4);
    assert.equal(sweptContactTime({ x: 0, z: 0 }, { x: 10, z: 0 }, { x: 5, z: 2 }, { x: 5, z: 2 }, 1), null);
    assert.equal(sweptContactTime({ x: 0, z: 0 }, { x: 0, z: 0 }, { x: 0, z: 0 }, { x: 0, z: 0 }), 0);
});

function contactState(strength, tackling, seed = 1, glancing = false) {
    const state = drill({ runner: player({ strength }), defenders: [player({ tackling })], seed });
    state.runner.vx = 8;
    state.defenders[0].x = 80 + (glancing ? 0 : 0.85);
    state.defenders[0].z = FIELD_WIDTH / 2 + (glancing ? 0.85 : 0);
    return state;
}

test('contact uses strength, tackling and actual angle, with seeded outcomes', () => {
    const weak = contactState(0, 100), strong = contactState(100, 0);
    stepDrill(weak, { x: 1 }); stepDrill(strong, { x: 1 });
    assert.equal(weak.result.reason, 'tackle');
    assert.equal(strong.result, null);
    assert.equal(strong.brokenTackles, 1);
    const square = contactState(60, 60), glance = contactState(60, 60, 1, true);
    stepDrill(square, { x: 1 }); stepDrill(glance, { x: 1 });
    assert.equal(square.result.reason, 'tackle');
    assert.equal(glance.result, null);
});

test('juke never grants blanket contact invulnerability', () => {
    const state = contactState(0, 100);
    stepDrill(state, { x: 1, juke: 1 });
    assert.ok(state.runner.jukeRemaining > 0);
    assert.equal(state.result.reason, 'tackle');
});

test('broken-contact latch avoids rerolls while overlapping, then rearms on separation', () => {
    const state = contactState(100, 0);
    stepDrill(state, { x: 1 });
    const randomState = state.randomState;
    stepDrill(state, { x: 1 });
    assert.equal(state.randomState, randomState);
    state.defenders[0].x = 100;
    stepDrill(state);
    assert.equal(state.defenders[0].contactLatched, false);
});

test('mirroring attack direction mirrors physical result and preserves yards', () => {
    const a = drill({ defenderCount: 2 }), b = drill({ defenderCount: 2, direction: -1 });
    for (let tick = 0; tick < 600; tick++) {
        stepDrill(a, { x: 1, z: 0.1, juke: tick === 80 ? 1 : 0 });
        stepDrill(b, { x: -1, z: 0.1, juke: tick === 80 ? 1 : 0 });
        near(a.runner.x + b.runner.x, 120);
        near(a.runner.z, b.runner.z);
    }
    assert.equal(a.result.reason, b.result.reason);
    near(a.result.yards, b.result.yards);
});

test('ready, paused and terminal states are idempotent; resume and reset are local', () => {
    const state = createDrill({ runner: player(), defenders: [player()] });
    const ready = structuredClone(state);
    stepDrill(state, { x: 1 }); assert.deepEqual(state, ready);
    startDrill(state); stepDrill(state, { x: 1 }); pauseDrill(state);
    const paused = structuredClone(state);
    advance(state, 200); assert.deepEqual(state, paused);
    startDrill(state); assert.equal(state.status, 'running');
    advance(state, 1400);
    const finished = structuredClone(state), result = state.result;
    startDrill(state); pauseDrill(state); advance(state, 100);
    assert.deepEqual(state, finished); assert.equal(state.result, result);
    assert.deepEqual(createDrill({ runner: player(), defenders: [player()] }), ready);
});

test('touchdown and sideline positions are resolved at crossing, not frame overshoot', () => {
    const td = advance(alone(drill()), 600);
    assert.equal(td.result.reason, 'touchdown'); near(td.runner.x, 110); near(td.result.yards, 30);
    const out = alone(drill()); out.runner.z = 0.01;
    advance(out, 100, { z: -1 });
    assert.equal(out.result.reason, 'out_of_bounds'); near(out.runner.z, 0);
    const reverse = advance(alone(drill({ direction: -1 })), 600, { x: -1 });
    assert.equal(reverse.result.reason, 'touchdown'); near(reverse.runner.x, 10);
});

test('swept collision wins before a later touchdown within the same tick', () => {
    const state = drill({ runner: player({ strength: 0 }), defenders: [player({ tackling: 100 })] });
    state.runner.x = 108; state.runner.vx = 300;
    state.defenders[0].x = 109; state.defenders[0].z = state.runner.z;
    stepDrill(state, { x: 1 });
    assert.equal(state.result.reason, 'tackle'); assert.ok(state.runner.x < 110);
});

test('stationary drill times out once and malformed input remains finite', () => {
    const state = alone(drill());
    advance(state, 1200, { x: NaN, z: Infinity });
    assert.equal(state.result.reason, 'timeout'); near(state.result.time, 20);
    assert.ok(Number.isFinite(state.runner.x));
});

test('drill rejects missing roster defenders and unsupported counts/directions', () => {
    assert.throws(() => createDrill({ runner: player(), defenders: [], defenderCount: 1 }));
    assert.throws(() => drill({ defenderCount: 3 }));
    assert.throws(() => drill({ direction: 0 }));
});


test('original and slower tuning reward a clean cut and juke against one or two defenders', () => {
    const runner = player({ speed: 78, acceleration: 80, strength: 65 });
    const defender = player({ speed: 70, acceleration: 70, awareness: 65, tackling: 72 });
    for (const speedScale of [1, DEFAULT_SPEED_SCALE]) {
    for (const defenderCount of [1, 2]) {
        for (const seed of [1, 832, 998]) {
            const options = { runner, defenders: [defender, { ...defender, id: 'S1' }], defenderCount, seed, speedScale };
            const straight = advance(drill(options), 600);
            assert.equal(straight.result.reason, 'tackle');
            // Hold forward, cut left with a juke as the defender approaches,
            // then straighten after clearing the lane. Several nearby timings work.
            // Slower approach speed moves the clean avoidance window slightly later.
            // Keep the old timings intact at scale1 and require three clean options at .925.
            for (const cutTick of speedScale === 1 ? [24, 27, 30] : [27, 28, 30]) {
                const evasive = drill(options);
                for (let tick = 0; tick < 600 && !evasive.result; tick++) {
                    stepDrill(evasive, { x: 1, z: tick >= cutTick && tick < 100 ? -1 : 0,
                        juke: tick === cutTick ? -1 : 0 });
                }
                assert.equal(evasive.result.reason, 'touchdown');
                near(evasive.result.yards, 30);
                assert.equal(evasive.brokenTackles, 0, 'Route wins through movement, not a lucky contact roll');
            }
        }
    }
    }
});

test('speed tuning is versioned, proportional for both teams, and keeps acceleration responsive', () => {
    const full = drill({ speedScale: 1 }), tuned = drill();
    assert.equal(tuned.speedScale, 0.925); assert.equal(tuned.tuningVersion, GAMEPLAY_TUNING_VERSION);
    full.defenders[0].x = 105; tuned.defenders[0].x = 105;
    stepDrill(full, { x: 1 }); stepDrill(tuned, { x: 1 });
    near(full.runner.vx, tuned.runner.vx); near(full.defenders[0].vx, tuned.defenders[0].vx);
    advance(full, 50); advance(tuned, 50);
    near(tuned.runner.vx / full.runner.vx, DEFAULT_SPEED_SCALE);
    near(Math.hypot(tuned.defenders[0].vx, tuned.defenders[0].vz)
        / Math.hypot(full.defenders[0].vx, full.defenders[0].vz), DEFAULT_SPEED_SCALE);
    assert.equal(drill({ speedScale: Infinity }).speedScale, DEFAULT_SPEED_SCALE);
    assert.equal(drill({ speedScale: -10 }).speedScale, 0.75);
    assert.equal(drill({ speedScale: 10 }).speedScale, 1.1);
});


test('rear drill boundary keeps retreat in view and ends once in either direction', () => {
    for (const direction of [1, -1]) {
        const state = alone(drill({ direction }));
        assert.equal(state.rearX, direction === 1 ? 74 : 46);
        advance(state, 180, { x: -direction });
        assert.equal(state.result.reason, 'out_of_bounds');
        near(state.runner.x, state.rearX);
        near(state.result.yards, -DRILL_RETREAT_YARDS);
        assert.ok(state.result.time < 2);
        const finished = structuredClone(state), result = state.result;
        advance(state, 120, { x: direction });
        assert.deepEqual(state, finished);
        assert.equal(state.result, result);
    }
});
