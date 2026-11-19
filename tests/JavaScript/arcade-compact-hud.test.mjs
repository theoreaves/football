import {createFourthDownActions} from '../../resources/js/arcade/fourth-down.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import {compactScore,createHudNotice,mountCompactHud} from '../../resources/js/arcade/compact-hud.js';

test('compact scoreboard uses canonical teams, period, clock, possession-relative spot and goal distance',()=>{
    const s={home_score:14,away_score:7,quarter:3,clock:63,down:2,distance:6,spot:60,phase:'scrimmage'};
    const score=compactScore(s,{home:'Chiefs',away:'Jets'});assert.equal(score.score,'Jets 7 · Chiefs 14');
    assert.equal(score.state,'Q3 · 1:03 · 2nd & 6 · Opp 40');
    assert.match(compactScore({...s,spot:98,distance:2}).state,/Goal/);
    assert.match(compactScore({...s,phase:'kickoff'}).state,/Kickoff/);assert.match(compactScore({...s,phase:'extra_point'}).state,/Try/);
    assert.match(compactScore({...s,quarter:5,clock:0}).state,/OT · 0:00/);assert.match(compactScore({...s,status:'final'}).state,/FINAL/);
});
test('routine notices expire once; repeated HUD frames do not reset the deadline',()=>{
    const tasks=[],shown=[];let hides=0;
    const n=createHudNotice({show:t=>shown.push(t),hide:()=>hides++,setTimer:(fn,ms)=>{tasks.push({fn,ms});return tasks.length;},clearTimer(){}});
    n.set('Saved');for(let i=0;i<120;i++)n.set('Saved');assert.equal(tasks.length,1);assert.equal(tasks[0].ms,5500);tasks[0].fn();assert.equal(hides,1);assert.deepEqual(shown,['Saved']);n.dispose();
});
test('stale notification timers cannot erase new recovery messages, clear state or disposed HUD',()=>{
    const tasks=[];let hides=0;
    const n=createHudNotice({show(){},hide:()=>hides++,setTimer:fn=>{tasks.push(fn);return tasks.length;},clearTimer(){}});
    n.set('Saved');n.set('Unknown save; retry',{persistent:true});tasks[0]();assert.equal(hides,0);
    n.set('');assert.equal(hides,1);n.set('Ready');n.dispose();tasks[1]();assert.equal(hides,1);
});
class Element {
    constructor(tag,doc){this.tag=tag;this.ownerDocument=doc;this.children=[];this.attrs={};this.dataset={};this.className='';this.listeners=new Map();this.hidden=false;this.disabled=false;this.textContent='';
        this.classList={remove:(...names)=>{this.className=this.className.split(' ').filter(x=>!names.includes(x)).join(' ');},contains:c=>this.className.split(' ').includes(c),add:c=>{if(!this.classList.contains(c))this.className+=' '+c;},toggle:(c,on)=>{const yes=on??!this.classList.contains(c);this.className=this.className.split(' ').filter(x=>x!==c).concat(yes?[c]:[]).join(' ');}};}
    get parentNode(){return this.parent;}
    get nextSibling(){return this.parent?.children[this.parent.children.indexOf(this)+1]??null;}
    insertBefore(node,next){node.remove();node.parent=this;const i=next?this.children.indexOf(next):-1;if(i<0)this.children.push(node);else this.children.splice(i,0,node);}
    append(...nodes){for(const n of nodes){n.remove();n.parent=this;this.children.push(n);}}
    remove(){if(this.parent)this.parent.children=this.parent.children.filter(n=>n!==this);this.parent=null;}
    setAttribute(k,v){this.attrs[k]=v;}
    addEventListener(t,fn){this.listeners.set(t,fn);}
    fire(t,event={}){this.listeners.get(t)?.({stopPropagation(){},...event});}
    all(){return this.children.flatMap(n=>[n,...n.all()]);}
    matches(s){return s.startsWith('.')?this.classList.contains(s.slice(1)):s.startsWith('[')?Object.hasOwn(this.attrs,s.slice(1,-1)):this.tag===s;}
    querySelectorAll(s){return this.all().filter(n=>n.matches(s));}
    querySelector(s){return this.querySelectorAll(s)[0]??null;}
    closest(s){return this.matches(s)?this:this.parent?.closest(s)??null;}
}
function fixture(options={}){
    const doc={createElement:t=>new Element(t,doc),createTextNode:t=>{const e=new Element('#text',doc);e.textContent=t;return e;}};
    const root=new Element('main',doc),score=new Element('div',doc);score.className='arcade-scoreboard';root.append(score);
    const setup=new Element('div',doc);setup.className='arcade-setup';root.append(setup);
    const toolbar=new Element('div',doc);toolbar.className='arcade-toolbar';root.append(toolbar);
    const nodes={};for(const k of ['score','state','live','flow-status','clock','notices','status','playbook','reserve','start','pause','cancel','submit','replay','retry-flow','timeout','spike','coach','preferences','advance','fast']){
        const n=nodes[k]=new Element(['score','state','live','flow-status','clock','notices','status'].includes(k)?'span':'button',doc);n.setAttribute(`data-exhibition-${k}`,'');(k==='score'||k==='state'?score:toolbar).append(n);
    }
    for(const k of ['tempo','side','defense']){const label=new Element('label',doc),n=nodes[k]=new Element('select',doc);n.setAttribute(`data-exhibition-${k}`,'');label.append(n);setup.append(label);}
    const hud=mountCompactHud(root,options);const base={official:{home_score:0,away_score:0,clock:180,quarter:1,down:1,distance:10,spot:25},flow:{enabled:true,phase:'offense'},state:null,pending:null,busy:false,replaying:false,recovered:false,recording:null};
    return {root,nodes,hud,base,update:v=>hud.update({...base,...v})};
}
test('compact toolbar preserves original handlers and disabled gates while presenting contextual controls',()=>{
    const f=fixture();try{
        f.nodes.reserve.disabled=true;f.update({});assert.equal(f.nodes.reserve.hidden,false);assert.equal(f.nodes.reserve.disabled,true);
        f.update({state:{status:'ready'},pending:{session:{}}});assert.equal(f.nodes.reserve.hidden,true);assert.equal(f.nodes.start.hidden,false);assert.equal(f.nodes.cancel.hidden,false);assert.equal(f.nodes.submit.hidden,true);
        f.update({state:{status:'running'},pending:{session:{}}});assert.equal(f.nodes.pause.hidden,false);assert.equal(f.nodes.start.hidden,true);assert.equal(f.nodes.replay.hidden,true);
        f.update({state:{status:'finished'},pending:{body:'exact-result'},recording:{}});assert.equal(f.nodes.submit.hidden,false);assert.equal(f.nodes.replay.hidden,false);
        f.update({recovered:true,pending:{body:'exact-result'},recording:{}});assert.match(f.nodes.submit.textContent,/Retry/);assert.equal(f.nodes.submit.hidden,false);assert.equal(f.nodes.start.hidden,true);
        assert.equal(f.root.querySelector('[data-exhibition-submit]'),f.nodes.submit);
    }finally{f.hud.dispose();}
});
test('developer diagnostics default off and cannot be enabled by receiving runtime metrics',()=>{
    const f=fixture();try{
        const dev=f.root.querySelector('.compact-developer');f.update({});assert.equal(dev.hidden,true);assert.equal(f.root.classList.contains('compact-debug-on'),false);
        const check=f.root.querySelector('input');check.checked=true;check.fire('change');assert.equal(dev.hidden,false);check.checked=false;check.fire('change');assert.equal(dev.hidden,true);
        assert.equal(f.root.querySelector('.compact-hud-bar').attrs['data-compact-hud-interactive'],'');
    }finally{f.hud.dispose();}
});
test('required game-decision and unconfirmed retry indicator stay outside the hidden diagnostic panel',()=>{
    const f=fixture();try{
        f.update({flow:{phase:'decision',required_decision:'penalty'}});const decision=f.root.querySelector('.compact-decision');assert.equal(decision.hidden,false);assert.equal(decision.closest('.compact-developer'),null);
        f.update({flowPending:true});assert.match(decision.textContent,/unconfirmed/);assert.equal(f.nodes['retry-flow'].closest('.compact-recovery-controls')!==null,true);
        f.update({flowBlocked:true});assert.match(decision.textContent,/Reload/);
    }finally{f.hud.dispose();}
});

