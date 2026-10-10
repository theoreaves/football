import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {buildPenaltyContact, samplePenaltyContact, applyPenaltyContact, resetPenaltyContactPose} from '../../resources/js/practice/penalty-contact.js';
import {buildFootballPlayer, animateFootballPlayer} from '../../resources/js/practice/player-model.js';
import {penaltyMoment} from '../../resources/js/practice/penalty-presentation.js';
import {buildRefereePaths} from '../../resources/js/practice/referees.js';

const play = direction => ({duration:6,passing:true,direction,line:40,receiver_role:'WR2',catch_at:3.4,
    result_at:5.3,contact_at:5.1,ball:[[0,40,1,26],[6,50,1,43]],events:[],
    players:[
        {team:'offense',role:'LT',path:[[0,40-direction,0,22.3],[6,40+direction*.3,0,22.3]]},
        {team:'defense',role:'DE1',path:[[0,40+direction,0,22],[6,40+direction*.8,0,22]]},
        {team:'defense',role:'DT1',path:[[0,40+direction,0,25],[6,40+direction*.8,0,25]]},
        {team:'offense',role:'WR2',path:[[0,40-direction,0,43],[6,60,0,43]]},
        {team:'defense',role:'CB2',path:[[0,40+direction*3,0,43],[6,60,0,44]]},
        {team:'defense',role:'S2',path:[[0,40+direction*12,0,34],[6,60,0,40]]}]});
const moment = (animation,type) => penaltyMoment(animation,{type},buildRefereePaths(animation));
const poses = plan => plan.type==='holding'
    ? [{...plan.actor,x:40,z:22.3},{...plan.victim,x:42,z:22}]
    : [{...plan.actor,x:50,z:45},{...plan.victim,x:50,z:43}];

test('contact pairs match the reporting blocker and intended receiver, deterministically', () => {
    for (const direction of [-1,1]) {
        const animation=play(direction), before=JSON.stringify(animation);
        const holding=buildPenaltyContact(animation,moment(animation,'holding'));
        assert.deepEqual(holding.actor,{team:'offense',role:'LT'});
        assert.deepEqual(holding.victim,{team:'defense',role:'DE1'});
        const dpi=buildPenaltyContact(animation,moment(animation,'defensive_pass_interference'));
        assert.deepEqual(dpi.actor,{team:'defense',role:'CB2'});
        assert.deepEqual(dpi.victim,{team:'offense',role:'WR2'});
        assert.ok(dpi.at < animation.catch_at && dpi.end < animation.catch_at);
        assert.deepEqual(dpi,buildPenaltyContact(animation,moment(animation,'defensive_pass_interference')));
        assert.equal(JSON.stringify(animation),before);
    }
});

test('holding restricts a nearby rusher, while DPI contacts before arrival and preserves the receiver position', () => {
    for (const direction of [-1,1]) for (const type of ['holding','defensive_pass_interference']) {
        const animation=play(direction), plan=buildPenaltyContact(animation,moment(animation,type));
        const input=poses(plan), before=JSON.stringify(input);
        const sample=samplePenaltyContact(plan,plan.at,input);
        assert.ok(sample.strength>.99);
        const distance=Math.hypot(sample.actor.x-sample.victim.x,sample.actor.z-sample.victim.z);
        assert.ok(distance>=.9 && distance<1.2);
        assert.deepEqual(type==='holding'?sample.actor:sample.victim,type==='holding'?input[0]:input[1]);
        assert.equal(samplePenaltyContact(plan,plan.start,input),null);
        assert.equal(samplePenaltyContact(plan,plan.releaseEnd,input),null);
        if (type==='defensive_pass_interference') assert.equal(samplePenaltyContact(plan,animation.catch_at,input).strength,0);
        const start=samplePenaltyContact(plan,plan.start+.00001,input);
        assert.ok(Math.hypot(start.actor.x-input[0].x,start.actor.z-input[0].z)<.00001);
        assert.ok(Math.hypot(start.victim.x-input[1].x,start.victim.z-input[1].z)<.00001);
        assert.equal(JSON.stringify(input),before);
        const expected=JSON.stringify(sample);
        samplePenaltyContact(plan,plan.end,input); samplePenaltyContact(plan,plan.start+.1,input);
        assert.equal(JSON.stringify(samplePenaltyContact(plan,plan.at,input)),expected);
    }
});

test('contact corrections are bounded and distant defenders never grab across empty space', () => {
    const animation=play(1), plan=buildPenaltyContact(animation,moment(animation,'defensive_pass_interference'));
    const input=[{...plan.actor,x:10,z:10},{...plan.victim,x:50,z:43}];
    const sample=samplePenaltyContact(plan,plan.at,input);
    assert.ok(Math.hypot(sample.actor.x-input[0].x,sample.actor.z-input[0].z)<=4);
    assert.equal(sample.strength,0);
    assert.equal(samplePenaltyContact(plan,plan.at,input.slice(1)),null);
});

test('unsupported fouls, missing participants, no-snap and non-passing plays have no contact animation', () => {
    const animation=play(1), dpi=moment(animation,'defensive_pass_interference');
    assert.equal(buildPenaltyContact(animation,null),null);
    assert.equal(buildPenaltyContact(animation,moment(animation,'false_start')),null);
    for (const change of [{no_snap:true},{passing:false},{receiver_role:null},{catch_at:undefined},{players:[]}]) {
        assert.equal(buildPenaltyContact({...animation,...change},dpi),null);
    }
    assert.equal(samplePenaltyContact(null,1,[]),null);
});

test('mesh contact uses articulated grips, resets IK axes and gives identical poses after seeking', () => {
    const document={createElement:()=>({getContext:()=>({strokeText(){},fillText(){}})})};
    for (const type of ['holding','defensive_pass_interference']) {
        const animation=play(1), plan=buildPenaltyContact(animation,moment(animation,type));
        const input=poses(plan), meshes=input.map(()=>buildFootballPlayer({}, {}, document));
        const reset=()=>meshes.forEach((mesh,i)=>{
            resetPenaltyContactPose(mesh);
            mesh.position.set(input[i].x,0,input[i].z); mesh.rotation.set(0,i===0?Math.PI/2:-Math.PI/2,0);
            mesh.userData.waist.rotation.set(0,0,0);
            animateFootballPlayer(mesh,plan.at,i,true);
        });
        const snapshot=()=>meshes.map(mesh=>({position:mesh.position.toArray(),rotation:mesh.rotation.toArray(),
            waist:mesh.userData.waist.rotation.toArray(),
            arms:mesh.userData.arms.map(arm=>arm.quaternion.toArray()),
            elbows:mesh.userData.elbows.map(elbow=>elbow.quaternion.toArray())}));
        reset(); applyPenaltyContact(plan,plan.at,input,meshes);
        const first=snapshot();
        assert.ok(meshes[0].userData.penaltyContactPose);
        meshes.forEach(mesh=>mesh.traverse(part=>assert.ok(part.quaternion.toArray().every(Number.isFinite))));
        reset(); applyPenaltyContact(plan,plan.end+.1,input,meshes);
        reset(); applyPenaltyContact(plan,plan.at,input,meshes);
        assert.deepEqual(snapshot(),first);
        reset(); applyPenaltyContact(plan,plan.releaseEnd,input,meshes);
        assert.equal(meshes[0].userData.penaltyContactPose,undefined);
        assert.ok(meshes[0].userData.elbows.every(elbow=>elbow.rotation.y===0&&elbow.rotation.z===0));
        if (type==='defensive_pass_interference') assert.deepEqual(meshes[1].position.toArray(),[input[1].x,0,input[1].z]);
    }
});
