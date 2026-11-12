import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {buildResultReferee} from '../../resources/js/practice/result-referee.js';
import {buildPenaltyPresentation} from '../../resources/js/practice/penalty-presentation.js';

function fixture(){const tags=[],panels=[];const node=()=>({dataset:{},style:{},children:[],append(...items){this.children.push(...items);},prepend(){throw Error('No inset canvas allowed');},setAttribute(){},remove(){},replaceChildren(){}});const document={createElement(tag){tags.push(tag);if(tag==='canvas')throw Error('No second canvas');return node();}};const root={...node(),ownerDocument:document,hidden:false,querySelector(selector){return selector==='[data-result-ok]'?{before:panel=>panels.push(panel)}:null;}};return{root,tags,panels};}
test('shared Play result captions remain without creating an inset renderer',()=>{const f=fixture();const before={phase:'scrimmage',possession:'home',distance:6,down:2,home_score:0,away_score:0};const result=buildResultReferee(f.root,{call:'inside_run',gain:8,outcome:'tackle',events:[]},before,{...before,down:1},{inset:false});result.update(.1);assert.equal(f.panels[0].children[0].textContent,'FIRST DOWN');assert.ok(!f.tags.includes('canvas'));result.dispose();});
test('shared Play keeps flag and canonical penalty decision text without a second scene',()=>{const f=fixture(),scene=new THREE.Scene();const view=buildPenaltyPresentation(f.root,scene,{team:'home',type:'holding',yards:10,accepted:false},{home:'Home'},true,{inset:false});view.update({moment:null,time:0,announcing:true,announcementTime:.5});const texts=f.root.children.flatMap(node=>node.children.map(child=>child.textContent));assert.ok(texts.some(text=>text?.includes('Awaiting accept / decline')));assert.equal(scene.children.length,1);assert.ok(!f.tags.includes('canvas'));view.dispose();});

test('Coach overlay ownership hides stale markers without hiding shared actors or football',async()=>{
    const {setCoachLayerVisible}=await import('../../resources/js/practice/shared-coach-presentation.js');
    const scene=new THREE.Scene(),layer=new THREE.Group(),actor=new THREE.Group(),ball=new THREE.Mesh();
    scene.add(layer,actor,ball);layer.add(new THREE.Group());
    setCoachLayerVisible(layer,false);assert.equal(layer.visible,false);assert.equal(actor.visible,true);assert.equal(ball.visible,true);
    setCoachLayerVisible(layer,true);assert.equal(layer.visible,true);assert.equal(scene.children.length,3);
});
test('Coach reacquires the same carried ball and restores its long axis without replacing geometry',async()=>{
    const {prepareCoachBall}=await import('../../resources/js/practice/shared-coach-presentation.js');
    const scene=new THREE.Scene(),waist=new THREE.Group(),ball=new THREE.Mesh(new THREE.SphereGeometry(.25),new THREE.MeshBasicMaterial());
    scene.add(waist);waist.position.set(10,2,3);waist.add(ball);ball.position.set(1,2,3);ball.scale.set(.85,1.6,.85);scene.updateMatrixWorld(true);
    const geometry=ball.geometry,position=ball.getWorldPosition(new THREE.Vector3());
    prepareCoachBall({scene,ball});assert.equal(ball.parent,scene);assert.equal(ball.geometry,geometry);assert.deepEqual(ball.scale.toArray(),[1.6,.85,.85]);assert.ok(ball.position.distanceTo(position)<1e-9);
    prepareCoachBall({scene,ball});assert.equal(scene.children.filter(child=>child===ball).length,1);
});


test('actual timeout submit handler leaves shared choice usable before transport acceptance but preserves standalone lock',async()=>{
    const {readFile}=await import('node:fs/promises');
    const source=await readFile(new URL('../../resources/js/practice/field.js',import.meta.url),'utf8');
    const body=source.match(/root\.querySelectorAll\('\[data-timeout-form\]'\)\.forEach\(form => form\.addEventListener\('submit', \(\) => \{([^}]+)\}\)\);/)[1];
    const submit=new Function('form','shared','saveCamera','setCpuAuto',body);
    for(const shared of [true,false]){
        const button={disabled:false},form={querySelector:()=>button};let saved=0,auto=true;
        submit(form,shared,()=>saved++,value=>auto=value);
        assert.equal(button.disabled,!shared);assert.equal(saved,1);assert.equal(auto,false);
        if(shared){submit(form,shared,()=>saved++,value=>auto=value);assert.equal(button.disabled,false,'transiently declined shared timeout remains selectable');}
    }
});
