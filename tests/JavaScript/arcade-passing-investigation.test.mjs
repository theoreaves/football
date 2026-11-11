import test from 'node:test';
import assert from 'node:assert/strict';
import {createPassingDrill, stepPassingDrill} from '../../resources/js/arcade/passing.js';
import {startDrill} from '../../resources/js/arcade/simulation.js';

// Controlled comparisons, not human gameplay or a difficulty target. Spread the
// seeds through uint32 space: consecutive small LCG seeds bias the first throw.
const seeds = Array.from({length: 100}, (_, index) => Math.imul(index + 1, 2654435761) >>> 0);
const player = (id, overrides = {}) => ({id, ratings: {speed: 70, acceleration: 70,
    throwing: 70, awareness: 70, catching: 70, coverage: 70, strength: 70, tackling: 70, ...overrides}});
function attempt({seed, routeSet = 'slants', releaseTick = 55, lead = .4,
    quarterback = {}, receiver = {}, defender = {}, open = false, direction = 1} = {}) {
    const state = startDrill(createPassingDrill({seed, direction, routeSet,
        quarterback: player('QB', quarterback), receivers: [player('WR1', receiver), player('WR2', receiver)],
        defenders: [player('CB1', defender), player('CB2', defender)], defenderCount: 2,
        exhibition: {startX: direction === 1 ? 35 : 85, goalX: direction === 1 ? 110 : 10,
            rearX: direction === 1 ? 10 : 110, firstDownX: direction === 1 ? 45 : 75}}));
    if (open) state.defenders = []; // Explicit isolated-catch control, not an official roster.
    let separation = null;
    for (let tick = 0; tick < 600 && state.status === 'running' && !state.passOutcome; tick++) {
        const input = {};
        if (tick === releaseTick) {
            const target = state.receivers[0];
            input.pass = {x: target.x + target.vx * lead, z: target.z + target.vz * lead};
            if (state.defenders.length) separation = Math.min(...state.defenders.map(d => Math.hypot(d.x - target.x, d.z - target.z)));
        }
        stepPassingDrill(state, input);
    }
    const throwEvent = state.events.find(event => event.type === 'throw');
    assert.ok(throwEvent, 'The scripted input must actually release a legal pass');
    assert.ok(state.passOutcome, 'The pass must resolve before the bounded test ends');
    return {state, separation, error: Math.hypot(throwEvent.target.x - throwEvent.actualTarget.x,
        throwEvent.target.z - throwEvent.actualTarget.z)};
}
function sample(options) {
    const result = {complete: 0, interception: 0, drop: 0, ground: 0, out: 0,
        receiverAttempts: 0, defenderAttempts: 0, meanAimError: 0, meanSeparation: 0};
    for (const seed of seeds) {
        const {state, separation, error} = attempt({...options, seed});
        if (state.passOutcome === 'incomplete') result[state.events.find(event => event.type === 'incomplete').cause]++;
        else result[state.passOutcome]++;
        result.receiverAttempts += state.events.filter(event => event.type === 'catch_attempt' && event.actorKey.startsWith('receiver:')).length;
        result.defenderAttempts += state.events.filter(event => event.type === 'catch_attempt' && event.actorKey.startsWith('defender:')).length;
        result.meanAimError += error / seeds.length;
        result.meanSeparation += (separation ?? 0) / seeds.length;
    }
    assert.equal(result.complete + result.interception + result.drop + result.ground + result.out, seeds.length);
    return result;
}

test('early fixed 0.7s lead misses even with no coverage; reachable lead produces catch attempts', () => {
    const overled = sample({releaseTick: 30, lead: .7, open: true});
    const reachable = sample({releaseTick: 30, lead: .4, open: true});
    assert.equal(overled.ground, 100);
    assert.equal(overled.receiverAttempts, 0);
    assert.equal(reachable.receiverAttempts, 100);
    assert.ok(reachable.complete > 70);
});

test('aiming behind a moving slant creates interceptions; leading it clears the same coverage', () => {
    const underthrown = sample({lead: 0}), led = sample({lead: .4});
    assert.ok(underthrown.interception > 50);
    assert.equal(led.interception, 0);
    assert.equal(led.receiverAttempts, 100);
    assert.ok(led.complete > underthrown.complete);
});

test('short slant and comeback windows are catchable in both attack directions', () => {
    for (const routeSet of ['slants', 'comeback']) {
        const forward = sample({routeSet, direction: 1}), reverse = sample({routeSet, direction: -1});
        assert.ok(forward.complete > 70);
        assert.ok(reverse.complete > 70);
        assert.equal(forward.interception, 0);
        assert.equal(reverse.interception, 0);
    }
});

test('open catch quality and throwing precision remain rating-driven under common seeds', () => {
    const lowCatch = sample({open: true, receiver: {catching: 40}});
    const highCatch = sample({open: true, receiver: {catching: 90}});
    assert.equal(lowCatch.receiverAttempts, 100);
    assert.equal(highCatch.receiverAttempts, 100);
    assert.ok(highCatch.complete > lowCatch.complete);
    const lowThrow = sample({open: true, quarterback: {throwing: 40}});
    const highThrow = sample({open: true, quarterback: {throwing: 90}});
    assert.ok(lowThrow.meanAimError > highThrow.meanAimError * 5);
});

test('controlled ratings and route matrix reports attempts separately from drops and misses', () => {
    const rows = [];
    for (const routeSet of ['slants', 'comeback']) for (const releaseTick of [55, 150]) {
        for (const throwing of [50, 90]) for (const catching of [50, 90]) for (const coverage of [50, 90]) {
            const setup = {routeSet, releaseTick, quarterback: {throwing}, receiver: {catching}, defender: {coverage}};
            const result = sample(setup);
            rows.push({routeSet, releaseTick, throwing, catching, coverage, ...result});
            assert.ok(Number.isFinite(result.meanAimError));
            assert.ok(result.receiverAttempts + result.defenderAttempts >= result.complete + result.interception);
        }
    }
    if (process.env.ARCADE_PASSING_REPORT === '1') console.log('ARCADE_PASSING_REPORT=' + JSON.stringify(rows));
});

// Speed changes alter separation and the reachable window rather than granting a
// fixed completion bonus. Do not assert that one fixed aim wins every matchup.
test('receiver speed and defender awareness change observed separation without changing .925 tuning', () => {
    const slow = attempt({seed: 42, receiver: {speed: 40}});
    const fast = attempt({seed: 42, receiver: {speed: 90}});
    assert.notEqual(slow.separation, fast.separation);
    assert.equal(slow.state.speedScale, .925);
    assert.equal(fast.state.speedScale, .925);
    const unaware = attempt({seed: 42, defender: {awareness: 40}});
    const aware = attempt({seed: 42, defender: {awareness: 90}});
    assert.notEqual(unaware.separation, aware.separation);
    if (process.env.ARCADE_PASSING_REPORT === '1') {
        const rows = [];
        for (const speed of [40, 70, 90]) for (const awareness of [40, 70, 90]) {
            rows.push({speed, awareness, ...sample({receiver: {speed}, defender: {awareness}})});
        }
        console.log('ARCADE_PASSING_SPEED_REPORT=' + JSON.stringify(rows));
    }
});
