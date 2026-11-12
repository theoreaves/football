import {createExhibitionFlowTransport} from '../../resources/js/arcade/exhibition-flow.js';
import {serializePlayForm} from '../../resources/js/practice/play-mode-presentation.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import {queuedPlayCall,acceptQueuedPlay,rejectQueuedPlay} from '../../resources/js/practice/queued-play-call.js';
import {unitExchange,playerKey} from '../../resources/js/practice/unit-exchange.js';

function fixture(callback=()=>{}) {
    const posted=[],listeners=new Map();
    const form={dataset:{},values:[['_token','csrf'],['version','8'],['call','power'],['team_play[home]','heavy-id'],['one_play[home][FB]','42']],
        getAttribute:()=>'/exhibitions/1/play',addEventListener(type,fn){listeners.set(type,fn);},removeEventListener(type){listeners.delete(type);}};
    const root={isConnected:true,append(){}};
    form.ownerDocument={createElement(tag){
        if(tag==='input')return {};
        return {children:[],setAttribute(name,value){this[name]=value;},append(input){this.children.push(input);},requestSubmit(){posted.push(this.children.map(input=>[input.name,input.value]));callback(this);},remove(){}};
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


test('shared handoff retains frozen selection while presentation declines then accepts exactly once',()=>withFormData(()=>{
    let ready=false,accept=false,released=0;const f=fixture(proxy=>{if(accept)acceptQueuedPlay(proxy);});
    const queue=queuedPlayCall(f.form,f.root,{ready:()=>ready,requireAcceptance:true,onRelease:()=>released++});
    f.submit(f.event());f.form.values[2][1]='deep_pass';queue.tick();assert.equal(f.posted.length,0);
    ready=true;queue.tick();assert.equal(released,0); // old failure: this consumed the choice
    accept=true;queue.tick();queue.tick();assert.equal(released,1);assert.equal(f.posted.length,2);
    assert.deepEqual(f.posted[0],f.posted[1]);assert.equal(f.posted[1][2][1],'power');
}));
test('ready shared calls still capture before acknowledgement; delayed ack and disposal are safe',()=>withFormData(()=>{
    let proxy,released=0;const f=fixture(value=>{proxy=value;});
    const queue=queuedPlayCall(f.form,f.root,{ready:()=>true,requireAcceptance:true,onRelease:()=>released++});
    const event=f.event();f.submit(event);assert.equal(event.prevented,true);queue.tick();assert.equal(released,0);
    acceptQueuedPlay(proxy);acceptQueuedPlay(proxy);queue.tick();assert.equal(released,1);assert.equal(f.posted.length,1);
    let late;const other=fixture(value=>{late=value;});const disposed=queuedPlayCall(other.form,other.root,{ready:()=>true,requireAcceptance:true,onRelease:()=>released++});other.submit(other.event());disposed.tick();disposed.dispose();acceptQueuedPlay(late);assert.equal(released,1);
}));
test('permanent shared preparation failure retains choice and stops frame-by-frame submission',()=>withFormData(()=>{
    let failures=0,released=0;const f=fixture(proxy=>rejectQueuedPlay(proxy,new Error('Storage unavailable')));
    const queue=queuedPlayCall(f.form,f.root,{ready:()=>true,requireAcceptance:true,onRelease:()=>released++,onFailure:()=>failures++});
    f.submit(f.event());queue.tick();queue.tick();queue.tick();assert.equal(f.posted.length,1);assert.equal(failures,1);assert.equal(released,0);assert.equal(f.form.dataset.playPending,'true');
}));


test('three consecutive shared calls survive changing presentation gates and prepare once per replaced queue',async()=>{
    const original=globalThis.FormData;
    globalThis.FormData=class extends Array {constructor(form){super(...form.values.map(pair=>[...pair]));}append(key,value){this.push([key,value]);}};
    try {
        const storageValues=new Map(),prepared=[],requests=[],retired=[];
        const storage={getItem:key=>storageValues.get(key),setItem:(key,value)=>storageValues.set(key,value),removeItem:key=>storageValues.delete(key)};
        let nextId=0;
        const transport=createExhibitionFlowTransport({url:'/exhibitions/15/arcade/flow',origin:'http://localhost',storage,key:'test-flow',
            idFactory:()=>`00000000-0000-4000-8000-${String(++nextId).padStart(12,'0')}`,
            request:async(url,body)=>{requests.push(JSON.parse(body));return {accepted:true};}});
        const orders=[['recorded','dialog','camera','staging'],['staging','recorded','camera','dialog'],['dialog','camera','staging','recorded']];
        for(let play=0;play<3;play++){
            const gates={recorded:true,dialog:true,camera:true,staging:true};let receiverBusy=true,released=0,lastProxy;
            const chosen=['zone','man_to_man','run_stop'][play];
            const f=fixture(proxy=>{
                lastProxy=proxy;
                if(receiverBusy)return; // late parent guard decline must not consume selection
                const fields=serializePlayForm(proxy.children.map(input=>[input.name,input.value]));
                transport.prepare({...fields,action:'play',version:play+1,flow_revision:play+1,state_hash:'a'.repeat(64)});
                prepared.push(JSON.parse(transport.pending.body));acceptQueuedPlay(proxy);
            });
            f.form.values=[['_token','csrf'],['version',String(play+1)],['defense',chosen],['team_play[home]',`package-${play}`],['one_play[home][LB1]',String(40+play)]];
            const queue=queuedPlayCall(f.form,f.root,{ready:()=>!Object.values(gates).some(Boolean),requireAcceptance:true,onRelease:()=>released++});
            f.submit(f.event());f.form.values[2][1]='changed-after-selection';f.form.values[4][1]='999';
            for(const gate of orders[play]){
                queue.tick();assert.equal(f.posted.length,0,`play ${play+1} must wait for ${gate}`);
                gates[gate]=false;
            }
            queue.tick();assert.equal(f.posted.length,1);assert.equal(released,0);assert.equal(prepared.length,play);
            receiverBusy=false;queue.tick();queue.tick();f.submit(f.event());
            assert.equal(released,1);assert.equal(prepared.length,play+1);assert.equal(f.posted.length,2);
            assert.deepEqual(f.posted[0],f.posted[1]);assert.equal(prepared[play].defense,chosen);
            assert.equal(prepared[play].one_play.home.LB1,String(40+play));assert.equal(prepared[play].team_play.home,`package-${play}`);
            assert.equal(storageValues.size,1,'transport owns durable retry before queue disposal');
            queue.dispose();f.root.isConnected=false;retired.push({queue,proxy:lastProxy});
            await transport.submit();assert.equal(storageValues.size,0);assert.equal(transport.pending,null);
            for(const old of retired){old.queue.tick();acceptQueuedPlay(old.proxy);}
            assert.equal(released,1);assert.equal(prepared.length,play+1);
        }
        assert.equal(requests.length,3);assert.equal(new Set(requests.map(body=>body.command_id)).size,3);
        assert.deepEqual(requests.map(body=>body.version),[1,2,3]);
    } finally {globalThis.FormData=original;}
});
