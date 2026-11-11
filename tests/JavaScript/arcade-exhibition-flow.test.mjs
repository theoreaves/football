import test from 'node:test';
import assert from 'node:assert/strict';
import {createExhibitionFlowTransport, newFlowCommandId, exhibitionStateLabel, exhibitionClockLabel, canonicalExhibitionNotices} from '../../resources/js/arcade/exhibition-flow.js';
const id='00000000-0000-4000-8000-000000000001';
const payload={action:'fast',version:8,flow_revision:3,state_hash:'a'.repeat(64)};
function harness(request=async()=>({state:{version:9}}), saved=null) {
    const store=new Map(saved?[['flow',saved]]:[]),sent=[];
    const storage={getItem:k=>store.get(k),setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)};
    const options={url:'/exhibitions/3/arcade/flow',origin:'http://football.test',key:'flow',storage,idFactory:()=>id,request:async(url,body)=>{sent.push({url,body});return request(url,body);}};
    return {options,store,sent,transport:createExhibitionFlowTransport(options)};
}
test('flow command retries and reloads use the same exact body/id; no work starts on construction',async()=>{
    let fail=true;
    const h=harness(async()=>{if(fail)throw new Error('lost response');return {state:{version:9}};});
    assert.equal(h.sent.length,0);const body=h.transport.prepare(payload);
    assert.equal(JSON.parse(body).command_id,id);await assert.rejects(h.transport.submit(),/lost response/);
    assert.equal(h.transport.pending.body,body);assert.throws(()=>h.transport.prepare({...payload,action:'timeout'}),/unconfirmed/);
    const reload=createExhibitionFlowTransport(h.options);assert.equal(reload.pending.body,body);
    fail=false;await reload.submit();assert.equal(h.sent[0].body,h.sent[1].body);assert.equal(reload.pending,null);assert.equal(h.store.size,0);
});
test('definitive rejection can be discarded, while unknown errors retain retry evidence',()=>{
    const h=harness();h.transport.prepare(payload);
    assert.equal(h.transport.reject(new Error('offline')),false);assert.ok(h.transport.pending);
    assert.equal(h.transport.reject({status:500}),false);assert.equal(h.transport.reject({status:409}),true);assert.equal(h.transport.pending,null);
});
test('flow transport validates restored commands/endpoints and preserves in-memory retry without storage',async()=>{
    for(const body of ['bad','{}',JSON.stringify({...payload,command_id:'bad'})]) assert.equal(harness(undefined,body).transport.pending,null);
    for(const url of ['https://evil.test/exhibitions/3/arcade/flow','/exhibitions/3/arcade/flow?x=1','/elsewhere','http://user:password@football.test/exhibitions/3/arcade/flow']) assert.throws(()=>createExhibitionFlowTransport({...harness().options,url}),/Invalid/);
    const warnings=[],h=harness();const t=createExhibitionFlowTransport({...h.options,storage:null,onWarning:m=>warnings.push(m)});
    const body=t.prepare(payload);assert.equal(t.pending.body,body);assert.ok(warnings.length);await t.submit();assert.equal(t.pending,null);
});
test('command UUIDs work on local HTTP without randomUUID and never use Math.random',()=>{
    const value=newFlowCommandId({getRandomValues:bytes=>{bytes.fill(15);return bytes;}});
    assert.match(value,/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.throws(()=>newFlowCommandId({}),/Secure command IDs/);
});
test('canonical penalty and quarter notices explain repeated down and direction changes without inventing outcomes',()=>{
    const before={quarter:1,possession:'home'},after={quarter:2,possession:'home'};
    const messages=canonicalExhibitionNotices({plays:[{summary:'Holding on home · repeat down',outcome:'penalty',penalty:{type:'holding',accepted:true},before,after}],flow:{notices:[{message:'Clock stopped'}]}});
    assert.ok(messages.some(m=>m.includes('nullified')));assert.ok(messages.some(m=>m.includes('Teams change ends')));
    assert.ok(messages.includes('Clock stopped'));assert.ok(!messages.some(m=>m.includes('Possession changes')));
    const declined=canonicalExhibitionNotices({play:{summary:'Flag declined',penalty:{type:'holding',accepted:false}}});
    assert.ok(declined.some(m=>m.includes('declined')));assert.ok(!declined.some(m=>m.includes('nullified')));
});
test('scoreboard shows goal-to-go, possession direction and authoritative clock status',()=>{
    const s={quarter:2,clock:61,down:1,distance:10,spot:95,possession:'home'};
    assert.match(exhibitionStateLabel(s),/Q2 · 1:01 · 1st & Goal.*Home offense ←/);
    assert.equal(exhibitionClockLabel({...s,clock_running:true}),'Clock runs between snaps');
    assert.equal(exhibitionClockLabel({...s,untimed_down:true}),'Untimed down');
    assert.equal(exhibitionClockLabel({...s,status:'final'}),'Final whistle');
});
