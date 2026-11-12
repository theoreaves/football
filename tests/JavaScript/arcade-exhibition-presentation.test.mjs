import {exhibitionReservation,exhibitionCanPass,exhibitionControlHint,exhibitionLiveLabel} from '../../resources/js/arcade/exhibition-play-selection.js';
import {requiredDecisionReady} from '../../resources/js/practice/play-mode-presentation.js';
import {createExhibitionFlowTransport, exhibitionStateLabel, exhibitionClockLabel, canonicalExhibitionNotices} from '../../resources/js/arcade/exhibition-flow.js';
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
function harness({pending=null,stored=null,flow=null,flowSaved=null,mountOptions={},terminalReason='tackle'}={}) {
    const nodes=Object.fromEntries(['reserve','start','pause','cancel','submit','replay','score','state','live','status','kind','tempo','coach','empty','preferences','side','defense','advance','fast','timeout','spike','retry-flow','flow-status','clock','notices'].map(key=>[key,node()]));
    nodes.kind.value='inside_run';nodes.tempo.value='normal';
    const official={version:0,possession:'home',phase:'scrimmage',status:'playing',quarter:1,clock:180,down:1,distance:10,spot:25};
    const root={dataset:{exhibition:JSON.stringify({flow,flowUrl:flow?'/exhibitions/3/arcade/flow':null,state:official,available:true,startUrl:'/exhibitions/3/arcade/sessions',csrf:'test',pendingSession:pending})},isConnected:true,
        querySelector(selector){return selector==='[data-arcade-field]'?host:nodes[selector.match(/data-exhibition-([^\]]+)/)[1]];}};
    const host={...node(),clientWidth:800,clientHeight:500};
    const queue=[],requests=[],views=[],recording={version:1,frames:[[0]],events:[],actors:[],result:{reason:terminalReason}};
    let frame,transport,opened,step,replayActive=false;
    const document=node(),window={...node(),location:{origin:'http://test',pathname:'/exhibitions/3/arcade',assign(url){this.destination=url;}},localStorage:{getItem(key){return key.startsWith('arcade-flow:')?flowSaved:stored?JSON.stringify(stored):null;},setItem(){},removeItem(){}}};
    const deps={exhibitionReservation,exhibitionCanPass,exhibitionControlHint,exhibitionLiveLabel,createExhibitionFlowTransport,exhibitionStateLabel,exhibitionClockLabel,canonicalExhibitionNotices,document,window,AbortController,ResizeObserver:class{observe(){}disconnect(){}},
        requestAnimationFrame(fn){frame=fn;return 1;},cancelAnimationFrame(){},
        async fetch(url,options){requests.push({url,body:options.body});const response=queue.shift();if(response instanceof Error)throw response;return {ok:!response?.httpError,status:response?.status || 200,json:async()=>response?.httpError?{message:response.httpError}:response};},
        createDrill(config){return {status:'ready',mode:'running',direction:1,startX:config.exhibition.startX,firstDownX:config.exhibition.firstDownX,runner:{x:config.exhibition.startX},elapsed:0};},
        createFullTeamPlay(config){return {...deps.createDrill(config),fullTeam:true,quarterback:{key:'offense_QB'},ball:{phase:'held',holderKey:'offense_QB'}};},stepFullTeamPlay(s){deps.stepDrill(s);},
        createPassingDrill(){assert.fail('not a passing test');},startDrill(s){s.status='running';},pauseDrill(s){if(s.status==='running')s.status='paused';},
        stepDrill(s){s.runner.x+=14;s.elapsed=terminalReason==='timeout'?20:2;s.status='finished';s.result={reason:terminalReason,yards:14};},stepPassingDrill(){},STEP_SECONDS:1/60,
        createFixedStepper(fn){step=fn;return {reset(){},advance(){step();}};},
        createDrillRecording(){return {capture(){},export(){return recording;}};},
        createArcadeInput(){return {sample(){return {};},clear(){},dispose(){}};},
        createArcadeView(host,config){const view={config,disposed:0,render(s){this.last=s;},resize(){},dispose(){this.disposed++;}};views.push(view);return view;},
        createReplayPanel(){return {get active(){return replayActive;},open(value){opened=value;},advance(){},dispose(){}};},
        createExhibitionTransport(options){transport=createExhibitionTransport(options);return transport;},canContinueExhibitionDrive,noSnapNotice,
        mountExhibitionHistory(){assert.fail('not a history test');}};
    const dispose=new Function(...Object.keys(deps),source+'\nreturn mountExhibition;')(...Object.values(deps))(root,mountOptions);
    const state={...official,version:1,spot:39,clock:177};
    const session=(startX=35)=>({envelope:{session_id:'s'+startX,result_id:'r'+startX},setup:{mode:'running',exhibition:{startX,firstDownX:startX+10}},prepared_state:{...official,spot:startX-10},submit_url:`/exhibitions/3/arcade/sessions/s${startX}/submit`,cancel_url:`/exhibitions/3/arcade/sessions/s${startX}/cancel`});
    return {dispose,window,nodes,views,requests,recording,state,session,queue,get transport(){return transport;},get opened(){return opened;},
        setReplayActive(value){replayActive=value;},click(key){return nodes[key].listeners.get('click')();},frame(){frame(16);}};
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

