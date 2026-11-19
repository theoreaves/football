import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {QB_CAMERA_LEVEL_TILT_DEGREES,quarterbackCameraActor,quarterbackCameraFrame,createQuarterbackCameraFollow} from '../../resources/js/arcade/qb-camera.js';
import {arcadeGroundPoint} from '../../resources/js/arcade/view.js';
import {createExhibitionCameraDirector} from '../../resources/js/practice/exhibition-camera-director.js';

test('perspective QB camera frames quarterback and downfield in both directions and aspect ratios',()=>{
 for(const direction of [-1,1]) for(const [w,h] of [[1280,720],[390,720],[844,390]]) for(const x of [0,5,60,115,120]) for(const z of [0,26,53.33]) {
  const camera=new THREE.PerspectiveCamera(58,w/h,.1,350);
  createQuarterbackCameraFollow(camera).update(quarterbackCameraFrame(w,h,{x,z},direction));
  const qb=new THREE.Vector3(x,1,z).project(camera), down=new THREE.Vector3(x+direction*25,0,z).project(camera);
  assert.ok(Math.abs(qb.x)<1 && Math.abs(qb.y)<1);
  assert.ok(down.y>qb.y);
  const rect={left:71,top:43,width:w,height:h};
  const point=arcadeGroundPoint(camera,rect,rect.left+(down.x+1)*w/2,rect.top+(1-down.y)*h/2);
  assert.ok(Math.abs(point.x-(x+direction*25))<1e-8 && Math.abs(point.z-z)<1e-8);
 }
});

test('camera follows live holder without transferring input and stays QB during flight',()=>{
 const qb={key:'offense_QB',x:60,z:26},rb={key:'offense_RB',x:63,z:23},wr={key:'offense_WR1',x:80,z:8};
 const state={actors:[qb,rb,wr],inputActorKey:'offense_QB',ball:{phase:'held',holderKey:qb.key}};
 assert.equal(quarterbackCameraActor(state),qb);
 state.ball.holderKey=rb.key;assert.equal(quarterbackCameraActor(state),rb);
 state.ball.phase='flight';state.ball.holderKey=null;assert.equal(quarterbackCameraActor(state),qb);
 state.ball.phase='held';state.ball.holderKey=wr.key;assert.equal(quarterbackCameraActor(state),wr);
 assert.equal(state.inputActorKey,'offense_QB');
});

test('carrier transfer and broadcast transition interpolate without replacing the camera owner',()=>{
 const camera=new THREE.PerspectiveCamera(),follow=createQuarterbackCameraFollow(camera);
 follow.update(quarterbackCameraFrame(1280,720,{x:50,z:26}));
 const before=camera.position.clone();const next=quarterbackCameraFrame(1280,720,{x:80,z:2});
 follow.update(next,1/60);assert.ok(camera.position.distanceTo(before)>0 && camera.position.distanceTo(before)<5);
 const broadcast=new THREE.PerspectiveCamera(42,1,.1,350);broadcast.position.set(60,48,92);broadcast.lookAt(60,0,26);
 const director=createExhibitionCameraDirector(broadcast);director.use(camera);
 assert.equal(director.transitioning,true);for(let i=0;i<8;i++)director.tick(.1);
 assert.equal(director.camera,camera);director.use(broadcast);for(let i=0;i<8;i++)director.tick(.1);
 assert.equal(director.camera,broadcast);
});


test('five-degree level tilt preserves camera position and field framing at both goals and sidelines',()=>{
 assert.equal(QB_CAMERA_LEVEL_TILT_DEGREES,5);
 for(const direction of [-1,1]) for(const x of [0,5,60,115,120]) for(const z of [0,26.665,53.33]) {
  const frame=quarterbackCameraFrame(1280,720,{x,z},direction);
  assert.deepEqual(frame.position,[THREE.MathUtils.clamp(x-direction*17,-17,137),18,THREE.MathUtils.clamp(z,4,49.33)]);
  assert.equal(frame.fov,58);
  const distance=Math.abs(frame.target[0]-frame.position[0]);
  const originalPitch=Math.atan2(18,distance);
  const pitch=Math.atan2(frame.position[1]-frame.target[1],distance);
  assert.ok(Math.abs(THREE.MathUtils.radToDeg(originalPitch-pitch)-5)<1e-10);
  assert.ok(pitch>0);
 }
});
