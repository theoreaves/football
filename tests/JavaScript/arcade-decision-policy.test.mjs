import test from 'node:test';
import assert from 'node:assert/strict';
import {createPassingDrill, stepPassingDrill} from '../../resources/js/arcade/passing.js';
import {startDrill} from '../../resources/js/arcade/simulation.js';
import {chooseVisiblePass, createDecisionPolicy, projectRoute} from '../Fixtures/arcade-decision-policy.mjs';
const player = {ratings: Object.fromEntries(['speed','acceleration','awareness','throwing','catching','coverage','strength','tackling'].map(k => [k, 70]))};
function setup(routeSet = 'slants', seed = 42, direction = 1) {
    return startDrill(createPassingDrill({quarterback: player, receivers: [player, player], defenders: [player, player], defenderCount: 2,
        seed, direction, routeSet, speedScale: .925, exhibition: {startX: direction === 1 ? 35 : 85, goalX: direction === 1 ? 110 : 10, rearX: direction === 1 ? 10 : 110}}));
}
test('benchmark route projection follows cuts and stops at the final waypoint', () => {
    const r = { ...player, x: 0, z: 0, route: {index: 0, points: [{x: 2,z: 0},{x:2,z:3}]}};
    assert.deepEqual(projectRoute(r, 10, .925), {x: 2, z: 3});
    assert.deepEqual(projectRoute(r, 0, .925), {x: 0, z: 0});
});
test('benchmark decision does not mutate state or depend on hidden RNG', () => {
    const state = setup();
    for (let i = 0; i < 60; i++) stepPassingDrill(state, {});
    const before = JSON.stringify(state), expected = chooseVisiblePass(state);
    assert.equal(JSON.stringify(state), before);
    state.randomState = 99999; state.seed = 222;
    assert.deepEqual(chooseVisiblePass(state), expected);
});
test('visible decision policy has bounded cadence and deterministically produces real throws', () => {
    for (const routeSet of ['slants', 'comeback']) for (const direction of [1, -1]) {
        const run = () => {
            const state = setup(routeSet, 42, direction), policy = createDecisionPolicy({distance: 10, seed: 42});
            while (state.status === 'running') stepPassingDrill(state, policy.input(state));
            assert.ok(policy.decision.tick >= 48 && policy.decision.tick <= 150);
            assert.equal(policy.decision.tick % 12, 0);
            assert.ok(state.events.some(e => e.type === 'throw'));
            return {result: state.result, decision: policy.decision};
        };
        assert.deepEqual(run(), run());
    }
});
test('visible decision policy never keeps searching after its declared read deadline', () => {
    const state = setup(), policy = createDecisionPolicy();
    state.tick = 145;
    const input = policy.input(state);
    assert.equal(input.pass, undefined);
    assert.equal(input.x, state.direction);
    assert.equal(policy.decision.kind, 'scramble');
    state.tick = 240;
    assert.equal(policy.input(state).pass, undefined);
});
