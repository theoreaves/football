import test from 'node:test';
import assert from 'node:assert/strict';
import {mountSimProgress,showSimProgress,hideSimProgress} from '../../resources/js/sim-progress.js';
class Element {
    constructor(tag){this.tagName=tag.toUpperCase();this.attributes={};this.dataset={};this.children=[];this.listeners=new Map();this.hidden=false;}
    setAttribute(k,v){this.attributes[k]=v;}
    removeAttribute(k){delete this.attributes[k];}
    hasAttribute(k){return Object.hasOwn(this.attributes,k);}
    append(...children){for(const child of children){this.children.push(child);child.parent=this;}}
    remove(){if(this.parent)this.parent.children=this.parent.children.filter(c=>c!==this);this.parent=null;}
    get isConnected(){return Boolean(this.parent);}
    addEventListener(k,fn){this.listeners.set(k,fn);}
    showModal(){assert.equal(this.isConnected,true);this.modal=true;this.setAttribute('open','');}
    set innerHTML(value){this.html=value;this.parts=new Map([['p',new Element('p')],['strong',new Element('strong')],['.sim-progress-spinner',new Element('span')],['.sim-progress-card',new Element('div')]]);}
    querySelector(k){return this.parts?.get(k)||null;}
}
class Form extends Element {
    constructor(){super('form');this.setAttribute('data-sim-cpu','');this.dataset.simCount='2';this.action='/seasons/1/sim-cpu';this.button=new Element('button');this.status=new Element('p');this.status.hidden=true;}
    querySelector(k){return k==='button'?this.button:k==='[data-sim-status]'?this.status:null;}
}
const flush=()=>new Promise(resolve=>setImmediate(resolve));
test('simulation popup uses a modal and active progress survives repeated initialization and pageshow',async()=>{
    const names=['document','window','HTMLFormElement','FormData','fetch'];const original=Object.fromEntries(names.map(n=>[n,globalThis[n]]));
    const docEvents=new Map(),winEvents=new Map(),body=new Element('body');let resolveRequest;const destinations=[];
    globalThis.document={body,createElement:tag=>new Element(tag),addEventListener:(k,fn)=>docEvents.set(k,fn),removeEventListener:k=>docEvents.delete(k)};
    globalThis.window={addEventListener:(k,fn)=>winEvents.set(k,fn),removeEventListener:k=>winEvents.delete(k),location:{assign:url=>destinations.push(url)}};
    globalThis.HTMLFormElement=Form;globalThis.FormData=class{};
    globalThis.fetch=()=>new Promise(resolve=>resolveRequest=resolve);
    try {
        showSimProgress(true);const first=body.children[0];assert.equal(first.tagName,'DIALOG');assert.equal(first.modal,true);assert.match(first.html,/role="status"/);
        let cancelled=false;first.listeners.get('cancel')({preventDefault(){cancelled=true;}});assert.equal(cancelled,true);
        hideSimProgress();assert.equal(body.children.length,0);
        mountSimProgress();const form=new Form();form.button.disabled=false;
        docEvents.get('submit')({target:form,submitter:form.button,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}});
        const popup=body.children[0];assert.equal(popup.modal,true);assert.equal(form.button.disabled,true);
        assert.equal(form.status.hidden,false);assert.match(form.status.textContent,/Completed 0 of 2/);
        mountSimProgress();winEvents.get('pageshow')();assert.equal(body.children[0],popup);assert.equal(popup.isConnected,true);
        resolveRequest({ok:true,headers:{get:()=> 'application/json'},json:async()=>({simulated:1,remaining:1,redirect:'/seasons/1'})});
        await flush();assert.match(form.status.textContent,/Completed 1 of 2/);assert.equal(popup.querySelector('p').textContent,form.status.textContent);
        mountSimProgress();assert.equal(body.children[0],popup);
        resolveRequest({ok:true,headers:{get:()=> 'application/json'},json:async()=>({simulated:1,remaining:0,redirect:'/seasons/1'})});
        await flush();assert.deepEqual(destinations,['/seasons/1']);assert.equal(form.button.disabled,false);
        winEvents.get('pageshow')();assert.equal(body.children.length,0);
    } finally {hideSimProgress();for(const n of names){if(original[n]===undefined)delete globalThis[n];else globalThis[n]=original[n];}}
});
