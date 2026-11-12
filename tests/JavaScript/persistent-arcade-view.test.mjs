import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {readFileSync} from 'node:fs';
import {createArcadeView} from '../../resources/js/arcade/view.js';
import {createReplayView, captureSharedPresentation} from '../../resources/js/arcade/replay-view.js';
import {createDrill} from '../../resources/js/arcade/simulation.js';
import {buildFootballPlayer} from '../../resources/js/practice/player-model.js';

function fixture(){
 const document={createElement:()=>({getContext:()=>({clearRect(){},fillRect(){},strokeText(){},fillText(){},measureText:()=>({width:100})})})};
 const host={ownerDocument:document,clientWidth:1280,clientHeight:720,appendChild(){assert.fail('borrowed view must not append a canvas');}};
 const scene=new THREE.Scene(),models=new Map(),listeners=new Set(),ball=new THREE.Mesh(new THREE.SphereGeometry(.25),new THREE.MeshBasicMaterial());scene.add(ball);
 const renderer={domElement:{getBoundingClientRect:()=>({left:0,top:0,width:1280,height:720})},render(){assert.fail('host alone renders');},dispose(){assert.fail('adapter cannot dispose renderer');}};
 const cameraDirector={camera:new THREE.PerspectiveCamera(),use(camera){this.camera=camera;}};
 const sharedHost={host,scene,renderer,ball,cameraDirector,cameras:{arcade:new THREE.OrthographicCamera()},textureFor:()=>null,
  player(actor,kit){const key=actor.side+':'+actor.id;if(!models.has(key)){const model=buildFootballPlayer(actor,kit,document,()=>null);models.set(key,model);scene.add(model);}return models.get(key);},
  hidePlayers(){models.forEach(model=>{model.visible=false;});},createLayer(name){const layer=new THREE.Group();layer.name=name;scene.add(layer);return layer;},releaseLayer(layer){layer.removeFromParent();layer.clear();},
  subscribeResize(fn){listeners.add(fn);return ()=>listeners.delete(fn);}};
 return {host,sharedHost,models,listeners,scene,ball};
}
const setup={runner:{id:1,side:'home',number:22,ratings:{}},defenders:[{id:2,side:'away',number:31,ratings:{}}],appearance:{},exhibition:{startX:35,firstDownX:45}};
test('borrowed Arcade survives repeated plays with one registry, ball and no renderer ownership',()=>{
 const f=fixture(),state=createDrill(setup);let original;
 for(let n=0;n<4;n++){const view=createArcadeView(f.host,setup,{sharedHost:f.sharedHost,rendererFactory(){assert.fail('second renderer');}});view.render(state);const runner=f.models.get('home:1');if(original)assert.equal(runner,original);original=runner;assert.equal(f.models.size,2);view.dispose();assert.equal(f.listeners.size,0);assert.equal(original.visible,true,'last coherent pose remains until replacement ready');assert.equal(f.scene.children.filter(x=>x.name==='arcade-live').length,0);}
});
test('borrowed replay restores original model transforms, ball parenting and camera without game writes',()=>{
 const f=fixture(),state=createDrill(setup),live=createArcadeView(f.host,setup,{sharedHost:f.sharedHost});live.render(state);f.scene.updateMatrixWorld(true);
 const runner=f.models.get('home:1'),position=runner.position.clone(),ballParent=f.ball.parent,camera=f.sharedHost.cameraDirector.camera;
 const recording={direction:1,mode:'running',field:{startX:35,goalX:110},appearance:{},actors:[{...setup.runner,key:'runner'},{...setup.defenders[0],key:'defender:0'}]};
 const replay=createReplayView(f.host,recording,{sharedHost:f.sharedHost,rendererFactory(){assert.fail('second renderer');}});
 replay.render({elapsed:2,actors:[{key:'runner',x:82,z:18,facing:0,pose:'run',speed:6,gaitPhase:2}],ball:{phase:'held',holderKey:'runner'}});
 assert.equal(f.models.get('home:1'),runner);assert.equal(runner.position.x,82);replay.dispose();assert.deepEqual(runner.position,position);assert.equal(f.ball.parent,ballParent);assert.equal(f.sharedHost.cameraDirector.camera,camera);assert.equal(f.models.size,2);live.dispose();
});
const readerSource=readFileSync(new URL('../../resources/js/arcade/persistent-exhibition.js',import.meta.url),'utf8');
const readerFactory=new Function(readerSource.slice(readerSource.indexOf('export function createPresentationReader'),readerSource.indexOf('export async function mountPersistentExhibition')).replace('export function','function')+';return createPresentationReader;')();
test('late read/playback callbacks cannot replace a newer game presentation or run after disposal',async()=>{
 const queue=[],applied=[];let release;
 const reader=readerFactory({request:()=>new Promise(resolve=>queue.push(resolve)),apply:async(data,context)=>{if(data===1)await new Promise(resolve=>{release=resolve;});if(context.isCurrent())applied.push(data);}});
 const first=reader.refresh('one');queue.shift()(1);await Promise.resolve();await Promise.resolve();const second=reader.refresh('two');queue.shift()(2);await second;release();assert.equal(await first,false);assert.deepEqual(applied,[2]);
 const third=reader.refresh('three');reader.dispose();queue.shift()(3);assert.equal(await third,false);assert.deepEqual(applied,[2]);
});

