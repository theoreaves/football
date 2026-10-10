import test from 'node:test';
import assert from 'node:assert/strict';
import {contactSoundCues} from '../../resources/js/practice/contact-sound-cues.js';
import {soundCues,crossedCues} from '../../resources/js/practice/sound-cues.js';
import {recordedContactEffects} from '../../resources/js/practice/recorded-contact-effects.js';
import {stadiumAudio} from '../../resources/js/practice/stadium-audio.js';

const track=(team,role,x,z)=>({team,role,path:[[0,x,0,z],[12,x,0,z]]});
const animation={call:'inside_run',duration:9,contact_at:8,result_at:8,reveal_at:6,tackle_style:'wrap',carrier:'RB',tackler_role:'LB2',
    players:[track('offense','C',40,26),track('offense','LG',40,28),track('offense','RG',40,24),
        track('defense','DT1',41,26),track('defense','DT2',41,28),track('defense','DE1',41,24),track('offense','RB',48,30),track('defense','LB2',48,30)]};

test('blocking contacts are bounded and depend on recorded proximity',()=>{
    const original=JSON.stringify(animation);const cues=contactSoundCues(animation);const pads=cues.filter(c=>c.type==='pad');
    assert.equal(pads.length,3);
    const edge={...animation,players:animation.players.map(p=>p.role==='DE1'?{...p,role:'EDGE1'}:p)};
    assert.equal(contactSoundCues(edge).filter(c=>c.type==='pad').length,3);assert.ok(pads.every(c=>c.time>=.22 && c.time<2.8));
    assert.ok(pads[1].time-pads[0].time>=.18);assert.ok(pads[2].time-pads[1].time>=.18);
    const distant={...animation,players:animation.players.map(p=>p.team==='defense'?{...p,path:p.path.map(pt=>[pt[0],pt[1]+20,pt[2],pt[3]])}:p)};
    assert.equal(contactSoundCues(distant).filter(c=>c.type==='pad').length,0);
    assert.equal(JSON.stringify(animation),original);
});

test('hit follows saved contact, landing follows tackle style, and cues cross once',()=>{
    for(const [style,fall] of [['wrap',.65],['lunge',.38],['side',.55]]){
        const cues=soundCues({...animation,tackle_style:style});
        assert.equal(cues.find(c=>c.type==='tackle').time,8);
        assert.ok(Math.abs(cues.find(c=>c.type==='landing').time-(8+.08+fall*.9))<1e-9);
        assert.ok(!crossedCues(cues,5.9,6.1).some(c=>c.type==='tackle'));
        assert.equal(crossedCues(cues,7.99,8).filter(c=>c.type==='tackle').length,1);
        assert.equal(crossedCues(cues,8,8).filter(c=>c.type==='tackle').length,0);
        assert.deepEqual(crossedCues(cues,8,4),[]);
    }
    assert.ok(!contactSoundCues({...animation,duration:8.1}).some(c=>c.type==='landing'));
});

test('no phantom tackles for incomplete passes, no-contact results or no-snap plays',()=>{
    assert.ok(!contactSoundCues({...animation,contact_at:null}).some(c=>['tackle','landing'].includes(c.type)));
    assert.ok(!contactSoundCues({...animation,tackle_style:null}).some(c=>c.type==='tackle'));
    assert.ok(!contactSoundCues({...animation,tackler_role:'missing'}).some(c=>c.type==='tackle'));
    assert.deepEqual(contactSoundCues({...animation,no_snap:true}),[]);
    for(const call of ['clock_event','kickoff','punt','field_goal','extra_point','spike','kneel'])assert.deepEqual(contactSoundCues({...animation,call}),[]);
    assert.deepEqual(contactSoundCues(null),[]);
    assert.deepEqual(contactSoundCues({call:'short_pass',players:[{team:'offense',role:'C',path:[]}]}),[]);
});

