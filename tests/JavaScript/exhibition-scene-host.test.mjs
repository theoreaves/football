import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {createExhibitionSceneHost,createPlayerRegistry} from '../../resources/js/practice/exhibition-scene-host.js';
import {createExhibitionCameraDirector} from '../../resources/js/practice/exhibition-camera-director.js';
import {createExhibitionUnitStaging} from '../../resources/js/practice/exhibition-unit-staging.js';
import {stadiumAudio} from '../../resources/js/practice/stadium-audio.js';

function fixture(){
    let next=0;const frames=new Map(),lifecycle={renderers:0,disposed:0,audio:0,audioDisposed:0,observers:0};
    const context=new Proxy({measureText:()=>({width:100})},{get:(target,key)=>target[key] ?? (()=>{})});
    const win={devicePixelRatio:1,performance:{now:()=>0}};
    const document={defaultView:win,hidden:false,createElement:()=>({getContext:()=>context})};
    const host={ownerDocument:document,clientWidth:1280,clientHeight:720,dataset:{},append(canvas){this.canvas=canvas;}};
    const renderer={domElement:{style:{},setAttribute(){},remove(){}},shadowMap:{},info:{memory:{},render:{}},setSize(){},setPixelRatio(){},setClearColor(){},render(scene,camera){this.scene=scene;this.camera=camera;scene.updateMatrixWorld(true);},dispose(){lifecycle.disposed++;}};
    const owner=createExhibitionSceneHost(host,{appearance:{home:{stadium_style:'indoor_dome',stadium_wall_color:'#123456'}},audioRoot:host},{
        rendererFactory:()=>{lifecycle.renderers++;return renderer;},ResizeObserver:class{constructor(){lifecycle.observers++;}observe(){}disconnect(){lifecycle.observers--; }},
        requestAnimationFrame:fn=>{frames.set(++next,fn);return next;},cancelAnimationFrame:id=>frames.delete(id),audioFactory:()=>{lifecycle.audio++;return {dispose(){lifecycle.audioDisposed++;},setPlayback(){}};},
    });
    let time=0;const step=()=>{assert.equal(frames.size,1);const [id,fn]=frames.entries().next().value;frames.delete(id);time+=100;fn(time);};
    return {owner,lifecycle,renderer,step,frames};
}
test('one configured renderer/stadium/ball/registry survives repeated adapter and overlay changes',()=>{
    const f=fixture(),host=f.owner,ids=host.metrics();
    assert.ok(host.stadium.group.getObjectByName('dome-roof'));
    const player={side:'home',id:12,role:'QB'},model=host.player(player,{}),ball=host.ball;
    let ticked=0;
    for(let i=0;i<8;i++){
        const layer=host.createLayer('play');layer.add(new THREE.Mesh(new THREE.BoxGeometry(),new THREE.MeshBasicMaterial()));
        host.activate({tick(){ticked++;model.visible=true;model.position.x=i;}});f.step();
        assert.equal(host.player({...player,role:'RB'},{}),model,'role reassignment keeps saved identity');
        assert.equal(host.ball,ball);assert.equal(host.scene.uuid,ids.sceneId);assert.equal(host.stadium.group.uuid,ids.stadiumId);
        host.releaseLayer(layer);assert.equal(host.metrics().layers,0);assert.equal(model.parent,host.scene);
    }
    assert.equal(ticked,8);assert.equal(f.lifecycle.renderers,1);assert.equal(f.lifecycle.audio,1);assert.equal(f.frames.size,1);
    assert.equal(host.metrics().actors,1);assert.equal(host.metrics().rafCount,1);
    host.dispose();host.dispose();assert.equal(f.lifecycle.disposed,1);assert.equal(f.lifecycle.audioDisposed,1);assert.equal(f.lifecycle.observers,0);assert.equal(f.frames.size,0);
});
test('actor registry distinguishes teams and never constructs another mesh for role aliases',()=>{
    const scene=new THREE.Scene();let builds=0;const registry=createPlayerRegistry(scene,()=>{builds++;return new THREE.Group();});
    const first=registry.get({side:'home',id:1,role:'QB'},{});assert.equal(registry.get({side:'home',id:1,role:'RB'},{}),first);
    assert.notEqual(registry.get({side:'away',id:1,role:'QB'},{}),first);assert.equal(builds,2);assert.equal(scene.children.length,2);
    assert.throws(()=>registry.get({id:1},{}),/identity/);
});
test('camera transitions preserve exact orthographic and broadcast endpoints without another scene',()=>{
    const broadcast=new THREE.PerspectiveCamera(42,16/9,.1,350),arcade=new THREE.OrthographicCamera(-24,24,19,-19,.1,300);
    broadcast.position.set(60,48,91);broadcast.lookAt(60,0,26);arcade.position.set(90,65,116);arcade.lookAt(90,0,26);
    const originalProjection=arcade.projectionMatrix.clone(),director=createExhibitionCameraDirector(broadcast);
    director.use(arcade);assert.equal(director.transitioning,true);const start=director.camera.position.clone();director.tick(.1);assert.ok(director.camera.position.distanceTo(start)>0);
    for(let i=0;i<10;i++){director.tick(.1);assert.ok(director.camera.projectionMatrixInverse.elements.every(Number.isFinite));}
    assert.equal(director.camera,arcade);assert.ok(arcade.projectionMatrix.equals(originalProjection));
    director.use(broadcast);for(let i=0;i<10;i++)director.tick(.1);assert.equal(director.camera,broadcast);
    broadcast.position.x+=20;director.use(broadcast);assert.equal(director.transitioning,true,'same endpoint object still pans from last drawn pose');
});
test('unit staging retains identity, bounds speed, and benches outgoing actors without physics writes',()=>{
    const before=[{key:'home:1',x:40,z:20},{key:'away:2',x:45,z:30}],after=[{key:'home:1',x:50,z:26},{key:'home:3',x:48,z:24}];
    const untouched=JSON.stringify({before,after}),stage=createExhibitionUnitStaging(before,after);
    let previous=new Map(stage.sample(0).map(p=>[p.key,p]));
    for(let t=.02;t<stage.duration+.02;t+=.02){for(const pose of stage.sample(t)){const last=previous.get(pose.key);assert.ok(Math.hypot(pose.x-last.x,pose.z-last.z)/.02<=7.500001);previous.set(pose.key,pose);}}
    assert.equal(stage.sample(stage.duration).find(p=>p.key==='away:2').visible,false);
    assert.equal(stage.sample(stage.duration).filter(p=>p.visible).length,2);assert.equal(JSON.stringify({before,after}),untouched);
});
test('host holds the adapter clock while physical units stage and resets ball ownership',()=>{
    const f=fixture(),host=f.owner,model=host.player({side:'home',id:1,role:'RB'},{});let ticks=0;
    host.activate({tick(){ticks++;model.visible=true;model.position.set(40,0,20);}});f.step();model.add(host.ball);
    host.activate({tick(){ticks++;model.visible=true;model.position.set(50,0,20);}});assert.equal(host.ball.parent,host.scene);
    host.stageNextFrame();f.step();assert.equal(host.staging,true);const initialTicks=ticks;
    while(host.staging)f.step();assert.equal(ticks,initialTicks,'no live or recorded step during unit staging');f.step();assert.equal(ticks,initialTicks+1);assert.equal(model.position.x,50);host.dispose();
});
function control(){return {dataset:{soundVolume:'stadium'},listeners:new Map(),setAttribute(){},addEventListener(name,fn){if(!this.listeners.has(name))this.listeners.set(name,new Set());this.listeners.get(name).add(fn);},removeEventListener(name,fn){this.listeners.get(name)?.delete(fn);}};}
test('single audio owner rebinds replacement controls without duplicate handlers or contexts',()=>{
    const toggle=control(),volume=control(),makeRoot=()=>({dataset:{},querySelector:selector=>selector==='[data-sound-toggle]'?toggle:null,querySelectorAll:()=>[volume],addEventListener(){},removeEventListener(){}});
    const root=makeRoot(),environment={localStorage:{getItem:()=>null,setItem(){}}};
    const audio=stadiumAudio(root,environment);assert.equal(toggle.listeners.get('click').size,1);
    audio.bindControls(root);assert.equal(toggle.listeners.get('click').size,1);assert.equal(volume.listeners.get('input').size,1);
    const unbind=audio.bindControls(root);unbind();assert.equal(toggle.listeners.get('click').size,0);audio.bindControls(root);audio.dispose();assert.equal(toggle.listeners.get('click').size,0);assert.equal(volume.listeners.get('input').size,0);
});