test('presentation capture restores instanced bench matrices and hides only per-play overlays',()=>{
 const f=fixture(),bench=new THREE.InstancedMesh(new THREE.BoxGeometry(),new THREE.MeshBasicMaterial(),1),layer=new THREE.Group();layer.name='coach-play';f.scene.add(bench,layer);bench.setMatrixAt(0,new THREE.Matrix4().makeTranslation(10,0,-6));const original=bench.instanceMatrix.array.slice();
 const restore=captureSharedPresentation(f.sharedHost);assert.equal(layer.visible,false);bench.setMatrixAt(0,new THREE.Matrix4().makeScale(0,0,0));restore();assert.deepEqual(bench.instanceMatrix.array,original);assert.equal(layer.visible,true);
});

test('BFCache restoration mounts only the persistent entry after the old host is disposed',()=>{
 assert.match(readerSource,/window\.addEventListener\('pageshow',event=>\{if\(event.persisted\)mountPersistentExhibition/);
 assert.match(readerSource,/delete root\.dataset\.persistentMounted/);
});

test('camera capture restores mutable broadcast pose, projection and zoom after historical reuse',()=>{
 const f=fixture(),camera=f.sharedHost.cameraDirector.camera;camera.position.set(12,40,80);camera.zoom=1.4;camera.updateProjectionMatrix();const position=camera.position.clone(),projection=camera.projectionMatrix.clone();const restore=captureSharedPresentation(f.sharedHost);camera.position.set(99,2,4);camera.zoom=.7;camera.updateProjectionMatrix();restore();assert.deepEqual(camera.position,position);assert.equal(camera.zoom,1.4);assert.deepEqual(camera.projectionMatrix,projection);
});
const historySource=readerSource.slice(readerSource.indexOf('    async function openHistory(number)'),readerSource.indexOf('    const dispose='));
function historyHarness(){let complete,reads=0,opened=0;const arcade={hasLive:false,pending:false,busy:false,replaying:false,openReplay(){opened++;}};const api=new Function('arcade','sharedHost','snapshot','read','say',`let readBlocked=false,coachHistory=false,loading=false,historyGeneration=0,activityGeneration=0,disposed=false;${historySource};return {open:openHistory,invalidate(){historyGeneration++;activityGeneration++;},get loading(){return loading;}};`)(arcade,{staging:false,cameraDirector:{transitioning:false}},{urls:{playback_base:'/exhibitions/3/arcade/playback'}},()=>{reads++;return new Promise(resolve=>{complete=resolve;});},()=>{});return {api,arcade,resolve:()=>complete({recording:{}}),get reads(){return reads;},get opened(){return opened;}};}
test('slow history read locks competing replay and rejects late response after refresh or reservation',async()=>{
 for(const invalidate of [h=>h.api.invalidate(),h=>{h.arcade.hasLive=true;},h=>{h.arcade.pending=true;}]){const h=historyHarness();const loading=h.api.open('1');assert.equal(h.api.loading,true);await h.api.open('2');assert.equal(h.reads,1);invalidate(h);h.resolve();await loading;assert.equal(h.opened,0);}
 const h=historyHarness();h.arcade.replaying=true;await h.api.open('1');assert.equal(h.reads,0);h.arcade.replaying=false;const load=h.api.open('1');h.resolve();await load;assert.equal(h.opened,1);assert.equal(h.api.loading,false);
});

test('every replaced live and historical controls fragment initializes its own settings controls',()=>{
 assert.match(readerSource,/mountGameSettings\(phaseRoot\)/);assert.match(readerSource,/mountGameSettings\(historical\)/);
});
