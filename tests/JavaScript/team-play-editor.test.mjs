import test from 'node:test';
import assert from 'node:assert/strict';
import {mountTeamPlayEditors} from '../../resources/js/team-play-editor.js';

test('editor preserves edited assignments and resets incompatible roles when switching unit or formation',()=>{
    const previous={document:globalThis.document,Option:globalThis.Option};
    class Node extends EventTarget {
        constructor(tag='div'){super();this.tag=tag;this.children=[];this.value='';this.hidden=false;}
        append(...nodes){this.children.push(...nodes);}
        replaceChildren(...nodes){this.children=nodes;if(this.tag==='select')this.value=nodes[0]?.value || '';}
        querySelectorAll(tag){return this.children.flatMap(node=>[...(node.tag===tag?[node]:[]),...node.querySelectorAll(tag)]);}
    }
    globalThis.document={createElement:tag=>new Node(tag)};
    globalThis.Option=class extends Node {constructor(text,value){super('option');this.textContent=text;this.value=value;}};
    try {
        const fields={kind:new Node('select'),formation:new Node('select'),call:new Node('select')};
        fields.kind.value='offense';fields.formation.value='goal_line';fields.call.value='power';
        const positions=new Node(),search=new Node('input'),profile=new Node('label');
        const catalogue={offense:{goal_line:{label:'Goal line',calls:['power'],roles:['RB','FB']},shotgun:{label:'Shotgun',calls:['inside_zone'],roles:['QB','RB']}},defense:{nickel:{label:'Nickel',calls:['zone'],roles:['CB1','CB3']}}};
        const players=[{id:1,name:'Big Defender',position:'DL',number:90},{id:2,name:'Wide Receiver',position:'WR',number:10}];
        const form={dataset:{catalogue:JSON.stringify(catalogue),players:JSON.stringify(players),draft:'{"personnel":{"FB":1}}'},elements:{namedItem:key=>fields[key]},querySelector:selector=>selector==='[data-team-play-positions]'?positions:search,querySelectorAll:()=>[profile]};
        const doc={querySelectorAll:()=>[form]};
        mountTeamPlayEditors(doc);
        let selects=positions.querySelectorAll('select');
        assert.equal(selects[1].name,'personnel[FB]');assert.equal(selects[1].value,'1');
        assert.equal(selects[0].children.find(option=>option.value==='1').disabled,true);
        search.value='WR';search.dispatchEvent(new Event('input'));
        assert.equal(selects[1].value,'1');assert.ok(selects[1].children.some(option=>option.value==='1'));
        fields.formation.value='shotgun';fields.formation.dispatchEvent(new Event('change'));
        selects=positions.querySelectorAll('select');
        assert.deepEqual(selects.map(select=>select.name),['personnel[QB]','personnel[RB]']);
        assert.ok(selects.every(select=>select.value===''));assert.equal(fields.call.value,'inside_zone');
        fields.kind.value='defense';fields.kind.dispatchEvent(new Event('change'));
        assert.equal(fields.formation.value,'nickel');assert.equal(fields.call.value,'zone');assert.equal(profile.hidden,false);
        assert.deepEqual(positions.querySelectorAll('select').map(select=>select.name),['personnel[CB1]','personnel[CB3]']);
        const nodes=positions.children;
        mountTeamPlayEditors(doc);assert.equal(positions.children,nodes);
    } finally {Object.assign(globalThis,previous);}
});
