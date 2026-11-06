import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {applyAnimationVariety,tackleFallAngle} from '../../resources/js/practice/animation-variety.js';
function model(){const mesh=new THREE.Group();for(const key of ['legs','knees','arms','elbows'])mesh.userData[key]=[new THREE.Group(),new THREE.Group()];mesh.userData.waist=new THREE.Group();return mesh;}
function pose(mesh){return ['legs','knees','arms','elbows'].flatMap(key=>mesh.userData[key].flatMap(joint=>joint.rotation.toArray().slice(0,3))).concat(mesh.userData.waist.rotation.x);}

test('five handling and four finishing options produce twenty distinct pose sequences per family',()=>{
    for(const family of ['run','catch','incomplete']){
        const signatures=[];
        for(let handling=0;handling<5;handling++)for(let finish=0;finish<4;finish++){
            const mesh=model(),player={team:'offense',role:family==='run'?'RB':'WR1'};
            const animation={carrier:player.role,receiver_role:family==='run'?null:player.role,catch_at:3,contact_at:family==='incomplete'?null:5,duration:6,variety:{version:1,family,handling,finish}};
            applyAnimationVariety(mesh,animation,player,'play',family==='run'?2:3);
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