function fixture(fetch) {
    const sources=[],requests=[];
    const context={currentTime:12,state:'running',decodeAudioData:async data=>({duration:.6,data}),
        createGain:()=>({gain:{value:0},connect(){},disconnect(){}}),
        createBufferSource(){const source={connect(){},disconnect(){this.disconnected=true;},start(at){this.at=at;},stop(){this.stopped=true;this.onended?.();}};sources.push(source);return source;}};
    const environment={fetch:async url=>{requests.push(url);return fetch?fetch(url):{ok:true,arrayBuffer:async()=>new ArrayBuffer(8)};}};
    return {sources,requests,context,player:recordedContactEffects(context,{},environment,'/football/audio/contact/v1/')};
}

test('real impact variations are deterministic, bounded, local and stoppable',async()=>{
    const f=fixture();await f.player.load();await f.player.load();f.player.setActive(true);
    assert.equal(f.requests.length,7);assert.ok(f.requests.every(url=>url.startsWith('/football/audio/contact/v1/')));
    f.player.play('pad',0);f.player.play('pad',1);f.player.play('pad',0);
    assert.notEqual(f.sources[0].buffer,f.sources[1].buffer);assert.equal(f.sources[0].buffer,f.sources[2].buffer);
    f.player.play('tackle',2);f.player.play('landing');f.player.play('tackle');f.player.play('pad');
    assert.equal(f.sources.length,6);assert.ok(f.sources.every(s=>s.at===12));
    f.player.setActive(false);assert.ok(f.sources.every(s=>s.stopped&&s.disconnected));
    f.player.play('pad');assert.equal(f.sources.length,6);f.player.dispose();
});

test('failed/late assets never queue stale contact and available variants still work',async()=>{
    let resolve;const response=new Promise(r=>resolve=r);const f=fixture(()=>response);
    f.player.setActive(true);const loading=f.player.load();f.player.play('tackle');f.player.setActive(false);
    resolve({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)});await loading;f.player.setActive(true);assert.equal(f.sources.length,0);
    f.player.play('landing');f.player.dispose();assert.equal(f.sources[0].stopped,true);
    f.player.setActive(true);f.player.play('pad');assert.equal(f.sources.length,1);
    const partial=fixture(url=>({ok:url.endsWith('hit-2.mp3'),arrayBuffer:async()=>new ArrayBuffer(8)}));
    await partial.player.load();partial.player.setActive(true);partial.player.play('tackle',0);assert.equal(partial.sources.length,1);
    partial.player.play('pad');partial.player.play('landing');assert.equal(partial.sources.length,1);partial.player.dispose();
});

test('contact recordings use the existing Effects slider and stop at zero or pause',async()=>{
    const sources=[],gains=[];let change;
    class Context {
        constructor(){this.currentTime=0;this.state='running';}
        createGain(){const n={gain:{value:0,setTargetAtTime(v){this.value=v;}},connect(){},disconnect(){}};gains.push(n);return n;}
        decodeAudioData(){return Promise.resolve({duration:.6});}
        createBufferSource(){const n={connect(){},disconnect(){},start(){},stop(){this.stopped=true;this.onended?.();}};sources.push(n);return n;}
        close(){return Promise.resolve();}
    }
    const input={dataset:{soundVolume:'effects'},addEventListener(t,fn){change=fn;}};
    const root={dataset:{},querySelector:()=>null,querySelectorAll:()=>[input],addEventListener(){},removeEventListener(){}};
    const audio=stadiumAudio(root,{AudioContext:Context,fetch:async url=>({ok:url.includes('/contact/'),arrayBuffer:async()=>new ArrayBuffer(8)}),localStorage:{getItem:()=>null,setItem(){}}});
    audio.setActive(true);await audio.prepare();audio.play({type:'tackle',time:8});assert.equal(sources.length,1);
    assert.equal(gains[2].gain.value,.7);input.value=0;change();assert.equal(sources[0].stopped,true);
    audio.play({type:'pad',time:1});assert.equal(sources.length,1);
    input.value=70;change();audio.play({type:'landing',time:8.6});assert.equal(sources.length,2);
    audio.setActive(false);assert.equal(sources[1].stopped,true);audio.dispose();
});