const flowState={enabled:true,revision:1,state_hash:'a'.repeat(64),human_side:'home',defense_mode:'watch',phase:'defense',can_advance:true,can_fast:true,can_timeout:true,can_spike:false};
test('complete-game flow progresses from opponent possession to next offense without permanent drive latch',async()=>{
    const h=harness({flow:flowState});
    assert.equal(h.requests.length,0);assert.equal(h.nodes.reserve.disabled,true);
    h.queue.push({state:{...h.state,possession:'home'},available:true,flow:{...flowState,phase:'offense',revision:2},plays:[{summary:'Opponent punt. Home ball.'}]});
    await h.click('fast');
    assert.equal(h.nodes.reserve.disabled,false);assert.match(h.nodes.notices.textContent,/Opponent punt/);
    const sent=JSON.parse(h.requests[0].body);assert.equal(sent.action,'fast');assert.equal(sent.state_hash,flowState.state_hash);
});
test('flow network failure blocks new play and retries identical command; watch navigates only committed response',async()=>{
    const h=harness({flow:flowState});h.queue.push(new Error('Lost response'));await h.click('advance');
    assert.equal(h.nodes.reserve.disabled,true);assert.equal(h.nodes.fast.disabled,true);assert.equal(h.nodes['retry-flow'].hidden,false);
    h.queue.push({flow:flowState,state:h.state,navigation_url:'/exhibitions/3?arcade=1&watch=1'});await h.click('retry-flow');
    assert.equal(h.requests[0].body,h.requests[1].body);assert.match(h.window.location.destination,/watch=1/);
});
test('recovered game decision does not auto-submit or permit a second outcome',()=>{
    const flowSaved=JSON.stringify({action:'fast',version:0,flow_revision:1,state_hash:flowState.state_hash,command_id:'00000000-0000-4000-8000-000000000001'});
    const h=harness({flow:flowState,flowSaved});assert.equal(h.requests.length,0);assert.equal(h.nodes['retry-flow'].hidden,false);assert.equal(h.nodes.advance.disabled,true);
});
test('required decisions and final whistle gate snaps but preserve replay controls',()=>{
    for(const phase of ['decision','special','final']){const h=harness({flow:{...flowState,phase,can_advance:false,required_decision:phase==='decision'?'penalty':null}});assert.equal(h.nodes.reserve.disabled,true);assert.match(h.nodes.empty.textContent,phase==='final'?/Final whistle/:/decision/);}
});

test('disabled flow explicitly enables chosen team/defense, then locks the human side',async()=>{
 const h=harness({flow:{...flowState,enabled:false,phase:'offense'}});
 assert.equal(h.nodes.reserve.disabled,true);assert.equal(h.nodes.side.disabled,false);
 h.nodes.side.value='away';h.nodes.defense.value='fast';
 h.queue.push({flow:{...flowState,human_side:'away',defense_mode:'fast'},state:h.state,available:false});
 await h.click('preferences');const sent=JSON.parse(h.requests[0].body);
 assert.equal(sent.human_side,'away');assert.equal(sent.defense_mode,'fast');assert.equal(h.nodes.side.disabled,true);
});
test('definitive stale-state rejection requires reload; unknown outcome remains retryable',async()=>{
 const h=harness({flow:flowState});h.queue.push({httpError:'State changed; reload',status:409});await h.click('fast');
 assert.equal(h.nodes['retry-flow'].hidden,true);assert.equal(h.nodes.fast.disabled,true);assert.equal(h.nodes.reserve.disabled,true);assert.match(h.nodes['flow-status'].textContent,/Reload/);
});
test('reserved live play blocks flow mutations, timeout and spike',()=>{
 const initial=harness();const h=harness({flow:{...flowState,phase:'offense',can_spike:true},pending:initial.session()});
 for(const name of ['preferences','advance','fast','timeout','spike'])assert.equal(h.nodes[name].disabled,true);
});

test('shared nonmodal replay blocks every mutation and keeps the exact pending result',async()=>{
 const h=harness({flow:{...flowState,phase:'offense'}});await finishPlay(h);const body=h.transport.pending.body,count=h.requests.length;
 h.setReplayActive(true);h.frame();for(const key of ['reserve','cancel','submit','preferences','fast','timeout','spike'])assert.equal(h.nodes[key].disabled,true,key);
 for(const key of ['reserve','cancel','submit','fast'])await h.click(key);
 assert.equal(h.requests.length,count);assert.equal(h.transport.pending.body,body);h.setReplayActive(false);h.frame();assert.equal(h.nodes.submit.disabled,false);
});

