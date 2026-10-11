import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {unitExchange,playerKey,sidelineSlot} from '../../resources/js/practice/unit-exchange.js';
import {buildSidelinePlayers} from '../../resources/js/practice/sideline-players.js';
import {presentationAmbience} from '../../resources/js/practice/presentation-ambience.js';
const oldFrame={players:[{id:1,side:'home',team:'offense',role:'QB',x:48,z:26.7},
    {id:2,side:'away',team:'defense',role:'LB1',x:51,z:28}],ball:{x:50,y:1,z:26.7}};
const newFrame={players:[{id:3,side:'home',team:'defense',role:'LB1',x:51,z:28},
    {id:4,side:'away',team:'offense',role:'QB',x:45,z:26.7}],ball:{x:50,y:.25,z:26.7}};
const roster={home:[{id:1},{id:3}],away:[{id:2},{id:4}]};
test('possession exchanges preserve identity, move old units out and bring new units into correct huddles',()=>{
    const saved=JSON.stringify([oldFrame,newFrame,roster]);
    for(const direction of [1,-1]) {
        const exchange=unitExchange(oldFrame,newFrame,50,'away',direction,roster);
        const start=exchange.sample(0),during=exchange.sample(3),end=exchange.sample(exchange.duration);
        assert.equal(start.outgoing[0].x,48);assert.equal(start.incoming[0].visible,false);
        assert.ok(during.outgoing[0].z<26.7);assert.ok(during.incoming.some(p=>p.visible));
        assert.ok(end.outgoing.every(p=>!p.visible));assert.ok(end.incoming.every(p=>p.visible));assert.equal(end.complete,true);
        assert.equal(end.incoming[0].id,3);assert.equal(end.incoming[1].id,4);
        assert.equal(end.incoming[1].team,'offense');assert.equal(end.incoming[1].side,'away');
        assert.ok(end.incoming.every(p=>p.z>20&&p.z<33));
        assert.deepEqual(exchange.sample(3),during);
    }
    assert.equal(JSON.stringify([oldFrame,newFrame,roster]),saved);
});
test('players jog within the speed cap and accelerate from their current spot',()=>{
    const exchange=unitExchange(oldFrame,newFrame,50,'away',1,roster),step=.02;
    const first=exchange.sample(0).outgoing[0],early=exchange.sample(step).outgoing[0];
    assert.ok(Math.hypot(early.x-first.x,early.z-first.z)/step<1);
    for(let t=0;t<exchange.duration;t+=step) {
        const a=exchange.sample(t),b=exchange.sample(t+step);
        for(const group of ['outgoing','incoming']) a[group].forEach((player,i)=>{
            assert.ok(Math.hypot(b[group][i].x-player.x,b[group][i].z-player.z)/step<=6.001);
        });
    }
});
test('a reused special-teams player reaches the bench before their incoming model becomes visible',()=>{
    const incoming={...newFrame,players:[{...newFrame.players[0],id:1}]};
    const exchange=unitExchange(oldFrame,incoming,50,'home',1,roster);
    for(let t=0;t<=exchange.duration;t+=.02) {
        const frame=exchange.sample(t);
        const visible=[...frame.outgoing,...frame.incoming].filter(p=>p.visible).map(playerKey);
        assert.equal(new Set(visible).size,visible.length);
    }
});
test('final-game transitions clear the field without spawning an incoming unit',()=>{
    const exchange=unitExchange(oldFrame,null,50,'away',1,roster);
    const end=exchange.sample(exchange.duration);
    assert.equal(end.incoming.length,0);assert.ok(end.outgoing.every(p=>!p.visible));
});
test('sideline areas stay behind the officials lane and instanced benches hide active player IDs',()=>{
    const scene=new THREE.Scene();const sideline=buildSidelinePlayers(scene,roster,{home:{uniform:{shirt:'#ff0000'}},away:{uniform:{shirt:'#0000ff'}}});
    sideline.update(new Set(['home:1','away:4']));
    assert.ok(scene.getObjectByName('home-team-area'));assert.ok(scene.getObjectByName('away-team-area'));
    const meshes=scene.children.filter(m=>m.isInstancedMesh);
    assert.equal(meshes.length,26); // shared parts per team, independent of bench size
    const matrix=new THREE.Matrix4(),position=new THREE.Vector3(),rotation=new THREE.Quaternion(),scale=new THREE.Vector3();
    const home=meshes.find(m=>m.name==='home-sideline-players');
    home.getMatrixAt(0,matrix);assert.equal(matrix.elements[0],0);assert.equal(matrix.elements[5],0);assert.equal(matrix.elements[10],0);
    home.getMatrixAt(1,matrix);matrix.decompose(position,rotation,scale);assert.ok(scale.x>0);assert.ok(position.z<-5);
    sideline.update(new Set());home.getMatrixAt(0,matrix);matrix.decompose(position,rotation,scale);assert.ok(scale.x>0);
    assert.ok(sidelineSlot('away',40).z>58);assert.ok(sidelineSlot('home',40).z<-5);
});
test('flag notices retain crowd ambience, including the final play, but hidden tabs and inspection screens stay silent',()=>{
    const flag={visible:true,callingPlay:false,flagOpen:true,final:false,recapOpen:false,inspectionOpen:false};
    assert.equal(presentationAmbience(flag),true);assert.equal(presentationAmbience({...flag,final:true}),true);
    for(const state of [{visible:false},{inspectionOpen:true},{recapOpen:true},{flagOpen:false}]) assert.equal(presentationAmbience({...flag,...state}),false);
    assert.equal(presentationAmbience({...flag,flagOpen:false,callingPlay:true}),true);
    assert.equal(presentationAmbience({...flag,flagOpen:false,callingPlay:true,final:true}),false);
});
