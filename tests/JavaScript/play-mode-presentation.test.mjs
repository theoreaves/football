import {defenseModeNotice} from '../../resources/js/practice/play-mode-presentation.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import {claimPlayOpening,watchMayAdvance,isPlayFootballForm,mountPlayPresentation,serializePlayForm,shouldMountCoachWizard} from '../../resources/js/practice/play-mode-presentation.js';
const memory=()=>{const values=new Map();return{getItem:key=>values.get(key),setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};};
const opening={flow:{enabled:true,opening_enabled:true,intro_seen:false},version:0,key:'game:play'};
test('new Play opening claims before playback; reload, Skip and closed-tab resume cannot repeat it',()=>{
    const storage=memory();assert.equal(claimPlayOpening({...opening,storage}),true);
    for(const resume of ['mid-intro reload','Skip','closed-tab resume']) assert.equal(claimPlayOpening({...opening,storage}),false,resume);
});
test('old games, recorded replay, completed intro and ordinary Coach never claim a Play opening',()=>{
    for(const change of [{flow:null},{flow:{enabled:true}},{flow:{...opening.flow,intro_seen:true}},{version:1}])
        assert.equal(claimPlayOpening({...opening,...change,storage:memory()}),false);
});
test('missing or denied durable storage skips Play opening rather than duplicating audio',()=>{
    assert.equal(claimPlayOpening(opening),false);
    assert.equal(claimPlayOpening({...opening,storage:{getItem(){throw Error('denied')}}}),false);
    assert.equal(claimPlayOpening({...opening,storage:{getItem(){},setItem(){throw Error('quota')}}}),false);
});
const ready={flow:{enabled:true,defense_mode:'watch',can_advance:true},recorded:true,completed:true,decisionWindow:true,ready:true,visible:true,busy:false,dialogOpen:false};
test('Watch only advances after actual recorded animation, result and notices finish',()=>{
    assert.equal(watchMayAdvance(ready),true);
    for(const change of [{recorded:false},{completed:false},{ready:false},{visible:false},{busy:true},{dialogOpen:true},{flow:{...ready.flow,required_decision:'penalty'}},{flow:{...ready.flow,can_advance:false}},{flow:{...ready.flow,defense_mode:'coach'}}])
        assert.equal(watchMayAdvance({...ready,...change}),false);
});
test('only game play forms are intercepted, never highlight/settings/dialog forms',()=>{
    const base='http://localhost/exhibitions/3';
    const form=(action,method='post')=>({method,getAttribute:()=>action});
    assert.equal(isPlayFootballForm(form('/exhibitions/3/play'),base),true);
    for(const path of ['/exhibitions/3/highlight','/exhibitions/3/team-control','/exhibitions/4/play'])assert.equal(isPlayFootballForm(form(path),base),false);
    assert.equal(isPlayFootballForm(form('/exhibitions/3/play','dialog'),base),false);
});
test('Watch to Fast sends one durable command, never reruns the recorded result',async()=>{
    const originals={window:globalThis.window,localStorage:globalThis.localStorage,fetch:globalThis.fetch};
    const events=new Map(), fast={dataset:{playFlowAction:'fast'},addEventListener:(name,fn)=>events.set(name,fn),removeEventListener(){}};
    const status={textContent:''},retry={hidden:true,addEventListener(){},removeEventListener(){}};
    const navigation=[],requests=[];
    globalThis.window={location:{origin:'http://localhost',href:'http://localhost/exhibitions/3?arcade=1&watch=1',assign:url=>navigation.push(url)}};
    globalThis.localStorage=memory();
    globalThis.fetch=async(url,options)=>{requests.push(JSON.parse(options.body));return{ok:true,json:async()=>({navigation_url:'/exhibitions/3/arcade'})};};
    const root={dataset:{arcadeFlowUrl:'/exhibitions/3/arcade/flow',playNumber:'4'},
        querySelector:selector=>selector==='[data-play-flow-status]'?status:selector==='[data-play-flow-retry]'?retry:selector==='[name="_token"]'?{value:'csrf'}:null,
        querySelectorAll:()=>[fast],addEventListener(){},removeEventListener(){}};
    try{
        const bridge=mountPlayPresentation(root,{...ready.flow,revision:7,state_hash:'a'.repeat(64)});
        assert.equal(requests.length,0,'mount/reload performs no mutation');
        bridge.tick({...ready,ready:false,completed:false});events.get('click')();assert.equal(requests.length,0,'cannot switch during animation');
        // Hidden tab prevents automatic Watch while explicitly choosing Fast.
        bridge.tick({...ready,visible:false});events.get('click')();events.get('click')();
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(requests.length,1);assert.equal(requests[0].action,'fast');assert.equal(requests[0].version,4);
        assert.equal(requests[0].state_hash,'a'.repeat(64));assert.equal(requests[0].flow_revision,7);
        assert.deepEqual(navigation,['/exhibitions/3/arcade']);bridge.dispose();
    }finally{Object.assign(globalThis,originals);}
});
test('Play form bridge preserves football decisions and scopes exactly one command per submit',async()=>{
    const originals={window:globalThis.window,localStorage:globalThis.localStorage,fetch:globalThis.fetch,FormData:globalThis.FormData};
    const requests=[];
    globalThis.window={location:{origin:'http://localhost',href:'http://localhost/exhibitions/3?arcade=1',assign(){}}};
    globalThis.localStorage=memory();
    globalThis.FormData=class extends Map {constructor(form){super(Object.entries(form.fields));}};
    globalThis.fetch=async(url,options)=>{requests.push(JSON.parse(options.body));return{ok:true,json:async()=>({navigation_url:'/exhibitions/3?arcade=1'})};};
    try{
        for(const action of ['timeout','lineup','coin','penalty','ot_call','ot_choice','play']){
            let listener;
            const root={dataset:{arcadeFlowUrl:'/exhibitions/3/arcade/flow',playNumber:'4'},querySelector:selector=>selector==='[name="_token"]'?{value:'csrf'}:null,querySelectorAll:()=>[],
                addEventListener:(name,callback,capture)=>{assert.equal(capture,undefined);listener=callback;},removeEventListener(){}};
            const bridge=mountPlayPresentation(root,{enabled:true,revision:7,state_hash:'b'.repeat(64)});
            const form={method:'post',getAttribute:()=>'/exhibitions/3/play',fields:{_token:'csrf',action,team:'home',role:'RB',player:'7',choice:'receive','one_play[home][RB]':'42','team_play[home]':'7'}};
            let prevented=0,stopped=0;
            const event={target:form,submitter:{name:'decision',value:'accept'},preventDefault(){prevented++;},stopImmediatePropagation(){stopped++;}};
            listener({...event,defaultPrevented:true});assert.equal(requests.length,['timeout','lineup','coin','penalty','ot_call','ot_choice','play'].indexOf(action),'existing validation guard prevents a request');
            listener(event);listener(event);
            await new Promise(resolve=>setImmediate(resolve));
            const command=requests.at(-1);assert.equal(command.action,action);assert.equal(command.team,'home');assert.equal(command.player,'7');assert.equal(command.decision,'accept');assert.equal(command._token,undefined);assert.deepEqual(command.one_play,{home:{RB:'42'}});assert.deepEqual(command.team_play,{home:'7'});
            assert.equal(prevented,2);assert.equal(stopped,3);bridge.dispose();
        }
        assert.equal(requests.length,7);
    }finally{Object.assign(globalThis,originals);}
});

