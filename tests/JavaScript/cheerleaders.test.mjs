import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {buildCheerleader,animateCheerleader} from '../../resources/js/practice/cheerleader-model.js';
import {buildSidelineCheerleaders,CHEERLEADER_SLOTS} from '../../resources/js/practice/sideline-cheerleaders.js';

test('cheerleader preview uses all three colors and an aspect-correct team logo',()=>{
    const texture=new THREE.Texture({width:200,height:100});
    const model=buildCheerleader({primary:'#123456',secondary:'#abcdef',accent:'#fedcba'},'logo.svg',()=>texture);
    assert.equal(model.getObjectByName('top').material.color.getHexString(),'123456');
    assert.equal(model.getObjectByName('waistband').material.color.getHexString(),'abcdef');
    assert.equal(model.getObjectByName('pom-core').material.color.getHexString(),'fedcba');
    const logo=model.getObjectByName('cheer-team-logo');logo.onBeforeRender();assert.equal(logo.scale.x/logo.scale.y,2);
    assert.equal(model.getObjectByName('cheer-team-logo').material.map,texture);
    for(const time of [0,3,6,9,NaN]){
        animateCheerleader(model,time,0,true);model.updateMatrixWorld(true);
        model.traverse(part=>{for(const value of part.matrixWorld.elements)assert.ok(Number.isFinite(value));});
    }
});

test('squad is instanced in twelve clear sideline slots and respects disabled settings',()=>{
    for(const enabled of [false,0,'0']){
        const scene=new THREE.Scene(),squad=buildSidelineCheerleaders(scene,{home:{cheerleaders:{enabled}}});
        assert.equal(scene.children.length,0);squad.update(.1);squad.cue({type:'touchdown'});squad.reset();
    }
    const scene=new THREE.Scene(),squad=buildSidelineCheerleaders(scene,{home:{primary_color:'#123456'}});
    assert.equal(CHEERLEADER_SLOTS.length,12);
    assert.ok(CHEERLEADER_SLOTS.every(slot=>slot.z<0 && (slot.x<29 || slot.x>91)));
    const meshes=squad.group.children.filter(part=>part.isInstancedMesh);
    assert.ok(meshes.length>10 && meshes.length<80);
    assert.ok(meshes.every(mesh=>mesh.count===12));
    const shirt=meshes.find(mesh=>mesh.name==='cheer-squad-top');
    assert.equal(shirt.material.color.getHexString(),'123456');
    const matrix=new THREE.Matrix4();shirt.getMatrixAt(0,matrix);
    assert.ok(Math.abs(matrix.elements[12]-16)<.001);
    assert.ok(Math.abs(matrix.elements[14]+7)<.001);
    squad.update(NaN);squad.update(.1);
    for(const mesh of meshes)for(const value of mesh.instanceMatrix.array)assert.ok(Number.isFinite(value));
});

test('home cues turn the squad toward the field while away cues leave the normal routine intact',()=>{
    const squad=buildSidelineCheerleaders(new THREE.Scene(),{home:{}});
    const shirt=squad.group.children.find(part=>part.name==='cheer-squad-top');
    const zDirection=()=>{const matrix=new THREE.Matrix4();shirt.getMatrixAt(0,matrix);return matrix.elements[10];};
    assert.ok(zDirection()<0);
    squad.cue({type:'away_score'});squad.update(.1);assert.ok(zDirection()<0);
    squad.cue({type:'touchdown'});squad.update(.1);assert.ok(zDirection()>0);
    squad.reset();assert.ok(zDirection()<0);
    squad.cue({type:'home_turnover'});squad.update(.1);assert.ok(zDirection()>0);
    for(let i=0;i<80;i++)squad.update(.1);
    assert.ok(zDirection()<0);
});
