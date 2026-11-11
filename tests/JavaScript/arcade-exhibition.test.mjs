import test from 'node:test';
import assert from 'node:assert/strict';
import {createDrill,startDrill,stepDrill} from '../../resources/js/arcade/simulation.js';
import {createPassingDrill,stepPassingDrill} from '../../resources/js/arcade/passing.js';
import {createDrillRecording} from '../../resources/js/arcade/recording.js';
import {createExhibitionTransport, canContinueExhibitionDrive, noSnapNotice} from '../../resources/js/arcade/exhibition-session.js';
import {arcadeCameraFrame,exhibitionCameraX} from '../../resources/js/arcade/view.js';
const transportContext = {startUrl:'/exhibitions/12/arcade/sessions',origin:'https://football.test'};
const session = () => ({envelope:{session_id:'abc',result_id:'same'},setup:{},submit_url:'/exhibitions/12/arcade/sessions/abc/submit',cancel_url:'/exhibitions/12/arcade/sessions/abc/cancel'});
const evidence = () => ({version:1,frames:[[1,2]],actors:[],events:[],result:{reason:'tackle'}});
const player = id => ({id,name:`Player ${id}`,ratings:{speed:70,acceleration:70,strength:0,tackling:100,awareness:70,catching:90,throwing:90,coverage:90}});
const options = (direction=1,spot=25) => ({runner:player(1),quarterback:player(1),receivers:[player(2),player(3)],defenders:[player(4),player(5)],defenderCount:2,seed:32768,direction,speedScale:.925,exhibition:{startX:direction===1?10+spot:110-spot,goalX:direction===1?110:10,rearX:direction===1?10:110,firstDownX:direction===1?20+spot:100-spot}});
const alone = state => {state.defenders.forEach(d=>{d.z=1000;});return startDrill(state);};
for(const direction of [1,-1]) {
    test(`exhibition LOS, actors and first down use session geometry (${direction})`,()=>{
        const o=options(direction),run=createDrill(o),pass=createPassingDrill(o);
        assert.equal(run.startX,o.exhibition.startX);assert.equal(run.runner.x,run.startX);
        assert.equal(pass.quarterback.x,pass.startX-direction*3);
        assert.equal(pass.firstDownX,o.exhibition.firstDownX);
        assert.equal(run.speedScale,.925);assert.equal(run.rearX,o.exhibition.rearX);
        assert.equal(pass.receivers[0].x,pass.startX+direction);
    });
    test(`crossing old retreat limit does not end live exhibition (${direction})`,()=>{
        const s=alone(createDrill(options(direction,40)));
        for(let i=0;i<100;i++)stepDrill(s,{x:-direction});
        assert.ok((s.runner.x-s.startX)*direction < -6);assert.equal(s.status,'running');
    });
    test(`own end zone can be entered and escaped; only dead ball becomes safety (${direction})`,()=>{
        const s=alone(createPassingDrill(options(direction,1)));
        assert.ok((s.runner.x-s.rearX)*direction<0);
        for(let i=0;i<35;i++)stepPassingDrill(s,{x:direction});
        assert.equal(s.status,'running');assert.ok((s.runner.x-s.rearX)*direction>0);
        const back=alone(createDrill(options(direction,1)));
        for(let i=0;i<300 && back.status==='running';i++)stepDrill(back,{x:-direction});
        assert.equal(back.result.reason,'safety');assert.equal(back.runner.x,direction===1?0:120);
    });
    test(`TD and tackle metadata use real endpoints (${direction})`,()=>{
        const s=alone(createDrill(options(direction,99))),record=createDrillRecording(s);
        for(let i=0;i<100&&s.status==='running';i++){stepDrill(s,{x:direction});record.capture(s);}
        assert.equal(s.result.reason,'touchdown');assert.equal(s.runner.x,s.goalX);
        assert.equal(record.export().frames.at(-1)[3][0][0],s.goalX*100);
        const contact=startDrill(createDrill(options(direction)));contact.defenders[0].x=contact.runner.x;contact.defenders[0].z=contact.runner.z;
        const r=createDrillRecording(contact);stepDrill(contact);r.capture(contact);
        assert.equal(contact.result.reason,'tackle');
        const event=r.export().events.find(e=>e[2]==='tackle');assert.equal(event[3],0);assert.equal(event[4],1);
    });
}
test('exhibition live clock does not kill the physics; local timeout remains explicitly unsupported',()=>{
    const s=alone(createDrill(options()));s.clock=0;stepDrill(s,{x:1});assert.equal(s.status,'running');
    const storage=new Map(),t=createExhibitionTransport({...transportContext,storage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},key:'test',request:async()=>({})});
    t.reserve(session());
    assert.throws(()=>t.prepare({result:{reason:'timeout'}}),/cannot be submitted/);
});
test('durable retries preserve exact evidence/body/result ID across unknown outcome and reload',async()=>{
    const store=new Map(),storage={getItem:k=>store.get(k),setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)};
    const sent=[],request=async(url,body)=>{sent.push([url,body]);if(sent.length===1)throw Error('lost response');return{receipt:{id:'same'}};};
    const args={...transportContext,storage,key:'test',request},a=createExhibitionTransport(args);
    a.reserve(session());
    const evidence={version:1,frames:[[1,2]],actors:[],events:[],result:{reason:'tackle'}};const body=a.prepare(evidence);
    evidence.frames[0][0]=999;assert.equal(a.prepare({result:{reason:'touchdown'}}),body);
    await assert.rejects(a.submit(),/lost response/);assert.ok(a.pending);
    const b=createExhibitionTransport(args);assert.equal(b.pending.body,body);await b.submit();
    assert.deepEqual(sent[0],sent[1]);assert.equal(b.pending,null);assert.equal(store.size,0);
});
test('cancel clears only after server response; replay cannot mutate the transport',async()=>{
    const store=new Map(),storage={getItem:k=>store.get(k),setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)};
    let fail=true;const t=createExhibitionTransport({...transportContext,storage,key:'test',request:async()=>{if(fail)throw Error('offline');return{cancelled:true};}});
    t.reserve(session());await assert.rejects(t.cancel());assert.ok(t.pending);
    fail=false;await t.cancel();assert.equal(t.pending,null);
});
test('exhibition framing translates only, preserving the approved angle and lens',()=>{
    const original=arcadeCameraFrame(900,600),live=arcadeCameraFrame(900,600,1,exhibitionCameraX(35,1));
    assert.equal(original.top,live.top);assert.equal(original.left,live.left);
    assert.equal(live.position[0],45);assert.equal(live.target[0],45);
    assert.deepEqual(original.position.slice(1),live.position.slice(1));
});