test('Coach bracketed personnel and team-play fields retain nested structure and Automatic values',()=>{
    assert.deepEqual(serializePlayForm([
        ['_token','csrf'], ['action','play'], ['call','pass'], ['decision','accept'],
        ['one_play[home][RB]','42'], ['one_play[away][CB1]',''], ['one_play[home][WR1]',null],
        ['team_play[home]','7'], ['team_play[away]',''],
    ]),{action:'play',call:'pass',decision:'accept',one_play:{home:{RB:'42',WR1:null},away:{CB1:''}},team_play:{home:'7',away:''}});
    assert.deepEqual(serializePlayForm([['call','run'],['call','pass']]),{call:'pass'});
});
test('malformed structure and prototype keys cannot alter JSON object prototypes or silently drop choices',()=>{
    for(const name of ['__proto__','constructor','prototype','one_play','team_play','one_play[home][__proto__]','one_play[home][constructor]','one_play[home][prototype]','one_play[other][RB]','one_play[home][RB][id]','team_play[__proto__]','unknown[home]'])
        assert.throws(()=>serializePlayForm([[name,'polluted']]),/form field/);
    assert.equal({}.polluted,undefined);
    assert.throws(()=>serializePlayForm([['call',{}]]),/form value/);
});

test('Watch never mounts the legacy CPU defense picker; Coach and human special-team calls remain unchanged',()=>{
    assert.equal(shouldMountCoachWizard(null),true);
    assert.equal(shouldMountCoachWizard({enabled:false,defense_mode:'watch'}),true);
    assert.equal(shouldMountCoachWizard({enabled:true,defense_mode:'coach',phase:'defense'}),true);
    assert.equal(shouldMountCoachWizard({enabled:true,defense_mode:'watch',phase:'defense',can_advance:true}),false);
    assert.equal(shouldMountCoachWizard({enabled:true,defense_mode:'watch',phase:'offense'}),true);
    assert.equal(shouldMountCoachWizard({enabled:true,defense_mode:'watch',phase:'special',can_advance:true}),false);
    assert.equal(shouldMountCoachWizard({enabled:true,defense_mode:'watch',phase:'special',can_advance:false}),true);
    assert.equal(shouldMountCoachWizard({enabled:true,defense_mode:'watch',phase:'decision',required_decision:'special',can_advance:false}),true);
});
test('completed Watch result offers a real Fast Sim click before huddle auto-advance; pause protects the window and pending retry',async()=>{
    const originals={window:globalThis.window,localStorage:globalThis.localStorage,fetch:globalThis.fetch};
    const button=dataset=>({dataset,disabled:false,hidden:false,handlers:{},addEventListener(name,fn){this.handlers[name]=fn;},removeEventListener(){},setAttribute(){},click(){assert.equal(this.disabled,false,'user can click without forcing a disabled control');this.handlers.click();}});
    const fast=button({playFlowAction:'fast'}),pause=button({}),retry=button({}),status={textContent:''};
    const requests=[];
    globalThis.window={location:{origin:'http://localhost',href:'http://localhost/exhibitions/3?arcade=1&watch=1',assign(){}}};
    globalThis.localStorage=memory();
    let confirm=false;
    globalThis.fetch=async(url,options)=>{requests.push(options.body);if(!confirm)throw Error('Connection lost');return{ok:true,json:async()=>({navigation_url:'/exhibitions/3/arcade'})};};
    const root={dataset:{arcadeFlowUrl:'/exhibitions/3/arcade/flow',playNumber:'4'},querySelector:selector=>({'[data-play-flow-status]':status,'[data-play-flow-retry]':retry,'[data-play-watch-pause]':pause,'[name="_token"]':{value:'csrf'}}[selector]),querySelectorAll:()=>[fast],addEventListener(){},removeEventListener(){}};
    try{
        const bridge=mountPlayPresentation(root,{...ready.flow,phase:'defense',revision:7,state_hash:'a'.repeat(64)});
        bridge.tick({...ready,completed:false,ready:false});assert.equal(fast.disabled,true);
        pause.click();assert.equal(requests.length,0,'pausing is presentation-only');
        bridge.tick({...ready,completed:true,ready:false});assert.equal(fast.disabled,false,'completed result offers mode change before substitution completes');
        bridge.tick(ready);assert.equal(requests.length,0,'paused Watch cannot auto-advance at huddle');
        fast.click();assert.equal(fast.disabled,true);await new Promise(resolve=>setImmediate(resolve));
        bridge.tick(ready);assert.equal(fast.disabled,true);assert.equal(pause.disabled,true);assert.equal(retry.hidden,false);
        assert.equal(requests.length,1);assert.equal(JSON.parse(requests[0]).action,'fast');
        confirm=true;retry.click();await new Promise(resolve=>setImmediate(resolve));
        assert.equal(requests.length,2);assert.equal(requests[0],requests[1],'retry keeps exact command rather than generating another outcome');bridge.dispose();
    }finally{Object.assign(globalThis,originals);}
});


