import test from 'node:test';
import assert from 'node:assert/strict';
import {mountArcadePlaybook,arcadePlayDiagram} from '../../resources/js/arcade/playbook.js';
import {FULL_TEAM_PLAYBOOK} from '../../resources/js/arcade/full-team-plays.js';
class Target {
    listeners = new Map();
    addEventListener(type, handler) { const handlers = this.listeners.get(type) ?? new Set(); handlers.add(handler); this.listeners.set(type, handlers); }
    removeEventListener(type, handler) { this.listeners.get(type)?.delete(handler); }
    fire(type, values = {}) {
        const event = { preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; }, ...values };
        for (const handler of [...(this.listeners.get(type) ?? [])]) handler(event);
        return event;
    }
    get listenerCount() { return [...this.listeners.values()].reduce((total, handlers) => total + handlers.size, 0); }
}
class Element extends Target {
    constructor(tag, document) { super(); this.tag = tag; this.ownerDocument = document; this.children = []; this.attributes = {}; this.textContent = ''; this.hidden = false; this.disabled = false; }
    setAttribute(key, value) { this.attributes[key] = value; if (key === 'value') this.value = value; }
    append(...nodes) { nodes.forEach(node => { this.children.push(node); node.parent = this; }); }
    get isConnected() { return this === this.ownerDocument.root || Boolean(this.parent?.isConnected); }
    remove() { this.parent?.children.splice(this.parent.children.indexOf(this), 1); this.parent = null; }
    focus() { this.ownerDocument.activeElement = this; }
    showModal() { this.open = true; }
    close() { this.open = false; this.fire('close'); }
    descendants() { return this.children.flatMap(child => [child, ...child.descendants()]); }
    querySelectorAll() { return this.descendants().filter(node => ['button', 'input', 'select'].includes(node.tag)); }
}

function fixture(){
 const document=new Target();document.createElement=tag=>new Element(tag,document);document.createElementNS=(_,tag)=>new Element(tag,document);
 const root=document.root=new Element('main',document),trigger=new Element('button',document),value={value:'inside_run'};root.append(trigger);trigger.focus();
 let enabled=true,selected=[];const book=mountArcadePlaybook(root,{trigger,value,canOpen:()=>enabled,onChoose:play=>selected.push(play.id),deps:{document}});
 return {document,root,trigger,value,book,selected,setEnabled(v){enabled=v;book.refresh();},get dialog(){return root.children.find(n=>n.tag==='dialog');},calls(){return root.descendants().filter(n=>n.attributes['data-playbook-call']);}};
}
test('playbook offers thirteen grouped calls and selection does not reserve/start a play',()=>{
 const f=fixture();f.book.open();assert.equal(f.calls().length,13);assert.equal(f.dialog.open,true);
 assert.deepEqual(f.calls().map(n=>n.attributes['data-playbook-call']),FULL_TEAM_PLAYBOOK.map(p=>p.id));
 const sneak=f.calls().find(n=>n.attributes['data-playbook-call']==='qb_sneak');sneak.fire('click');
 assert.equal(f.value.value,'qb_sneak');assert.deepEqual(f.selected,['qb_sneak']);assert.equal(f.book.active,false);assert.equal(f.document.activeElement,f.trigger);assert.match(f.trigger.textContent,/QB Sneak/);f.book.dispose();
});
test('cancel and Escape preserve selection, repeated opens do not duplicate and gates close modal',()=>{
 const f=fixture();f.book.open();f.book.open();assert.equal(f.root.children.filter(n=>n.tag==='dialog').length,1);
 f.dialog.fire('cancel');assert.equal(f.value.value,'inside_run');assert.deepEqual(f.selected,[]);
 f.book.open();f.dialog.fire('keydown',{key:'Escape'});assert.equal(f.book.active,false);
 f.book.open();f.setEnabled(false);assert.equal(f.book.active,false);assert.equal(f.trigger.disabled,true);f.book.open();assert.equal(f.book.active,false);
 f.setEnabled(true);f.book.open();f.book.dispose();f.book.dispose();assert.equal(f.book.active,false);f.book.open();assert.equal(f.book.active,false);
});
test('playbook keeps keyboard focus within modal and stale clicks cannot select after a gate changes',()=>{
 const f=fixture();f.book.open();const first=f.dialog.children[0].children[1],last=f.calls().at(-1);first.focus();f.dialog.fire('keydown',{key:'Tab',shiftKey:true});assert.equal(f.document.activeElement,last);f.dialog.fire('keydown',{key:'Tab'});assert.equal(f.document.activeElement,first);
 const old=f.calls()[3];f.setEnabled(false);old.fire('click');assert.deepEqual(f.selected,[]);f.book.dispose();
});
test('diagrams derive finite player and assignment points for every supported call',()=>{
 for(const play of FULL_TEAM_PLAYBOOK){const d=arcadePlayDiagram(play.id);assert.equal(d.players.length,11);assert.ok(d.lines.length>0);for(const line of d.lines)assert.ok(!/NaN|undefined|Infinity/.test(line.points));}
});