test('explicit arbitrary-text errors persist, and a successful reserved/live transition clears stale warnings',()=>{
    const originalSet=globalThis.setTimeout,originalClear=globalThis.clearTimeout,tasks=[];
    globalThis.setTimeout=fn=>{tasks.push(fn);return tasks.length;};globalThis.clearTimeout=()=>{};
    const f=fixture();try{
        const toast=f.root.querySelector('.compact-toast');
        f.nodes.status.textContent='Saved';f.nodes.status.dataset.noticePersistent='false';f.update({});assert.equal(toast.hidden,false);const oldTimer=tasks.at(-1);
        f.nodes.status.textContent='Connection refused';f.nodes.status.dataset.noticePersistent='true';f.update({});oldTimer();assert.equal(toast.hidden,false);assert.equal(toast.textContent,'Connection refused');
        f.nodes.status.textContent='Play reserved with the saved roster.';f.nodes.status.dataset.noticePersistent='false';f.update({});assert.equal(toast.hidden,true);
        f.nodes.status.textContent='Play live.';f.update({});assert.equal(toast.hidden,true);
        f.nodes.status.textContent='Saved';f.update({});assert.equal(toast.hidden,false);assert.equal(toast.textContent,'Saved');
    }finally{f.hud.dispose();globalThis.setTimeout=originalSet;globalThis.clearTimeout=originalClear;}
});