test('saved Fast preference never mounts opponent Coach picker but does not bypass the human post-PAT kickoff',()=>{
    const kickoff={enabled:true,human_side:'home',defense_mode:'fast',phase:'decision',required_decision:'special',can_advance:false,can_fast:false};
    assert.equal(shouldMountCoachWizard(kickoff),true,'home7away0 after PAT still needs its legal kickoff choice');
    assert.equal(watchMayAdvance({flow:kickoff,recorded:true,completed:true,ready:true,visible:true,busy:false,dialogOpen:false}),false);
    for(const defense_mode of ['watch','fast']){
        assert.equal(shouldMountCoachWizard({...kickoff,defense_mode}),true);
        assert.equal(shouldMountCoachWizard({...kickoff,defense_mode,phase:'defense',required_decision:null,can_advance:true,can_fast:true}),false,'delegated opponent scrimmage does not ask for Coach defense');
        assert.equal(shouldMountCoachWizard({...kickoff,defense_mode,phase:'special',required_decision:null,can_advance:true,can_fast:true}),false,'delegated opponent special teams does not ask for Coach defense');
        assert.equal(shouldMountCoachWizard({...kickoff,defense_mode,phase:'offense',required_decision:null}),true);
    }
    assert.equal(shouldMountCoachWizard({...kickoff,defense_mode:'coach',phase:'defense',required_decision:null,can_advance:true}),true);
    assert.equal(shouldMountCoachWizard(null),true);assert.equal(shouldMountCoachWizard({...kickoff,enabled:false}),true);
});

