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

test('fans use five instanced meshes with varied clothing and skin colors', () => {
    const seats = Array.from({ length: 100 }, (_, i) => ({ x: i, y: 1, z: 0, yaw: 0 }));
    const crowd = buildStadiumCrowd(seats, { fullness: 50, visitors: 20, seed: 1 }, { uniform: { shirt: '#ff0000' } }, { uniform: { shirt: '#ffffff' } });
    assert.equal(crowd.children.length, 5);
    assert.equal(crowd.userData.attendance, 50); assert.equal(crowd.userData.visitors, 10);
    crowd.children.forEach(mesh => {
        assert.equal(mesh.count, 50); assert.ok(mesh.isInstancedMesh);
        assert.ok([...mesh.instanceMatrix.array].every(Number.isFinite));
        assert.ok(mesh.instanceColor);
    });
});


test('larger fans have shoulder-mounted arms and GPU cheering shared across all instances',()=>{
    const seats=Array.from({length:30},(_,i)=>({x:i*2,y:1,z:0,yaw:0}));
    const crowd=buildStadiumCrowd(seats,{fullness:100,seed:42},{},{});
    const arms=crowd.children.filter(m=>m.name.includes('arms'));
    assert.equal(arms.length,2);assert.ok(crowd.getObjectByName('fan-shirts').geometry.parameters.width>.48);
    for(const mesh of crowd.children) {
        assert.equal(mesh.geometry.getAttribute('fanTraits').count,30);
        const shader={uniforms:{},vertexShader:'#include <beginnormal_vertex>\n#include <begin_vertex>'};
        mesh.material.onBeforeCompile(shader);
        assert.ok(shader.vertexShader.includes('fanSelected'));
        assert.equal(shader.uniforms.fanTime,crowd.userData.animation.uniforms.fanTime);
    }
    const animation=crowd.userData.animation;
    animation.tick(.1);animation.cheer('home');
    assert.equal(animation.uniforms.fanHomeStart.value,.1);assert.equal(animation.uniforms.fanAwayStart.value,-100);
    animation.cheer('away');assert.equal(animation.uniforms.fanAwayStart.value,.1);
    const matrices=crowd.children.map(m=>[...m.instanceMatrix.array]);
    animation.tick(.1);assert.deepEqual(crowd.children.map(m=>[...m.instanceMatrix.array]),matrices);
    animation.reset();assert.equal(animation.uniforms.fanHomeStart.value,-100);
});
