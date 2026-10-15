import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {turnoverCelebrants} from '../../resources/js/practice/turnover-group.js';
import {unitExchange,playerKey,sidelineSlot} from '../../resources/js/practice/unit-exchange.js';
import {sidelineNumbers} from '../../resources/js/practice/sideline-numbers.js';
import {buildSidelinePlayers} from '../../resources/js/practice/sideline-players.js';
const defenders=Array.from({length:11},(_,i)=>({id:i+1,side:'away',team:'defense',role:i===0?'CB1':`LB${i}`,x:50+i*2,z:25}));
const frame={players:[{id:99,side:'home',team:'offense',role:'QB',x:40,z:26},...defenders],ball:{x:50,y:1,z:25}};
test('the recovering player and four nearest defenders stay while all other players leave',()=>{
    for(const outcome of ['interception','fumble']) {
        const group=turnoverCelebrants({outcome,ballHolders:[[0,'offense','QB'],[5,'defense','CB1']]},frame);
        assert.equal(group.size,5);assert.ok(group.has('away:1'));assert.equal(group.has('home:99'),false);assert.equal(group.has('away:11'),false);
        const early=unitExchange({...frame,players:frame.players.filter(p=>!group.has(playerKey(p)))},null,50,'away',1,{});
        assert.equal(early.sample(1).outgoing.length,7);assert.ok(early.sample(early.duration).outgoing.every(p=>!p.visible));
    }
    assert.equal(turnoverCelebrants({outcome:'touchdown'},frame).size,0);
    assert.equal(turnoverCelebrants({outcome:'interception',no_snap:true},frame).size,0);
    assert.ok(turnoverCelebrants({outcome:'fumble',ballHolders:[[5,'defense','LB8']]},frame).has('away:9'));
});
test('a player already on the bench does not respawn on the field during the later exchange',()=>{
    const slot=sidelineSlot('home',0);
    const old={players:[{id:1,side:'home',role:'QB',team:'offense',...slot}],ball:frame.ball};
    const exchange=unitExchange(old,null,50,'away',1,{home:[{id:1}]});
    assert.equal(exchange.sample(0).outgoing[0].visible,false);
    assert.equal(exchange.duration,0);
});
function documentFixture() {
    const calls=[];
    const document={createElement(){
        return {width:0,height:0,getContext(){
            return {strokeText(){},fillText(...args){calls.push(args);}};
        }};
    }};
    return {calls,document};
}
test('number atlas selects roster numbers and draws chest back and both shoulders with team number colors',()=>{
    const f=documentFixture(),people=[{id:1,number:12},{id:2,number:87}];
    const {geometry,material}=sidelineNumbers(people,{number:'#ffffff'},f.document);
    assert.deepEqual([...geometry.getAttribute('numberCell').array],[2,8,7,1]);
    assert.equal(geometry.attributes.position.count,16);
    assert.ok(f.calls.some(([n,x,y])=>n==='87'&&x===480&&y===562));
    const shader={vertexShader:'#include <uv_vertex>'};material.onBeforeCompile(shader);
    assert.ok(shader.vertexShader.includes('attribute vec2 numberCell'));
    assert.ok(shader.vertexShader.includes('vMapUv = (vMapUv + numberCell) / 10.0'));
});
test('bench details honor independent helmet logos and stripes and hide with active roster IDs',()=>{
    const f=documentFixture(),scene=new THREE.Scene(),requests=[];
    const texture=new THREE.Texture({width:200,height:100});
    const bench=buildSidelinePlayers(scene,{home:[{id:1,number:12},{id:2,number:null}]},
        {home:{uniform:{helmet_stripe_enabled:true,helmet_stripe:'#ff0000'},helmet_logo_left:'/left.png',helmet_logo_right:'/right.png'}},
        f.document,url=>{requests.push(url);return texture;});
    assert.deepEqual(requests,['/left.png','/right.png']);
    for(const name of ['home-sideline-numbers','home-sideline-helmet-stripe','home-sideline-helmet-logo--1','home-sideline-helmet-logo-1']) assert.ok(scene.getObjectByName(name));
    bench.update(new Set(['home:1']));const matrix=new THREE.Matrix4();
    const numbers=scene.getObjectByName('home-sideline-numbers');
    numbers.getMatrixAt(0,matrix);assert.equal(matrix.elements[0],0);
    numbers.getMatrixAt(1,matrix);assert.equal(matrix.elements[0],0);
    bench.update(new Set());numbers.getMatrixAt(0,matrix);assert.equal(matrix.elements[0],1);
    const logo=scene.getObjectByName('home-sideline-helmet-logo-1');logo.onBeforeRender();assert.ok(logo.geometry.attributes.position.count>0);
});
