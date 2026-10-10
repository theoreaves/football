import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {buildRefereePaths, buildReferees, refereeFormation} from '../../resources/js/practice/referees.js';
import {penaltyMoment, flagPosition, foulMovement, penaltyText, penaltyAlignment, illegalFormationRoles} from '../../resources/js/practice/penalty-presentation.js';
const animation = {line: 40, direction: 1, duration: 6, catch_at: 3.4, result_at: 5.3, contact_at: 5.1,
    receiver_role: 'WR1', tackler_role: 'LB2',
    players: [{team:'offense',role:'LG',path:[[0,40,0,23],[6,42,0,23]]},
        {team:'offense',role:'LT',path:[[0,40,0,20],[6,42,0,20]]},
        {team:'offense',role:'WR1',path:[[0,40,0,10],[6,60,0,10]]},
        {team:'defense',role:'DT1',path:[[0,41,0,23],[6,43,0,23]]},
        {team:'defense',role:'LB2',path:[[0,45,0,26],[6,60,0,26]]}],
    ball:[[0,40,1,26],[6,60,1,26]],events:[]};
test('all supported fouls have deterministic flag timing and airborne/landed cloth', () => {
    const before = JSON.stringify(animation), paths = buildRefereePaths(animation);
    const timings = {false_start:.48, encroachment:.48, holding:1.25, defensive_pass_interference:3.2, face_mask:5.1};
    for (const [type, at] of Object.entries(timings)) {
        const moment = penaltyMoment(animation,{type},paths);
        assert.ok(Math.abs(moment.at-at) < .000001);
        assert.equal(flagPosition(moment,at-.01),null);
        assert.equal(flagPosition(moment,at).x,moment.origin.x);
        assert.ok(flagPosition(moment,at+.4).y > moment.origin.y);
        assert.equal(flagPosition(moment,at+1).landed,true);
        assert.equal(flagPosition(moment,at+1).y,.08);
        assert.deepEqual(moment,penaltyMoment(animation,{type},paths));
    }
    assert.equal(JSON.stringify(animation),before);
    assert.equal(penaltyMoment(animation,null,paths),null);
    assert.equal(penaltyMoment(animation,{type:'unsupported'},paths),null);
});
test('pre-snap offenders move before the flag, in both field directions', () => {
    for (const direction of [-1,1]) {
        for (const type of ['false_start','encroachment']) {
            const moment = penaltyMoment(animation,{type},buildRefereePaths(animation));
            assert.deepEqual(foulMovement(moment,0,direction),{x:0,y:0,lean:0});
            const movement = foulMovement(moment,.32,direction);
            assert.ok(movement.y > 0);
            assert.equal(Math.sign(movement.x),type === 'false_start' ? direction : -direction);
            const finish = foulMovement(moment,1,direction);
            assert.equal(finish.x,type === 'false_start' ? direction*.48 : -direction*2.1);
        }
    }
});
test('announcement distinguishes pending, accepted and declined enforcement', () => {
    const penalty = {type:'holding',team:'home',yards:5,accepted:true};
    const names = {home:'Warriors'};
    assert.match(penaltyText(penalty,names,true),/Awaiting accept \/ decline/);
    assert.doesNotMatch(penaltyText(penalty,names,true),/5 yards/);
    assert.match(penaltyText(penalty,names),/Warriors.*5 yards.*Repeat down/);
    assert.match(penaltyText({...penalty,accepted:false},names),/Declined.*Play stands/);
});
test('referee gestures animate and reset without inheriting the prior penalty', () => {
    const refs = buildReferees(new THREE.Scene()), poses = refereeFormation(40,1);
    const arms = refs.group.children[0].children.filter(c => c.isGroup).slice(2);
    const snapshots = [];
    for (const type of ['false_start','encroachment','holding','defensive_pass_interference','face_mask']) {
        refs.update({poses,penalty:type,penaltyTime:1,active:true});
        snapshots.push(arms.map(arm => [...arm.rotation.toArray(),...arm.children.at(-1).rotation.toArray()]));
        assert.ok(arms.some(arm => Math.abs(arm.rotation.x)+Math.abs(arm.rotation.z)>0));
    }
    assert.equal(new Set(snapshots.map(s=>JSON.stringify(s))).size,5);
    refs.update({poses,active:false});
    assert.ok(arms.every(arm=>arm.rotation.x===0&&arm.rotation.z===0));
    assert.ok(arms.every(arm=>arm.children.at(-1).rotation.x===0&&arm.children.at(-1).rotation.z===0));
});

test('new live fouls throw at the snap and use the existing signal families', () => {
    const paths = buildRefereePaths(animation);
    for (const type of ['defensive_offside','illegal_formation']) {
        const moment = penaltyMoment(animation,{type},paths);
        assert.equal(moment.at,0); assert.equal(moment.dead,false);
        assert.ok(flagPosition(moment,0));
        assert.match(penaltyText({type,team:'home',yards:5,accepted:true},{home:'Warriors'}),/5 yards/);
    }
});

test('illegal formation has six players on the line and offside crosses at the snap', () => {
    for (const direction of [-1,1]) {
        const roles = ['C','LG','RG','LT','RT','TE','WR1','WR2'];
        const players = roles.map((role,i)=>({team:'offense',role,path:[[0,40-direction,0,5+i*5],[6,45,0,5+i*5]]}));
        players.push({team:'defense',role:'DT1',path:[[0,40+direction,0,25],[6,45,0,25]]});
        const play = {...animation,direction,players};
        const paths = buildRefereePaths(play), formation = penaltyMoment(play,{type:'illegal_formation'},paths);
        assert.equal(illegalFormationRoles(play).length,2);
        const onLine = players.filter(p=>p.team==='offense' && penaltyAlignment(formation,p,0,direction)===0);
        assert.equal(onLine.length,6);
        const offside = penaltyMoment(play,{type:'defensive_offside'},paths), defender = players.at(-1);
        const x = defender.path[0][1]+penaltyAlignment(offside,defender,0,direction);
        assert.ok((x-40)*direction < 0);
        assert.equal(penaltyAlignment(offside,defender,.8,direction),0);
    }
});
