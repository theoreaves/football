import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {buildCoach,buildSidelineCoaches} from '../../resources/js/practice/coach-model.js';
import {uniformFromForm} from '../../resources/js/team-settings.js';
import {buildFootballPlayer} from '../../resources/js/practice/player-model.js';
test('coach profiles color the model and head coach alone carries a clipboard',()=>{
    const head=buildCoach({skin:'#593b2c',hair:'curly',hair_color:'#777777',shirt:'#123456',pants:'#333333',build:'stocky'},'head');
    assert.ok(head.getObjectByName('clipboard'));assert.ok(head.getObjectByName('headset'));assert.ok(head.getObjectByName('hair-curl'));
    assert.equal(head.getObjectByName('coach-shirt').material.color.getHexString(),'123456');
    assert.equal(buildCoach({},'offense').getObjectByName('clipboard'),undefined);
    head.traverse(object=>{if(object.isMesh){object.geometry.computeBoundingBox();assert.ok(Number.isFinite(object.geometry.boundingBox.max.x));}});
});
test('configured coaches remain in their respective sideline lanes during idle motion',()=>{
    const scene=new THREE.Scene(),staff={head:{name:'Coach'},offense:{name:'OC'},defense:{name:'DC'}};
    const controller=buildSidelineCoaches(scene,{home:{staff},away:{staff}});
    assert.equal(scene.children.length,6);
    for(let i=0;i<1000;i++)controller.update(.1);
    scene.children.forEach((coach,index)=>{assert.ok(coach.position.x>46&&coach.position.x<62);assert.equal(coach.position.z,index<3?-3.7:57.03);});
    const empty=new THREE.Scene();buildSidelineCoaches(empty,{});assert.equal(empty.children.length,0);
});
test('uniform preview reads unchecked flags correctly and honors unsaved colors and 00 numbering',()=>{
    const original=globalThis.FormData;
    globalThis.FormData=class {constructor(form){this.form=form;}getAll(key){return this.form[key] || [];}};
    try {
        const kit=uniformFromForm({'uniform_away_shirt':['#abcdef'],'uniform_away_name_enabled':['0','1'],'uniform_away_helmet_stripe_enabled':['0']},'away',{helmet_logo_left:'pending-logo'});
        assert.equal(kit.shirt,'#abcdef');assert.equal(kit.name_enabled,true);assert.equal(kit.helmet_stripe_enabled,false);assert.equal(kit.helmet_logo_left,'pending-logo');
        const texts=[],document={createElement(){return {getContext(){return {strokeText(text){texts.push(text);},fillText(text){texts.push(text);},measureText(text){return {width:text.length*10};},clearRect(){}};}};}};
        buildFootballPlayer({number:'00',lastname:'PLAYER'},kit,document);
        assert.ok(texts.includes('00'));assert.ok(texts.includes('PLAYER'));
    }finally{globalThis.FormData=original;}
});
test('optional coach caps use team colors and both logos retain artwork proportions',()=>{
    const texture=new THREE.Texture({width:400,height:100});
    const coach=buildCoach({hat:true,hat_color:'#abcdef',hair:'curly'},'head','team-logo',()=>texture);
    assert.equal(coach.getObjectByName('coach-cap').material.color.getHexString(),'abcdef');
    assert.equal(coach.getObjectByName('hair-curl').visible,false);
    for(const name of ['coach-shirt-logo','coach-cap-logo']) {
        const logo=coach.getObjectByName(name);assert.ok(logo);assert.equal(logo.material.map,texture);logo.onBeforeRender();
        assert.ok(Math.abs(logo.geometry.parameters.width/logo.geometry.parameters.height-4)<1e-9);
    }
    const bare=buildCoach({hat:false},'offense','team-logo',()=>texture);
    assert.equal(bare.getObjectByName('coach-cap'),undefined);assert.ok(bare.getObjectByName('coach-shirt-logo'));assert.equal(bare.getObjectByName('coach-cap-logo'),undefined);
    assert.equal(buildCoach({hat:'0'}).getObjectByName('coach-cap'),undefined);
    assert.ok(buildCoach({hat:'1'}).getObjectByName('coach-cap'));
    const scene=new THREE.Scene();buildSidelineCoaches(scene,{home:{team_logo:'home-logo',staff:{head:{hat:true}}}},()=>texture);
    assert.equal(scene.children[0].getObjectByName('coach-cap-logo').material.map,texture);
});
