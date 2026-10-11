import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import * as THREE from 'three';
import {buildMascot, MASCOT_CHARACTERS} from '../../resources/js/practice/mascot-model.js';

test('all configured mascots build finite full-body models with selected colors', () => {
    const config=fs.readFileSync(new URL('../../config/team-settings.php',import.meta.url),'utf8');
    const catalog=config.split("'mascot_characters' => [")[1].split('],')[0];
    assert.deepEqual([...catalog.matchAll(/'([^']+)' =>/g)].map(match=>match[1]),MASCOT_CHARACTERS);
    for(const character of MASCOT_CHARACTERS) {
        const model=buildMascot({character,name:'Chief',fur:'#123456',shirt:'#abcdef'});
        assert.equal(model.userData.character,character);
        assert.equal(model.userData.mascotName,'Chief');
        assert.equal(model.getObjectByName('jersey').material.color.getHexString(),'abcdef');
        assert.equal(model.getObjectByName('head').material.color.getHexString(),'123456');
        const bounds=new THREE.Box3().setFromObject(model);
        assert.ok(bounds.max.y>2 && bounds.min.y>=-.01,character);
        model.traverse(object=>{if(object.geometry)for(const value of object.geometry.attributes.position.array)assert.ok(Number.isFinite(value),character);});
    }
});

test('character-specific features distinguish animal and human costumes', () => {
    for(const [character,part] of Object.entries({dog:'muzzle',wolf:'pointed-ear',cowboy:'hat-brim',shark:'tooth',dolphin:'tail-fluke',king:'crown',knight:'visor',ram:'curled-horn',viking:'helmet-horn',pirate:'eyepatch',raccoon:'eye-mask',lion:'mane',cardinal:'crest'})) {
        assert.ok(buildMascot({character}).getObjectByName(part),`${character}: ${part}`);
    }
    assert.equal(buildMascot({character:'invalid'}).userData.character,'bear');
});

test('mascot shirt uses the shared logo texture and keeps its aspect ratio', () => {
    const texture=new THREE.Texture({width:200,height:100});
    const model=buildMascot({},'team.svg',url=>{assert.equal(url,'team.svg');return texture;});
    const logo=model.getObjectByName('mascot-shirt-logo');
    assert.equal(logo.material.map,texture);
    logo.onBeforeRender();
    assert.equal(logo.scale.x/ logo.scale.y,2);
    assert.equal(buildMascot().getObjectByName('mascot-shirt-logo'),undefined);
});
