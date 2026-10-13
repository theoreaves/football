import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {buildPenaltyPresentation} from '../../resources/js/practice/penalty-presentation.js';

function element(){return {children:[],style:{},hidden:false,setAttribute(){},append(child){this.children.push(child);},replaceChildren(){this.children=[];},remove(){this.removed=true;}};}
test('referee presentation embeds inside the decision dialog instead of adding another popup',()=>{
    const previous=globalThis.document;
    globalThis.document={createElement:element};
    try{
        const embedded=element(),root=element(),decisionForm=element();
        root.children.push(decisionForm);root.querySelector=selector=>selector==='[data-penalty-referee]'?embedded:null;
        const presentation=buildPenaltyPresentation(root,new THREE.Scene(),{type:'holding',team:'home',accepted:true,yards:10},{home:'Home'},true);
        assert.equal(root.children.length,2); // Existing decision form plus live FLAG badge.
        assert.equal(embedded.children[0].textContent,'REFEREE ANNOUNCEMENT');
        assert.match(embedded.children.at(-1).textContent,/Awaiting accept/);
        presentation.update({moment:null,time:0,announcing:true,announcementTime:1});
        assert.equal(embedded.hidden,false);assert.equal(decisionForm.removed,undefined);
        presentation.dispose();assert.equal(embedded.children.length,0);assert.equal(decisionForm.removed,undefined);
    }finally{globalThis.document=previous;}
});
