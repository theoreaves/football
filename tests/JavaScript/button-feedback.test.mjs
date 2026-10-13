import test from 'node:test';
import assert from 'node:assert/strict';
import {mountButtonFeedback,waitsForLink,waitsForSubmit} from '../../resources/js/button-feedback.js';

class Element {
    constructor(tag='BUTTON',attributes={}){this.tagName=tag;this.attributes={...attributes};this.dataset={};this.children=[];this.classes=new Set();this.classList={add:k=>this.classes.add(k),remove:k=>this.classes.delete(k)};}
    getAttribute(key){return this.attributes[key] ?? null;}
    setAttribute(key,value){this.attributes[key]=value;}
    removeAttribute(key){delete this.attributes[key];}
    hasAttribute(key){return Object.hasOwn(this.attributes,key);}
    append(child){this.children.push(child);child.parent=this;}
    remove(){if(this.parent)this.parent.children=this.parent.children.filter(child=>child!==this);}
    closest(selector){return selector==='dialog[open]'?null:this;}
    querySelector(){return this.button;}
}
function surface(){
    const events=new Map(),body=new Element('BODY'),windowEvents=new Map();
    const doc={body,createElement:tag=>new Element(tag.toUpperCase()),addEventListener:(key,handler,capture)=>{const list=events.get(key)||[];list.push({handler,capture});events.set(key,list);}};
    const win={location:new URL('https://football.test/teams'),setTimeout:()=>{},addEventListener:(key,handler)=>windowEvents.set(key,handler)};
    const dispatch=(type,event={})=>{event.defaultPrevented??=false;event.preventDefault=()=>event.defaultPrevented=true;for(const listener of [...(events.get(type)||[])].sort((a,b)=>Number(b.capture)-Number(a.capture)))listener.handler(event);return event;};
    return {doc,win,dispatch,windowEvents};
}
const flush=()=>Promise.resolve();
test('native submission shows feedback without removing a named submit value and prevents duplicates',async()=>{
    const {doc,win,dispatch,windowEvents}=surface();mountButtonFeedback(doc,win);
    const form=new Element('FORM',{method:'post'}),button=new Element('BUTTON',{name:'scope',value:'quarter'});form.button=button;
    dispatch('submit',{target:form,submitter:button});await flush();
    assert.equal(button.getAttribute('aria-busy'),'true');assert.equal(button.getAttribute('value'),'quarter');assert.equal(button.disabled,undefined);
    assert.equal(button.children.length,1);assert.equal(doc.body.children[0].textContent,'Working…');
    const duplicate=dispatch('submit',{target:form,submitter:button});await flush();assert.equal(duplicate.defaultPrevented,true);
    windowEvents.get('pageshow')();assert.equal(button.getAttribute('aria-busy'),null);assert.equal(button.children.length,0);assert.equal(doc.body.children.length,0);
});
test('cancelled confirmations and dialog closing do not start a busy indicator',async()=>{
    const {doc,win,dispatch}=surface();mountButtonFeedback(doc,win);const button=new Element();
    dispatch('submit',{target:new Element('FORM',{method:'post'}),submitter:button,defaultPrevented:true});
    dispatch('submit',{target:new Element('FORM',{method:'dialog'}),submitter:button});await flush();assert.equal(doc.body.children.length,0);
    assert.equal(waitsForSubmit(new Element('FORM',{target:'_blank'}),{}),false);
});
test('navigation skips download new-tab fragment and external links',()=>{
    const location=new URL('https://football.test/teams');
    const link=href=>new Element('A',{href});
    assert.equal(waitsForLink(link('/seasons'),{},location),true);
    for(const href of ['#stats','/teams#stats','https://example.com','/export.zip','mailto:theo@example.test'])assert.equal(waitsForLink(link(href),{},location),false);
    assert.equal(waitsForLink(new Element('A',{href:'/download',download:''}),{},location),false);
    assert.equal(waitsForLink(link('/seasons'),{ctrlKey:true},location),false);
});
test('instant controls show pressed feedback and Livewire failures clear processing feedback',async()=>{
    const {doc,win,dispatch}=surface();let request;
    win.Livewire={hook:(name,handler)=>{assert.equal(name,'request');request=handler;}};
    mountButtonFeedback(doc,win);const button=new Element();dispatch('click',{target:button});await flush();
    assert.equal(button.classes.has('button-was-pressed'),true);assert.equal(doc.body.children.length,0);
    let failed;request({succeed(){},fail:handler=>failed=handler});assert.equal(button.getAttribute('aria-busy'),'true');failed();assert.equal(button.getAttribute('aria-busy'),null);
    const api=mountButtonFeedback(doc,win);assert.ok(api.reset);assert.equal(doc.body.children.length,0);
});

test('overlapping Livewire requests keep feedback until the last request finishes',()=>{
    const {doc,win,dispatch}=surface();let request;
    win.Livewire={hook:(_,handler)=>request=handler};mountButtonFeedback(doc,win);
    const button=new Element();dispatch('click',{target:button});
    let first,second;request({succeed:handler=>first=handler,fail(){}});request({succeed:handler=>second=handler,fail(){}});
    first();assert.equal(button.getAttribute('aria-busy'),'true');second();assert.equal(button.getAttribute('aria-busy'),null);
});
