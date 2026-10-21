import test from 'node:test';
import assert from 'node:assert/strict';
import {sampleNoHuddle} from '../../resources/js/practice/huddle.js';
import {hurryUpCalling} from '../../resources/js/practice/hurry-up.js';

test('no huddle returns the same identities straight to formation without a circular gathering',()=>{
    const finish={players:[{id:1,side:'home',role:'QB',x:35,z:20},{id:2,side:'away',role:'LB2',x:36,z:23}],ball:{x:35,y:1,z:20}};
    const ready={players:[{...finish.players[1],x:45,z:27},{...finish.players[0],x:39,z:27}],ball:{x:42,y:.25,z:26.7}};
    const snapshot=JSON.stringify(finish);
    const start=sampleNoHuddle(finish,ready,0),mid=sampleNoHuddle(finish,ready,.5),end=sampleNoHuddle(finish,ready,1);
    assert.deepEqual(start.players.map(p=>[p.id,p.x,p.z]),[[1,35,20],[2,36,23]]);
    assert.deepEqual(mid.players.map(p=>[p.id,p.x,p.z]),[[1,37,23.5],[2,40.5,25]]);
    assert.deepEqual(end.players.map(p=>[p.id,p.x,p.z]),[[1,39,27],[2,45,27]]);
    assert.equal(JSON.stringify(finish),snapshot);
});

test('hurry calling limits both formation selectors and releases them when normal tempo returns',()=>{
    let change;
    const tempo={value:'normal',addEventListener:(name,fn)=>change=fn};
    const selector=value=>({value,options:['singleback','shotgun','nickel','base_4_3'].map(value=>({value,disabled:false})),dispatchEvent(){}});
    const offense=selector('shotgun'),defense=selector('base_4_3'),panel={hidden:false};
    const controls={tempo,offense_formation:offense,defense_formation:defense};
    const form={elements:{namedItem:name=>controls[name]},dataset:{}};
    const root={dataset:{noHuddle:JSON.stringify({offense_formation:'singleback',defense_formation:'nickel'})},querySelectorAll:()=>[panel]};
    hurryUpCalling(root,form);
    tempo.value='hurry';change();
    assert.equal(offense.value,'singleback');assert.equal(defense.value,'nickel');
    assert.equal(offense.options.filter(o=>!o.disabled).length,1);assert.equal(panel.hidden,true);
    tempo.value='normal';change();
    assert.equal(offense.options.filter(o=>o.disabled).length,0);assert.equal(panel.hidden,false);
});
