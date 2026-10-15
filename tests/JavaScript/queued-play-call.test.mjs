import test from 'node:test';
import assert from 'node:assert/strict';
import {queuedPlayCall} from '../../resources/js/practice/queued-play-call.js';
import {unitExchange,playerKey} from '../../resources/js/practice/unit-exchange.js';

function fixture(callback=()=>{}) {
    const posted=[],listeners=new Map();
    const form={dataset:{},values:[['_token','csrf'],['version','8'],['call','power'],['team_play[home]','heavy-id'],['one_play[home][FB]','42']],
        getAttribute:()=>'/exhibitions/1/play',addEventListener(type,fn){listeners.set(type,fn);},removeEventListener(type){listeners.delete(type);}};
    const root={isConnected:true,append(){}};
    form.ownerDocument={createElement(tag){
        if(tag==='input')return {};
        return {children:[],setAttribute(name,value){this[name]=value;},append(input){this.children.push(input);},requestSubmit(){posted.push(this.children.map(input=>[input.name,input.value]));callback();},remove(){}};
    }};
    const event=()=>({submitter:{},preventDefault(){this.prevented=true;},stopImmediatePropagation(){this.stopped=true;}});
    return {form,root,posted,event,submit(event){listeners.get('submit')?.(event);}};
}

function withFormData(run) {
    const original=globalThis.FormData;
    globalThis.FormData=class extends Array {constructor(form){super(...form.values.map(pair=>[...pair]));}append(key,value){this.push([key,value]);}};
    try {run();} finally {globalThis.FormData=original;}
}

test('early call freezes the full selected payload and submits exactly once after the exchange',()=>withFormData(()=>{
    const f=fixture();let ready=false,queued=0,released=0;
    const queue=queuedPlayCall(f.form,f.root,{ready:()=>ready,onQueued:()=>queued++,onRelease:()=>released++});
    const e=f.event();f.submit(e);
    assert.equal(e.prevented,true);assert.equal(e.stopped,true);assert.equal(queued,1);
    assert.equal(f.form.dataset.playPending,'true');assert.equal(f.form.inert,true);
    f.form.values[2][1]='deep_pass';
    f.submit(f.event());queue.tick();assert.equal(f.posted.length,0);assert.equal(queued,1);
    ready=true;queue.tick();queue.tick();f.submit(f.event());
    assert.equal(f.posted.length,1);assert.equal(released,1);
    assert.deepEqual(f.posted[0],[['_token','csrf'],['version','8'],['call','power'],['team_play[home]','heavy-id'],['one_play[home][FB]','42']]);
}));

test('calls with ready personnel follow normal submission while canceled or detached queues never post',()=>withFormData(()=>{
    const immediate=fixture();const q=queuedPlayCall(immediate.form,immediate.root,{ready:()=>true});
    const e=immediate.event();immediate.submit(e);assert.equal(e.prevented,undefined);assert.equal(immediate.form.dataset.playPending,undefined);q.tick();assert.equal(immediate.posted.length,0);
    for(const mode of ['disposed','detached']) {
        const f=fixture();let ready=false;const pending=queuedPlayCall(f.form,f.root,{ready:()=>ready});
        f.submit(f.event());
        if(mode==='disposed')pending.dispose();else f.root.isConnected=false;
        ready=true;pending.tick();assert.equal(f.posted.length,0);
    }
}));

test('old exchange completes before release and a newly selected package starts from the arrived players',()=>withFormData(()=>{
    const old={players:[{id:1,side:'home',team:'offense',role:'RB',x:50,z:27},{id:2,side:'away',team:'defense',role:'CB1',x:53,z:11}],ball:{x:50,y:0,z:27}};
    const standard={players:[{id:3,side:'home',team:'offense',role:'RB',x:45,z:27},{id:4,side:'away',team:'defense',role:'CB1',x:53,z:11}],ball:old.ball};
    const chosen={...standard,players:[{...standard.players[0],id:5},standard.players[1]]};
    const roster={home:[{id:1},{id:3},{id:5}],away:[{id:2},{id:4}]};
    const exchange=unitExchange(old,standard,50,'home',1,roster);let seconds=2,nextExchange;
    const f=fixture();const queue=queuedPlayCall(f.form,f.root,{ready:()=>seconds>=exchange.duration,onRelease:()=>{
        const arrived=exchange.sample(seconds);
        assert.ok(arrived.complete);assert.ok(arrived.outgoing.every(player=>!player.visible));
        nextExchange=unitExchange({players:arrived.incoming,ball:old.ball},chosen,50,'home',1,roster,{partial:true});
        const staying=nextExchange.sample(0).incoming.find(player=>player.id===4);
        const prior=arrived.incoming.find(player=>player.id===4);
        assert.ok(Math.hypot(staying.x-prior.x,staying.z-prior.z)<1e-9);
    }});
    f.submit(f.event());seconds=exchange.duration-.01;queue.tick();assert.equal(f.posted.length,0);
    seconds=exchange.duration;queue.tick();assert.equal(f.posted.length,1);
    for(let t=0;t<=nextExchange.duration;t+=.02) {
        const frame=nextExchange.sample(t),ids=[...frame.outgoing,...frame.incoming].filter(player=>player.visible).map(playerKey);
        assert.equal(new Set(ids).size,ids.length);
    }
    assert.deepEqual(nextExchange.sample(nextExchange.duration).incoming.map(player=>player.id),[5,4]);
}));