test('disposal restores original nodes so BFCache remount retains controls, status and handlers',()=>{
    const f=fixture();let clicks=0;f.nodes.reserve.addEventListener('click',()=>clicks++);
    f.hud.dispose();f.hud.dispose();
    assert.equal(f.root.querySelector('.compact-hud-bar'),null);assert.equal(f.root.querySelector('.compact-developer'),null);
    for(const [key,node]of Object.entries(f.nodes))assert.equal(f.root.querySelector(`[data-exhibition-${key}]`),node,key);
    assert.equal(f.root.querySelector('.arcade-toolbar').hidden,false);assert.equal(f.root.querySelector('.arcade-setup').hidden,false);
    assert.equal(f.root.classList.contains('compact-game-hud'),false);
    const next=mountCompactHud(f.root);try{next.update(f.base);f.nodes.reserve.fire('click');assert.equal(clicks,1);}finally{next.dispose();}
});

test('phase compaction keeps camera/timeline nodes and required dialogs inside their original owner root',()=>{
    const f=fixture(),phase=new Element('section',f.root.ownerDocument);f.root.append(phase);
    const camera=new Element('div',f.root.ownerDocument),timeline=new Element('div',f.root.ownerDocument),decision=new Element('dialog',f.root.ownerDocument);
    camera.className='game-camera-controls';timeline.className='game-timeline';phase.append(camera,timeline,decision);
    try{
        f.hud.attachPhase(phase);assert.equal(phase.querySelector('.game-camera-controls'),camera);assert.equal(phase.querySelector('.game-timeline'),timeline);
        assert.equal(camera.closest('.compact-menu')!==null,true);assert.equal(camera.parent,timeline.parent);assert.equal(decision.parent,phase);
    }finally{f.hud.dispose();}
});

