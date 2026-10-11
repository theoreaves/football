import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {mascotPose,mascotReaction,mascotBigPlayCue,buildSidelineMascot} from '../../resources/js/practice/sideline-mascot.js';

test('roaming stays behind the bench and moves continuously through walk and wave transitions',()=>{
    let previous=mascotPose(0);
    for(let t=.1;t<300;t+=.1){
        const pose=mascotPose(t);
        assert.ok(pose.x>=35 && pose.x<=85);
        assert.equal(pose.z,-16);
        assert.ok(Math.abs(pose.x-previous.x)<.14);
        previous=pose;
    }
    assert.equal(mascotPose(2).wave,true);
    assert.equal(mascotPose(20).moving,true);
    assert.equal(mascotPose(50).wave,true);
    assert.equal(mascotPose(70).moving,true);
    assert.deepEqual(mascotPose(NaN),mascotPose(0));
});

test('only home touchdowns takeaways and valid big plays trigger a cheer',()=>{
    assert.equal(mascotReaction({type:'touchdown'}),9);
    assert.equal(mascotReaction({type:'home_turnover'}),7);
    for(const type of ['away_score','crowd','snap','first_down','third_down'])assert.equal(mascotReaction({type}),0);
    const animation={outcome:'run',gain:25,duration:7,reveal_at:6};
    assert.equal(mascotBigPlayCue(animation,{possession:'home'},{},null).time,7.2);
    assert.equal(mascotBigPlayCue(animation,{possession:'away'},{}),null);
    assert.equal(mascotBigPlayCue({...animation,gain:undefined},{possession:'home'},{}),null);
    assert.equal(mascotBigPlayCue(animation,{possession:'home'},{penalty_pending:true}),null);
    assert.equal(mascotBigPlayCue(animation,{possession:'home'},{},{accepted:true}),null);
    assert.ok(mascotBigPlayCue(animation,{possession:'home'},{},{accepted:false}));
    for(const outcome of ['interception','fumble','touchdown','sack','incomplete','penalty'])assert.equal(mascotBigPlayCue({...animation,outcome},{possession:'home'},{}),null);
});

test('one configured home mascot animates, pauses its route for cheers and resets without teleporting',()=>{
    const scene=new THREE.Scene();
    const absent=buildSidelineMascot(scene,{home:{mascot:[]}});
    assert.equal(absent.model,null);absent.update(.1);absent.cue({type:'touchdown'});absent.reset();
    const mascot=buildSidelineMascot(scene,{home:{mascot:{character:'dog'}},away:{mascot:{character:'wolf'}}});
    assert.equal(scene.children.length,1);assert.equal(mascot.model.userData.character,'dog');
    for(let i=0;i<150;i++)mascot.update(.1);
    const x=mascot.model.position.x;
    mascot.cue({type:'home_turnover'});
    for(let i=0;i<30;i++)mascot.update(.1);
    assert.equal(mascot.model.position.x,x);
    assert.ok(mascot.model.getObjectByName('mascot-arm-1').rotation.x < -2);
    mascot.reset();assert.equal(mascot.model.position.x,x);
    mascot.update(.1);assert.ok(mascot.model.position.x>x);
    mascot.update(NaN);assert.ok(Number.isFinite(mascot.model.position.x));
});
