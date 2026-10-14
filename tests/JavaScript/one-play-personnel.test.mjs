import test from 'node:test';
import assert from 'node:assert/strict';
import {filterPersonnel, prunePersonnel, personnelRequest, createOnePlayPersonnel} from '../../resources/js/practice/one-play-personnel.js';

test('replacement search matches names numbers and positions without hiding eligibility markers', () => {
    const pool = [{id:1,name:'Sam Smith',number:32,position:'WR',unavailable:null},{id:2,name:'John Jones',number:90,position:'DL',unavailable:'Injured'}];
    assert.deepEqual(filterPersonnel(pool, 'smith'), [pool[0]]);
    assert.deepEqual(filterPersonnel(pool, ' 90 '), [pool[1]]);
    assert.deepEqual(filterPersonnel(pool, 'dl'), [pool[1]]);
});

test('formation changes discard inactive positions and unrelated teams', () => {
    const choices = {home:{RB:4,WR3:5,TE2:6},away:{LB3:7,CB3:8}};
    const teams = {home:{players:{RB:{},TE2:{}}},away:{players:{CB3:{}}}};
    assert.deepEqual(prunePersonnel(choices, teams), {home:{RB:4,TE2:6},away:{CB3:8}});
    assert.deepEqual(prunePersonnel(choices, {}), {home:{},away:{}});
});

test('preview request replaces stale hidden picks and preserves version csrf and calls', () => {
    const original = globalThis.FormData;
    class FormDataMock extends Map {
        constructor() {super([['version','8'],['_token','csrf'],['call','inside_run'],['one_play[home][WR4]','99']]);}
    }
    globalThis.FormData = FormDataMock;
    try {
        const data = personnelRequest({}, {home:{RB:3,WR1:null},away:{S1:4}});
        assert.equal(data.get('one_play[home][RB]'), '3');
        assert.equal(data.get('one_play[away][S1]'), '4');
        assert.equal(data.has('one_play[home][WR4]'), false);
        assert.equal(data.get('version'), '8');
        assert.equal(data.get('_token'), 'csrf');
    } finally {globalThis.FormData = original;}
});

test('personnel preview locks submission and ignores an aborted older response', async () => {
    const previous = {document:globalThis.document,fetch:globalThis.fetch,FormData:globalThis.FormData};
    class Element {
        constructor() {this.children=[];this.listeners={};this.attrs={};this.textContent='';}
        append(...items) {this.children.push(...items);}
        replaceChildren(...items) {this.children=items;this.textContent='';}
        setAttribute(k,v) {this.attrs[k]=v;}
        removeAttribute(k) {delete this.attrs[k];}
        addEventListener(k,fn) {this.listeners[k]=fn;}
    }
    const requests = [];
    globalThis.document = {createElement:()=>new Element()};
    globalThis.FormData = class extends Map {constructor(){super();}};
    globalThis.fetch = () => new Promise(resolve => requests.push(resolve));
    try {
        const form = new Element(); form.elements={namedItem:()=>({value:'shotgun'})};
        const ui = createOnePlayPersonnel({dataset:{personnelUrl:'/personnel',teamNames:'{}'}},form);
        const host = new Element(), button = {disabled:false};
        ui.render(host,button);
        assert.equal(button.disabled,true);
        const event = {preventDefault(){this.prevented=true;},stopImmediatePropagation(){this.stopped=true;}};
        form.listeners.submit(event);
        assert.equal(event.prevented,true); assert.equal(event.stopped,true);
        ui.cancel(); ui.render(host,button);
        requests[0]({ok:true,json:async()=>({supported:false,teams:{}})});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(button.disabled,true);
        requests[1]({ok:true,json:async()=>({supported:false,teams:{}})});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(button.disabled,false); assert.equal(ui.ready(),true);
        ui.render(host,button);
        requests[2]({ok:false,json:async()=>({message:'Game changed'})});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(ui.ready(),false); assert.equal(button.disabled,true);
        assert.equal(host.children[0].textContent,'Game changed');
    } finally {Object.assign(globalThis,previous);}
});
