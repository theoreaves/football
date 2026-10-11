import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {buildReferees} from '../../resources/js/practice/referees.js';
import {resultRefereeSignal,resultSignalPose,resultRefereeSequence,resultSignalAt} from '../../resources/js/practice/result-referee.js';
const before={phase:'scrimmage',possession:'home',distance:6,down:2,home_score:0,away_score:0};
const animation={call:'inside_run',gain:8,outcome:'tackle',events:[]};
test('result inset signals committed first downs scores and kicks, never provisional or nullified results',()=>{
 const after={...before,down:1};
 assert.equal(resultRefereeSignal(animation,before,after),'first_down');
 assert.equal(resultRefereeSignal(animation,before,{...after,home_score:6}),'touchdown');
 assert.equal(resultRefereeSignal({...animation,outcome:'interception'},before,{...after,away_score:6}),'touchdown');
 for(const call of ['field_goal','extra_point']){
  const prefix=call,points=call==='field_goal'?3:1;
  assert.equal(resultRefereeSignal({...animation,call,outcome:`${prefix}_good`},before,{...after,home_score:points}),'good');
  for(const outcome of [`${prefix}_missed`,`${prefix}_blocked`])assert.equal(resultRefereeSignal({...animation,call,outcome},before,{...after,phase:'kickoff'}),'no_good');
  assert.equal(resultRefereeSignal({...animation,call,outcome:`${prefix}_good`},before,after),null);
 }
 assert.equal(resultRefereeSignal(animation,before,{...after,penalty_pending:true}),null);
 assert.equal(resultRefereeSignal({...animation,events:[[1,'Touchdown nullified']]},before,{...after,home_score:6}),null);
 assert.equal(resultRefereeSignal({...animation,no_snap:true},before,after),null);
 assert.equal(resultRefereeSignal({...animation,gain:2},before,after),null);
});
test('popup official faces the viewer with full raised arms and directional first-down signals',()=>{
 const refs=buildReferees(new THREE.Scene()),body=refs.group.children[0];
 const arms=body.children.filter(child=>child.isGroup).slice(2);
 for(const signal of ['good','touchdown']){
  resultSignalPose(refs,signal,1);body.updateMatrixWorld(true);
  assert.equal(body.rotation.y,0);
  for(const arm of arms)assert.ok(arm.children.at(-1).children.at(-1).getWorldPosition(new THREE.Vector3()).y>2);
 }
 for(const direction of [-1,1]){
  resultSignalPose(refs,'first_down',1,direction);
  assert.equal(arms[direction>0?1:0].rotation.z,direction*Math.PI/2);
 }
 resultSignalPose(refs,'no_good',1);const first=arms[0].rotation.z;
 resultSignalPose(refs,'no_good',1.5);assert.notEqual(first,arms[0].rotation.z);
 resultSignalPose(refs,'good',0);assert.ok(arms.every(arm=>arm.rotation.z===0));
});

test('pass signals show a catch followed by a first down or touchdown, without signaling sacks or interceptions',()=>{
 const pass={...animation,passing:true};const after={...before,down:3};
 assert.deepEqual(resultRefereeSequence(pass,before,after),['complete']);
 assert.deepEqual(resultRefereeSequence(pass,before,{...after,down:1}),['complete','first_down']);
 assert.deepEqual(resultRefereeSequence(pass,before,{...after,home_score:6}),['complete','touchdown']);
 assert.deepEqual(resultRefereeSequence({...pass,outcome:'incomplete'},before,after),['incomplete']);
 for(const outcome of ['sack','interception'])assert.deepEqual(resultRefereeSequence({...pass,outcome},before,{...after,possession:'away'}),[]);
 assert.deepEqual(resultRefereeSequence(pass,before,{...after,penalty_pending:true}),[]);
 assert.deepEqual(resultRefereeSequence({...pass,events:[[1,'Catch nullified']]},before,after),[]);
 assert.deepEqual(resultSignalAt(['complete','first_down'],1),{signal:'complete',time:1});
 assert.deepEqual(resultSignalAt(['complete','first_down'],3),{signal:'first_down',time:3-2.4});
});
test('pass completion brings raised hands down toward the chest and incompletion sweeps horizontally',()=>{
 const refs=buildReferees(new THREE.Scene());const arms=refs.group.children[0].children.filter(child=>child.isGroup).slice(2);
 resultSignalPose(refs,'complete',.65);const raised=arms[0].rotation.x;
 resultSignalPose(refs,'complete',1.5);assert.ok(arms[0].rotation.x>raised);assert.ok(arms[0].children.at(-1).rotation.x<0);
 resultSignalPose(refs,'incomplete',1);const sweep=arms[0].rotation.z;
 resultSignalPose(refs,'incomplete',1.5);assert.notEqual(arms[0].rotation.z,sweep);
});