test('overlay disposal cannot dispose or remove the shared physical player and football',()=>{
    const f=fixture(),host=f.owner,layer=host.createLayer('temporary');const player=host.player({side:'home',id:8,role:'QB'},{});
    let destroyed=0;host.ball.geometry.addEventListener('dispose',()=>destroyed++);layer.add(player);player.add(host.ball);
    host.releaseLayer(layer);assert.equal(player.parent,host.scene);assert.equal(host.ball.parent,player);assert.equal(destroyed,0);host.dispose();assert.equal(destroyed,1);
});

test('final handshake roster QBs retain their original team registry identities',async()=>{
    const {readFile}=await import('node:fs/promises');
    const source=await readFile(new URL('../../resources/js/practice/field.js',import.meta.url),'utf8');
    assert.match(source,/playerModel\(shared \? \{\.\.\.person,side\} : person,/);
    const scene=new THREE.Scene();let builds=0;
    const registry=createPlayerRegistry(scene,()=>{builds++;return new THREE.Group();});
    const person={id:17,position:'QB',depth:'QB1'};
    const home=registry.get({...person,side:'home',role:'QB'},{}),away=registry.get({...person,side:'away',role:'QB'},{});
    for(const side of ['home','away'])assert.equal(registry.get({...person,side},{}),side==='home'?home:away);
    assert.equal(builds,2);assert.equal(scene.children.length,2);
});

test('host idle atmosphere advances only when explicitly requested and only once per frame',()=>{
    const f=fixture(),host=f.owner,calls=[];
    for(const [name,object,method] of [['crowd',host.stadium,'updateCrowd'],['coaches',host.sidelineCoaches,'update'],['mascot',host.sidelineMascot,'update'],['cheer',host.sidelineCheerleaders,'update'],['entourage',host.championshipAwayEntourage,'update']])object[method]=delta=>calls.push([name,delta]);
    let audio=0;host.audio.setPlayback=(play,visible)=>{assert.equal(play,false);assert.equal(visible,true);audio++;};
    host.activate({tick(){}});f.step();assert.equal(calls.length,0);
    host.activate({tick(now,delta){host.tickIdleAtmosphere(delta);host.tickIdleAtmosphere(delta);}});f.step();f.step();
    assert.equal(calls.length,10);assert.equal(audio,2);assert.ok(calls.every(([,delta])=>delta>=0 && delta<=.1));host.dispose();
});
