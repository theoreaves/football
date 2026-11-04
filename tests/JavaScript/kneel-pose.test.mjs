import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {applyKneelPose,kneelActive} from '../../resources/js/practice/kneel-pose.js';

function model(){const mesh=new THREE.Group();for(const key of ['legs','knees','arms','elbows'])mesh.userData[key]=[new THREE.Group(),new THREE.Group()];mesh.userData.waist=new THREE.Group();return mesh;}

test('QB blends onto one knee protects the ball and holds through the result',()=>{
    const animation={call:'kneel',kneel_at:1.1},player={team:'offense',role:'QB'};
    for(const direction of [-1,1]){
        const mesh=model();
        applyKneelPose(mesh,animation,player,'play',1.1,direction);assert.ok(Math.abs(mesh.position.y)<1e-10);
        applyKneelPose(mesh,animation,player,'play',1.4,direction);assert.ok(mesh.position.y<0 && mesh.position.y>-.55);
        applyKneelPose(mesh,animation,player,'play',2,direction);
        assert.equal(mesh.position.y,-.55);assert.equal(mesh.rotation.y,direction*Math.PI/2);
        assert.notEqual(mesh.userData.legs[0].rotation.x,mesh.userData.legs[1].rotation.x);
        assert.ok(mesh.userData.knees.every(knee=>knee.rotation.x>1));
        assert.ok(mesh.userData.arms.every(arm=>arm.rotation.x===-.9));
        applyKneelPose(mesh,animation,player,'result',6,direction);assert.equal(mesh.position.y,-.55);
    }
});

test('kneel pose leaves ordinary plays holders presnap and clock-expiry events alone',()=>{
    for(const [animation,phase] of [[{call:'inside_run'},'play'],[{call:'field_goal'},'play'],[{call:'spike'},'play'],[{call:'kneel',no_snap:true},'play'],[{call:'kneel'},'set'],[{call:'kneel'},'huddle']]){
        const mesh=model();mesh.position.y=.2;
        assert.equal(applyKneelPose(mesh,animation,{team:'offense',role:'QB'},phase,2,1),false);
        assert.equal(mesh.position.y,.2);assert.equal(kneelActive(animation,phase),false);
    }
    const mesh=model();assert.equal(applyKneelPose(mesh,{call:'kneel'},{team:'defense',role:'LB1'},'play',2,1),false);
    assert.ok(Math.abs(mesh.position.y)<1e-10);assert.equal(mesh.userData.knees[0].rotation.x,0);
});
