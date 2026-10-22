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
test('single quick sims keep their modal while queued weekly submission stays non-blocking',()=>{
    const names=['document','window','HTMLFormElement'];const original=Object.fromEntries(names.map(n=>[n,globalThis[n]]));
    const docEvents=new Map(),winEvents=new Map(),body=new Element('body');
    globalThis.document={body,createElement:tag=>new Element(tag),addEventListener:(k,fn)=>docEvents.set(k,fn)};
    globalThis.window={addEventListener:(k,fn)=>winEvents.set(k,fn)};
    globalThis.HTMLFormElement=Form;
    try {
        mountSimProgress();
        const form=new Form();let prevented=false;
        docEvents.get('submit')({target:form,submitter:form.button,defaultPrevented:false,preventDefault(){prevented=true;}});
        assert.equal(body.children.length,0);assert.equal(prevented,false);
        form.button.name='quick_sim';form.button.value='1';
        docEvents.get('submit')({target:form,submitter:form.button,defaultPrevented:false,preventDefault(){prevented=true;}});
        const popup=body.children[0];assert.equal(popup.tagName,'DIALOG');assert.equal(popup.modal,true);assert.match(popup.html,/role="status"/);
        let cancelled=false;popup.listeners.get('cancel')({preventDefault(){cancelled=true;}});assert.equal(cancelled,true);
        winEvents.get('pageshow')();assert.equal(body.children.length,0);
    } finally {hideSimProgress();for(const n of names){if(original[n]===undefined)delete globalThis[n];else globalThis[n]=original[n];}}
});
