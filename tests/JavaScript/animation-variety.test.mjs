import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {applyAnimationVariety,tackleFallAngle} from '../../resources/js/practice/animation-variety.js';
function model(){const mesh=new THREE.Group();for(const key of ['legs','knees','arms','elbows'])mesh.userData[key]=[new THREE.Group(),new THREE.Group()];mesh.userData.waist=new THREE.Group();return mesh;}
function pose(mesh){return ['legs','knees','arms','elbows'].flatMap(key=>mesh.userData[key].flatMap(joint=>joint.rotation.toArray().slice(0,3))).concat(mesh.userData.waist.rotation.x);}

test('five handling and four finishing options produce twenty distinct pose sequences per family',()=>{
    for(const family of ['run','catch','incomplete','scramble']){
        const signatures=[];
        for(let handling=0;handling<5;handling++)for(let finish=0;finish<4;finish++){
            const mesh=model(),player={team:'offense',role:family==='scramble'?'QB':family==='run'?'RB':'WR1'};
            const animation={carrier:player.role,receiver_role:['run','scramble'].includes(family)?null:player.role,catch_at:3,contact_at:family==='incomplete'?null:5,duration:6,variety:{version:1,family,handling,finish}};
            applyAnimationVariety(mesh,animation,player,'play',family==='scramble'?3:family==='run'?2:3);
            const early=pose(mesh);
            applyAnimationVariety(mesh,animation,player,'play',5.7);
            signatures.push(JSON.stringify([early,pose(mesh)]));
            assert.deepEqual(mesh.position.toArray(),[0,0,0]);
        }
        assert.equal(new Set(signatures).size,20,family);
    }
    assert.equal(new Set(['wrap','side','lunge','drive'].map(tackleFallAngle)).size,4);
});

test('legacy replays other players and presnap poses remain untouched',()=>{
    for(const [animation,player,phase] of [[{}, {team:'offense',role:'RB'},'play'],[{variety:{version:1,handling:2,finish:1},carrier:'RB'}, {team:'defense',role:'LB2'},'play'],[{variety:{version:1,handling:2,finish:1},carrier:'RB'}, {team:'offense',role:'RB'},'set']]){
        const mesh=model(),before=pose(mesh);applyAnimationVariety(mesh,animation,player,phase,3);assert.deepEqual(pose(mesh),before);
    }
});

test('scrambles keep the pocket pose and never use a receiving reach',()=>{
    const animation={carrier:'QB',receiver_role:null,catch_at:3.8,contact_at:5.3,variety:{version:1,family:'scramble',handling:3,finish:2}};
    const player={team:'offense',role:'QB'};
    const mesh=model(),before=pose(mesh);
    applyAnimationVariety(mesh,animation,player,'play',1.55);
    assert.deepEqual(pose(mesh),before);
    applyAnimationVariety(mesh,animation,player,'play',3.8);
    assert.equal(mesh.userData.arms[1].rotation.x,0);
    assert.equal(mesh.userData.arms[0].rotation.x,-.67+.08);
    assert.equal(mesh.userData.elbows[0].rotation.x,-1.3+.09);
});
