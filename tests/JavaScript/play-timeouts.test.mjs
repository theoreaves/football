import test from 'node:test';
import assert from 'node:assert/strict';
import {playTimeouts} from '../../resources/js/practice/play-timeouts.js';

test('timeout choices freeze the selected lineup and submit once without calling a play',()=>{
    const priorDoc=globalThis.document,priorData=globalThis.FormData,posted=[];
    const element=()=>({children:[],style:{},append(...children){this.children.push(...children);},setAttribute(name,value){this[name]=value;},addEventListener(name,fn){this[name]=fn;},remove(){},requestSubmit(){posted.push(Object.fromEntries(this.children.map(input=>[input.name,input.value])));}});
    globalThis.document={createElement:()=>element()};
    globalThis.FormData=class extends Map {constructor(form){super(form.values);}};
    try{
        const source={elements:{namedItem:name=>({value:name==='timeout_team'?'away':'7'})},querySelector:()=>({textContent:'Visitors timeout (2)'}),getAttribute:()=>'/play'};
        const root={querySelectorAll:()=>[source],append(){}};
        const form={values:[['_token','test'],['version','7'],['action','play'],['call','field_goal'],['offense_formation','field_goal'],['defense_formation','nickel']],dataset:{}};
        const host=element();let closed=0;
        playTimeouts(root,form,()=>closed++)(host);
        const buttons=host.children[0].children.filter(child=>child.type==='button');
        assert.equal(buttons.length,3);
        buttons[2].click();buttons[0].click();
        assert.equal(posted.length,1);assert.equal(closed,1);assert.equal(source.hidden,true);
        assert.equal(posted[0].action,'timeout');assert.equal(posted[0].timeout_timing,'after_lineup');
        assert.equal(posted[0].timeout_team,'away');assert.equal(posted[0].call,'field_goal');assert.equal(posted[0].offense_formation,'field_goal');
        assert.equal(form.inert,true);
    }finally{globalThis.document=priorDoc;globalThis.FormData=priorData;}
});

test('lineup timeout whistles before any snap or kick sounds',async()=>{
    const {soundCues}=await import('../../resources/js/practice/sound-cues.js');
    const cues=soundCues({call:'clock_event',outcome:'timeout',no_snap:true,duration:4.2,reveal_at:3.5,timeout_at:3.5,events:[[3.5,'Timeout']]});
    assert.equal(cues.filter(c=>c.type==='whistle').length,1);
    assert.equal(cues.find(c=>c.type==='whistle').time,3.5);
    assert.equal(cues.some(c=>['snap','kick','throw'].includes(c.type)),false);
});
