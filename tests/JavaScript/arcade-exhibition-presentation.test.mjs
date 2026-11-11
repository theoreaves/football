import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createExhibitionTransport, canContinueExhibitionDrive, noSnapNotice} from '../../resources/js/arcade/exhibition-session.js';

// Execute the production entry point with explicit UI/WebGL dependencies. CSS imports
// are the only reason this browser module cannot be imported directly by Node.
const source = readFileSync(new URL('../../resources/js/arcade/exhibition.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '').replace('export function mountExhibition', 'function mountExhibition')
    .replace(/^window.addEventListener\('pageshow'.*$/m, '');
const node = () => ({disabled:false, hidden:false, textContent:'', value:'', listeners:new Map(),
    addEventListener(type,fn){this.listeners.set(type,fn);}, removeEventListener(type){this.listeners.delete(type);}, focus(){}});
function harness({pending=null,stored=null}={}) {
    const nodes=Object.fromEntries(['reserve','start','pause','cancel','submit','replay','score','state','live','status','kind','tempo','coach','empty'].map(key=>[key,node()]));
    nodes.kind.value='run';nodes.tempo.value='normal';
    const official={version:0,possession:'home',phase:'scrimmage',status:'playing',quarter:1,clock:180,down:1,distance:10,spot:25};
    const root={dataset:{exhibition:JSON.stringify({state:official,available:true,startUrl:'/exhibitions/3/arcade/sessions',csrf:'test',pendingSession:pending})},isConnected:true,
        querySelector(selector){return selector==='[data-arcade-field]'?host:nodes[selector.match(/data-exhibition-([^\]]+)/)[1]];}};
    const host={...node(),clientWidth:800,clientHeight:500};
    const queue=[],requests=[],views=[],recording={version:1,frames:[[0]],events:[],actors:[],result:{reason:'tackle'}};
    let frame,transport,opened,step;
    const document=node(),window={...node(),location:{origin:'http://test'},localStorage:{getItem(){return stored?JSON.stringify(stored):null;},setItem(){},removeItem(){}}};
    const deps={document,window,AbortController,ResizeObserver:class{observe(){}disconnect(){}},
        requestAnimationFrame(fn){frame=fn;return 1;},cancelAnimationFrame(){},
        async fetch(url,options){requests.push({url,body:options.body});const response=queue.shift();if(response instanceof Error)throw response;return {ok:!response?.httpError,json:async()=>response?.httpError?{message:response.httpError}:response};},
        createDrill(config){return {status:'ready',mode:'running',direction:1,startX:config.exhibition.startX,firstDownX:config.exhibition.firstDownX,runner:{x:config.exhibition.startX},elapsed:0};},
        createPassingDrill(){assert.fail('not a passing test');},startDrill(s){s.status='running';},pauseDrill(s){if(s.status==='running')s.status='paused';},
        stepDrill(s){s.runner.x+=14;s.elapsed=2;s.status='finished';s.result={reason:'tackle'};},stepPassingDrill(){},STEP_SECONDS:1/60,
        createFixedStepper(fn){step=fn;return {reset(){},advance(){step();}};},
        createDrillRecording(){return {capture(){},export(){return recording;}};},
        createArcadeInput(){return {sample(){return {};},clear(){},dispose(){}};},
        createArcadeView(host,config){const view={config,disposed:0,render(s){this.last=s;},resize(){},dispose(){this.disposed++;}};views.push(view);return view;},
        createReplayPanel(){return {active:false,open(value){opened=value;},advance(){},dispose(){}};},
        createExhibitionTransport(options){transport=createExhibitionTransport(options);return transport;},canContinueExhibitionDrive,noSnapNotice,
        mountExhibitionHistory(){assert.fail('not a history test');}};
    new Function(...Object.keys(deps),source+'\nreturn mountExhibition;')(...Object.values(deps))(root);
    const state={...official,version:1,spot:39,clock:177};
    const session=(startX=35)=>({envelope:{session_id:'s'+startX,result_id:'r'+startX},setup:{mode:'running',exhibition:{startX,firstDownX:startX+10}},prepared_state:{...official,spot:startX-10},submit_url:`/exhibitions/3/arcade/sessions/s${startX}/submit`,cancel_url:`/exhibitions/3/arcade/sessions/s${startX}/cancel`});
    return {nodes,views,requests,recording,state,session,queue,get transport(){return transport;},get opened(){return opened;},
        click(key){return nodes[key].listeners.get('click')();},frame(){frame(16);}};
}
async function finishPlay(h){h.queue.push({session:h.session()});await h.click('reserve');h.click('start');h.frame();assert.ok(h.transport.pending.body);}

test('unreserved and recovered fields explain the next action instead of showing mysterious blank space',()=>{
    const h=harness();assert.equal(h.nodes.empty.hidden,false);assert.match(h.nodes.empty.textContent,/Reserve play/);
    const recovered=harness({pending:h.session()});
    assert.match(recovered.nodes.empty.textContent,/Cancel reserved play/);
});

test('accepted submission clears old field markers, retains exact replay, and reserves fresh geometry',async()=>{
    const h=harness();await finishPlay(h);const old=h.views[0];
    assert.equal(old.last.firstDownX,45);
    h.queue.push({state:h.state,available:true});await h.click('submit');
    assert.equal(old.disposed,1,'old LOS and first-down markers must not remain beneath the next-down HUD');
    assert.match(h.nodes.state.textContent,/Down 1 & 10 · Ball 39/);assert.equal(h.nodes.live.textContent,'');
    assert.equal(h.nodes.empty.hidden,false);assert.match(h.nodes.empty.textContent,/Reserve play/);
    assert.equal(h.nodes.replay.disabled,false);h.click('replay');assert.equal(h.opened,h.recording);
    h.queue.push({session:h.session(49)});await h.click('reserve');
    assert.equal(h.views[1].last.startX,49);assert.equal(h.views[1].last.firstDownX,59);assert.equal(h.nodes.empty.hidden,true);
});

for (const failure of [new Error('Connection lost'), {httpError:'Session rejected'}]) test(`failed submission (${failure.message ?? failure.httpError}) preserves field and exact retry until accepted`,async()=>{
    const h=harness();await finishPlay(h);const old=h.views[0],body=h.transport.pending.body;
    h.queue.push(failure);await h.click('submit');
    assert.equal(old.disposed,0);assert.equal(h.transport.pending.body,body);assert.equal(h.nodes.submit.disabled,false);
    assert.match(h.nodes.status.textContent,/Connection lost|Session rejected/);
    h.queue.push({state:h.state,available:true});await h.click('submit');
    assert.equal(h.requests[1].body,body);assert.equal(h.requests[2].body,body);assert.equal(old.disposed,1);
});

test('accepted drive-ending play replaces the old field with Coach continuation instructions',async()=>{
    const h=harness();await finishPlay(h);
    h.queue.push({state:{...h.state,possession:'away'},available:false});await h.click('submit');
    assert.equal(h.views[0].disposed,1);assert.match(h.nodes.empty.textContent,/Continue in Coach/);
    assert.equal(h.nodes.reserve.disabled,true);assert.equal(h.nodes.replay.disabled,false);
});

test('recovered finished recording explains retry and remains available for exact replay',()=>{
    const original=harness(),session=original.session();
    const body=JSON.stringify({envelope:session.envelope,recording:original.recording});
    const h=harness({stored:{session,body}});
    assert.match(h.nodes.empty.textContent,/Submit \/ retry same result/);assert.equal(h.transport.pending.body,body);
    assert.equal(h.nodes.submit.disabled,false);assert.equal(h.views.length,0);
    h.click('replay');assert.deepEqual(h.opened,original.recording);
});