test('delegated mode feedback distinguishes saved preference from pending human kickoff and explicit simulation action',()=>{
    for(const defense_mode of ['watch','fast']){
        const flow={enabled:true,defense_mode,required_decision:'special',can_advance:false};
        assert.match(defenseModeNotice(flow,{phase:'kickoff'}),/Your kickoff decision is still required/);
        assert.match(defenseModeNotice(flow,{phase:'extra_point'}),/extra-point \/ two-point/);
        assert.match(defenseModeNotice({...flow,required_decision:'penalty'},{}),/required football decision/);
        assert.match(defenseModeNotice({...flow,required_decision:null,can_advance:true},{}),defense_mode==='fast'?/Use Fast Sim to next decision/:/Use Watch next play/);
    }
    assert.equal(defenseModeNotice({enabled:true,defense_mode:'coach'},{}),'');
    assert.equal(defenseModeNotice(null,{}),'');
});

test('manual human fourth-down Coach picker survives every defense preference without automatic offensive opening',async()=>{
    const {shouldAutoOpenCoachWizard}=await import('../../resources/js/practice/play-mode-presentation.js');
    for(const defense_mode of ['coach','watch','fast']){
        const flow={enabled:true,defense_mode,phase:'offense',required_decision:null,can_advance:false};
        assert.equal(shouldMountCoachWizard(flow),true,'Continue in Coach must retain Punt/FG choice');
        assert.equal(shouldAutoOpenCoachWizard(flow,{shared:true}),false,'Arcade possession handoff must not auto-open it');
        assert.equal(shouldAutoOpenCoachWizard(flow,{shared:false}),true,'standalone Coach keeps automatic picker');
    }
});