test('late reserve response after disposal preserves recovery but never mounts a destroyed host',async()=>{
 const h=harness();let resolve;h.queue.push(new Promise(done=>{resolve=done;}));const reservation=h.click('reserve');h.dispose();resolve({session:h.session()});await reservation;assert.equal(h.views.length,0);assert.ok(h.transport.pending);assert.equal(h.transport.pending.session.envelope.session_id,h.session().envelope.session_id);
});


for(const action of ['penalty','coin','ot_call','ot_choice'])test(`matching ${action} decision commits while recorded presentation blocks live input`,async()=>{
    let dialogOpen=true,blocked=false,prepared=0,declined=0;
    const flow={...flowState,phase:'decision',required_decision:action};
    const h=harness({flow,mountOptions:{inputEnabled:()=>false,flowEnabled:name=>requiredDecisionReady({action:name,requiredDecision:action,dialogOpen,blocked})}});
    h.queue.push({flow:{...flowState,required_decision:null},state:h.state});
    await h.dispose.flowAction('fast',{}, {onDeclined:()=>declined++});assert.equal(h.requests.length,0);
    dialogOpen=false;await h.dispose.flowAction(action,{}, {onDeclined:()=>declined++});assert.equal(h.requests.length,0);
    dialogOpen=true;blocked=true;await h.dispose.flowAction(action,{}, {onDeclined:()=>declined++});assert.equal(h.requests.length,0);
    blocked=false;await h.dispose.flowAction(action,{choice:'accept'},{onPrepared:()=>prepared++});
    assert.equal(h.requests.length,1);assert.equal(prepared,1);assert.equal(declined,3);assert.equal(JSON.parse(h.requests[0].body).action,action);
});
test('required penalty unknown outcome retains exact choice and retry works despite blocked live input',async()=>{
    let prepared=0,declined=0;
    const flow={...flowState,phase:'decision',required_decision:'penalty'};
    const h=harness({flow,mountOptions:{inputEnabled:()=>false,flowEnabled:action=>requiredDecisionReady({action,requiredDecision:'penalty',dialogOpen:true})}});
    h.queue.push(new Error('Lost response'));
    await h.dispose.flowAction('penalty',{choice:'accept'},{onPrepared:()=>prepared++});assert.equal(h.requests.length,1);assert.equal(h.dispose.pendingFlow,true);
    await h.dispose.flowAction('penalty',{choice:'decline'},{onDeclined:()=>declined++});assert.equal(declined,1);assert.equal(h.requests.length,1);
    h.queue.push({flow:{...flowState,required_decision:null},state:h.state});await h.dispose.flowAction(null);
    assert.equal(h.requests.length,2);assert.equal(h.requests[0].body,h.requests[1].body);assert.equal(prepared,1);assert.equal(h.dispose.pendingFlow,false);
});
test('required decision preparation failure reports failure rather than an accepted saving state',async()=>{
    let prepared=0,errors=0;const h=harness({flow:{...flowState,state_hash:'bad'},mountOptions:{inputEnabled:()=>false,flowEnabled:()=>true}});
    await h.dispose.flowAction('penalty',{choice:'accept'},{onPrepared:()=>prepared++,onPrepareError:()=>errors++});
    assert.equal(prepared,0);assert.equal(errors,1);assert.equal(h.requests.length,0);assert.equal(h.dispose.pendingFlow,false);
});

test('five-call reservation opts in to v2 while setup controls legacy versus full-team dispatch',async()=>{
 const h=harness();h.nodes.kind.value='outside_run';const session=h.session();session.setup.fullTeam=true;
 h.queue.push({session});await h.click('reserve');
 assert.deepEqual(JSON.parse(h.requests[0].body),{version:0,kind:'run',call:'outside_run',tempo:'normal',contract_version:'live-recording-2'});
 assert.equal(h.views[0].last.fullTeam,true);assert.match(h.nodes.status.textContent,/Automatic handoff/);
 h.click('start');h.frame();assert.ok(h.transport.pending.body);
});


test('local recording timeout keeps submission disabled and consistently offers cancellation',async()=>{
    const h=harness({terminalReason:'timeout'}),session=h.session();session.setup.fullTeam=true;
    h.queue.push({session});await h.click('reserve');h.click('start');h.frame();
    assert.equal(h.transport.pending.body,null);assert.equal(h.nodes.submit.disabled,true);
    assert.equal(h.nodes.cancel.disabled,false);assert.equal(h.requests.length,1);
    for(const key of ['status','live']){
        assert.match(h.nodes[key].textContent,/No football result was saved/);
        assert.match(h.nodes[key].textContent,/Cancel/);
        assert.doesNotMatch(h.nodes[key].textContent,/submit the recorded result/);
    }
    assert.throws(()=>h.transport.prepare(h.recording),/cannot be submitted/);
    h.frame();assert.equal(h.nodes.submit.disabled,true);assert.equal(h.requests.length,1);
    h.queue.push({cancelled:true});await h.click('cancel');
    assert.equal(h.transport.pending,null);assert.equal(h.requests[1].url,session.cancel_url);
    assert.equal(h.nodes.reserve.disabled,false);
});
