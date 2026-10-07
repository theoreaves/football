import test from 'node:test';
import assert from 'node:assert/strict';
import { crowdPlan, buildStadiumCrowd } from '../../resources/js/practice/stadium-crowd.js';

test('attendance percentages and visitor proportions are exact and repeatable across replays', () => {
    const settings = { fullness: 80, visitors: 10, seed: 42 };
    const plan = crowdPlan(1000, settings);
    assert.equal(plan.length, 800);
    assert.equal(plan.filter(fan => fan.allegiance === 'away').length, 80);
    assert.equal(plan.filter(fan => fan.allegiance === 'neutral').length, 216);
    assert.equal(new Set(plan.map(fan => fan.seat)).size, 800);
    assert.deepEqual(crowdPlan(1000, settings), plan);
    assert.notDeepEqual(crowdPlan(1000, { ...settings, seed: 43 }), plan);
    assert.equal(crowdPlan(1000, { fullness: 0 }).length, 0);
    assert.equal(crowdPlan(1000, { fullness: 100, visitors: 100 }).filter(fan => fan.allegiance === 'away').length, 1000);
});

test('fans use three instanced meshes with varied clothing and skin colors', () => {
    const seats = Array.from({ length: 100 }, (_, i) => ({ x: i, y: 1, z: 0, yaw: 0 }));
    const crowd = buildStadiumCrowd(seats, { fullness: 50, visitors: 20, seed: 1 }, { uniform: { shirt: '#ff0000' } }, { uniform: { shirt: '#ffffff' } });
    assert.equal(crowd.children.length, 3);
    assert.equal(crowd.userData.attendance, 50); assert.equal(crowd.userData.visitors, 10);
    crowd.children.forEach(mesh => {
        assert.equal(mesh.count, 50); assert.ok(mesh.isInstancedMesh);
        assert.ok([...mesh.instanceMatrix.array].every(Number.isFinite));
        assert.ok(mesh.instanceColor);
    });
});