test('recorded Arcade and historical Coach playback label the unchanged official scoreboard as current game',()=>{
    const f=fixture();try{
        f.update({});const current=f.nodes.state.textContent,score=f.nodes.score.textContent;
        for(const playback of [{replaying:true},{coachHistory:true}]) {
            f.update(playback);assert.equal(f.nodes.state.textContent,`Current game · ${current}`);assert.equal(f.nodes.score.textContent,score);
            f.update({});assert.equal(f.nodes.state.textContent,current);
        }
    }finally{f.hud.dispose();}
});

test('developer trace reads only the currently mounted phase status without retaining replaced fragments',()=>{
    const f=fixture(),originalNow=Date.now;let now=1000;Date.now=()=>now;
    const phase=text=>{const p=new Element('section',f.root.ownerDocument),s=new Element('p',f.root.ownerDocument);p.className='persistent-phase-controls';s.setAttribute('data-status','');s.textContent=text;p.append(s);f.root.append(p);return p;};
    try{
        const old=phase('Old phase');const checkbox=f.root.querySelector('input');checkbox.checked=true;checkbox.fire('change');f.update({});
        assert.equal(JSON.parse(f.root.querySelector('.compact-trace').textContent).phaseStatus,'Old phase');
        old.remove();phase('New phase');now+=1000;f.update({});assert.equal(JSON.parse(f.root.querySelector('.compact-trace').textContent).phaseStatus,'New phase');
        assert.equal(old.querySelector('[data-status]').textContent,'Old phase');
    }finally{Date.now=originalNow;f.hud.dispose();}
});

test('fourth-down kick shortcuts use canonical offered calls and reservation/transition gates',()=>{
 const calls=[],f=fixture({onSpecial:call=>calls.push(call)});try{
 const punt=f.root.all().find(n=>n.textContent==='Punt'),fg=f.root.all().find(n=>n.textContent==='Field Goal');
 f.hud.setSpecialCalls(['punt','field_goal']);f.update({});assert.equal(punt.hidden,true);
 const fourth={official:{...f.base.official,down:4}};f.update(fourth);assert.equal(punt.hidden,false);assert.equal(fg.hidden,false);punt.fire('click');fg.fire('click');assert.deepEqual(calls,['punt','field_goal']);
 f.update({...fourth,pending:{session:{}}});assert.equal(punt.disabled,true);punt.fire('click');assert.equal(calls.length,2);
 f.nodes.reserve.disabled=true;f.update(fourth);assert.equal(fg.disabled,true);f.nodes.reserve.disabled=false;
 for(const gate of [{busy:true},{flowPending:true},{flowBlocked:true},{replaying:true}]){f.update({...fourth,...gate});assert.equal(punt.disabled,true);}
 f.hud.setSpecialCalls(['punt']);f.update(fourth);assert.equal(fg.hidden,true);assert.equal(punt.hidden,false);
 f.update({...fourth,flow:{phase:'defense'}});assert.equal(punt.hidden,true);
 }finally{f.hud.dispose();}
});

test('visible kick buttons dispatch through captured canonical eligibility after Coach filters its own options',()=>{
 const sent=[],calls=['inside_run','punt','field_goal'];
 const actions=createFourthDownActions({blocked:()=>false,tempo:()=> 'normal',send:(...args)=>sent.push(args)});
 const f=fixture({onSpecial:call=>actions.execute(call)});try{
 const fourth={official:{...f.base.official,down:4}};
 actions.update({state:fourth.official,flow:f.base.flow},calls);f.hud.setSpecialCalls(actions.offered);
 calls.splice(0,calls.length,'inside_run');f.update(fourth);
 for(const label of ['Punt','Field Goal'])f.root.all().find(n=>n.textContent===label).fire('click');
 assert.deepEqual(sent,[['play',{call:'punt',tempo:'normal'}],['play',{call:'field_goal',tempo:'normal'}]]);
 }finally{f.hud.dispose();}
});