test('untrusted recovery shapes, broken JSON and foreign or mismatched endpoints are discarded',()=>{
    const good={session:session(),body:null};
    const candidates=['{', 'true', '[]', '{}', JSON.stringify({...good,body:'broken'}),
        JSON.stringify({...good,session:{...session(),envelope:null}}),
        JSON.stringify({...good,session:{...session(),submit_url:'https://evil.test/exhibitions/12/arcade/sessions/abc/submit'}}),
        JSON.stringify({...good,session:{...session(),cancel_url:'/exhibitions/13/arcade/sessions/abc/cancel'}}),
        JSON.stringify({...good,session:{...session(),cancel_url:'/exhibitions/12/arcade/sessions/other/cancel'}}),
        JSON.stringify({...good,body:JSON.stringify({envelope:{},recording:evidence()})}),
        JSON.stringify({...good,body:JSON.stringify({envelope:session().envelope,recording:{}})})];
    for(const raw of candidates){
        let removed=0,warnings=0;
        const storage={getItem:()=>raw,removeItem:()=>removed++,setItem(){}};
        const t=createExhibitionTransport({...transportContext,storage,key:'test',request:async()=>{},onWarning:()=>warnings++});
        assert.equal(t.pending,null);assert.equal(removed,1);assert.equal(warnings,1);
        t.reserve(session());assert.equal(t.pending.session.envelope.session_id,'abc','valid server reservation remains recoverable');
    }
});
test('storage denied or quota exhausted retains exact in-memory retries and accepted receipt despite cleanup failure',async()=>{
    for(const storage of [null,{getItem(){throw Error('denied');},setItem(){throw Error('quota');},removeItem(){throw Error('denied');}}]){
        const warnings=[],sent=[];
        const t=createExhibitionTransport({...transportContext,storage,key:'test',onWarning:message=>warnings.push(message),request:async(url,body)=>{sent.push(body);return{receipt:{result_id:'same'}};}});
        t.reserve(session());const body=t.prepare(evidence());assert.equal(t.pending.body,body);
        const result=await t.submit();assert.equal(result.receipt.result_id,'same');assert.equal(t.pending,null);assert.deepEqual(sent,[body]);assert.ok(warnings.length);
    }
});

test('precise exhibition terminal evidence preserves a tackle just short of either goal despite centiyard rounding',()=>{
    for(const direction of [1,-1]){
        const s=startDrill(createDrill(options(direction,99)));
        s.runner.x=direction===1?109.996:10.004;
        s.defenders[0].x=s.runner.x;s.defenders[0].z=s.runner.z;
        const r=createDrillRecording(s);stepDrill(s);r.capture(s);
        assert.equal(s.result.reason,'tackle');const exported=r.export();
        assert.equal(exported.frames.at(-1)[3][0][0],s.goalX*100);
        assert.deepEqual(exported.events.at(-1)[5].position,[s.runner.x,s.runner.z]);
        assert.ok((exported.events.at(-1)[5].position[0]-s.goalX)*direction<0);
    }
});
test('raw catch contact evidence is additive only for exhibitions and detached from simulation events',()=>{
    for(const exhibition of [true,false]){
        const config=options();if(!exhibition)delete config.exhibition;
        const s=startDrill(createPassingDrill(config));const r=createDrillRecording(s);
        s.tick=1;s.elapsed=1/60;s.events.push({tick:1,time:1/60,type:'catch',actorKey:'receiver:0',contact:{x:109.996,y:1.123456,z:26.665}});
        r.capture(s);s.events[0].contact.x=0;
        const data=r.export().events.find(event=>event[2]==='catch')[5];
        assert.deepEqual(data.contact,[11000,112,2667]);
        if(exhibition)assert.deepEqual(data.contactExact,[109.996,1.123456,26.665]);
        else assert.equal(Object.hasOwn(data,'contactExact'),false);
    }
});

test('no-snap warning and quarter advance preserve same offensive drive, but halftime and possession changes hand off',()=>{
    for(const summary of ['Two-minute warning.', 'End of first quarter.', 'Penalty automatically accepted.']){
        const stopped=!canContinueExhibitionDrive({possession:'home',phase:'scrimmage'},true,'home');
        assert.equal(stopped,false);assert.equal(noSnapNotice({summary},stopped),`${summary} No snap occurred. Choose and reserve the next play when ready.`);
    }
    for(const [state,available] of [[{possession:'home',phase:'halftime'},false],[{possession:'home',phase:'kickoff'},false],[{possession:'away',phase:'scrimmage'},true]]){
        const stopped=!canContinueExhibitionDrive(state,available,'home');
        assert.equal(stopped,true);assert.match(noSnapNotice({summary:'Phase changed.'},stopped),/Continue in Coach/);
    }
});
