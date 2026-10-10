import test from 'node:test';
import assert from 'node:assert/strict';
import {ballSoundCues} from '../../resources/js/practice/ball-sound-cues.js';
import {soundCues,crossedCues} from '../../resources/js/practice/sound-cues.js';
import {recordedBallEffects} from '../../resources/js/practice/recorded-ball-effects.js';
import {stadiumAudio} from '../../resources/js/practice/stadium-audio.js';

const pass={call:'short_pass',passing:true,throw_at:2.2,catch_at:5.8,duration:8,
    ballHolders:[[0,'offense','C'],[.01,null,null],[.35,'offense','QB'],[2.2,null,null],[5.8,'offense','WR2']],
    ball:[[0,40,1,26],[2.2,38,2,26],[4,45,5,29],[5.8,52,1,32],[8,56,1,32]]};

test('receptions and interceptions follow the saved possession change, not a fixed catch time',()=>{
    const original=JSON.stringify(pass);assert.deepEqual(ballSoundCues(pass),[{time:5.8,type:'catch',mood:null}]);
    assert.equal(JSON.stringify(pass),original);
    const interception={...pass,ballHolders:pass.ballHolders.map(h=>h[0]===5.8?[5.8,'defense','CB1']:h)};
    assert.equal(ballSoundCues(interception)[0].time,5.8);
    const incomplete={...pass,ballHolders:pass.ballHolders.slice(0,-1),ball:[...pass.ball.slice(0,-2),[5.8,52,0,32],[8,52,0,32]]};
    assert.deepEqual(ballSoundCues(incomplete),[{time:5.8,type:'ball_ground',mood:null}]);
    assert.ok(!ballSoundCues({...pass,ballHolders:[]}).some(c=>c.type==='catch'));
    assert.deepEqual(crossedCues(ballSoundCues(pass),5.7,5.8).map(c=>c.type),['catch']);
    assert.deepEqual(crossedCues(ballSoundCues(pass),5.8,5.8),[]);
    assert.deepEqual(crossedCues(ballSoundCues(pass),6,3),[]);
});

test('all four kick types use kick timing once and only returns get catches',()=>{
    for(const call of ['kickoff','punt','field_goal','extra_point']){
        const animation={call,duration:6,events:[[0,'Snap'],[1.4,'Kick'],[2.5,'Kick in flight']],ballHolders:[[0,null,null]],ball:[[0,40,.25,26],[1.4,40,.5,26],[2.5,60,14,26],[3.5,90,5,26]]};
        const cues=soundCues(animation);
        assert.deepEqual(cues.filter(c=>c.type==='kick'),[{time:1.4,type:'kick',mood:null}]);
        assert.ok(!cues.some(c=>['catch','ball_ground'].includes(c.type)));
        assert.equal(ballSoundCues({...animation,kick_at:1.6})[0].time,1.6);
        assert.equal(ballSoundCues({...animation,events:[]})[0].time,1.2);
    }
    const returning={call:'punt',duration:6,ballHolders:[[0,'offense','C'],[.01,null,null],[.35,'offense','QB'],[1.2,null,null],[3.5,'defense','CB1']],ball:[[0,40,1,26],[2.4,60,14,26],[3.5,90,1,26]]};
    assert.deepEqual(ballSoundCues(returning).map(c=>[c.time,c.type]),[[1.2,'kick'],[3.5,'catch']]);
    const touchback={...returning,ballHolders:returning.ballHolders.slice(0,-1),ball:[...returning.ball.slice(0,-1),[3.5,113,.25,26],[6,113,.25,26]]};
    assert.deepEqual(ballSoundCues(touchback).map(c=>[c.time,c.type]),[[1.2,'kick'],[3.5,'ball_ground']]);
});

test('ground hits require visible descending loose-ball paths, not just a fumble outcome',()=>{
    const loose={call:'inside_run',duration:6,ballHolders:[[0,'offense','RB'],[4,null,null]],ball:[[0,40,1,26],[4,50,1,26],[4.3,50,.25,26],[4.6,51,.8,26],[4.9,52,.25,26],[6,52,.25,26]]};
    assert.deepEqual(ballSoundCues(loose).map(c=>c.time),[4.3,4.9]);
    assert.deepEqual(ballSoundCues({...loose,ballHolders:[[0,'offense','RB']]}),[]);
    const recovered={...loose,ballHolders:[[0,'offense','RB'],[4,'defense','LB2']]};
    assert.deepEqual(ballSoundCues(recovered),[]);
    assert.deepEqual(ballSoundCues({call:'inside_run',events:[[5,'Fumble']],ball:[[0,40,1,26],[6,50,1,26]]}),[]);
    assert.equal(ballSoundCues({...loose,duration:4.4}).length,1);
    assert.deepEqual(ballSoundCues({...loose,no_snap:true}),[]);
    assert.deepEqual(ballSoundCues({...loose,call:'clock_event'}),[]);
    assert.deepEqual(ballSoundCues(null),[]);
    assert.deepEqual(ballSoundCues({call:'kneel'}),[]);
});

test('snaps and handoffs are excluded, while a visible spike gets one ground sound',()=>{
    assert.deepEqual(ballSoundCues({...pass,call:'inside_run',passing:false}),[]);
    const spike={call:'spike',duration:6,ballHolders:[[0,'offense','C'],[.01,null,null],[.35,'offense','QB'],[1,null,null]],ball:[[0,40,1,26],[.35,38,1,26],[1,38,.25,26],[6,38,.25,26]]};
    assert.deepEqual(ballSoundCues(spike),[{time:1,type:'ball_ground',mood:null}]);
});

function fixture(fetch) {
    const sources=[],requests=[];
    const context={currentTime:12,state:'running',decodeAudioData:async data=>({duration:.4,data}),
        createGain:()=>({gain:{value:0},connect(){},disconnect(){}}),
        createBufferSource(){const source={connect(){},disconnect(){this.disconnected=true;},start(at){this.at=at;},stop(){this.stopped=true;this.onended?.();}};sources.push(source);return source;}};
    const environment={fetch:async url=>{requests.push(url);return fetch?fetch(url):{ok:true,arrayBuffer:async()=>new ArrayBuffer(8)};}};
    return {sources,requests,context,player:recordedBallEffects(context,{},environment,'/football/audio/ball/v1/')};
}

test('ball effects load locally once and select recorded variations deterministically',async()=>{
    const f=fixture();await f.player.load();await f.player.load();f.player.setActive(true);
    assert.equal(f.requests.length,6);assert.ok(f.requests.every(url=>url.startsWith('/football/audio/ball/v1/')));
    f.player.play('catch',0);f.player.play('catch',1);f.player.play('catch',0);
    assert.notEqual(f.sources[0].buffer,f.sources[1].buffer);assert.equal(f.sources[0].buffer,f.sources[2].buffer);
    f.player.play('kick');f.player.play('ball_ground',1);
    f.player.setActive(false);assert.ok(f.sources.every(s=>s.stopped&&s.disconnected));
    f.player.play('catch');assert.equal(f.sources.length,5);f.player.dispose();
});

test('late/missing ball recordings never replay stale cues and available variations can substitute',async()=>{
    let resolve;const response=new Promise(r=>resolve=r);const f=fixture(()=>response);
    f.player.setActive(true);const loading=f.player.load();f.player.play('catch');f.player.setActive(false);
    resolve({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)});await loading;f.player.setActive(true);assert.equal(f.sources.length,0);
    f.player.play('kick');f.player.dispose();assert.equal(f.sources[0].stopped,true);
    f.player.setActive(true);f.player.play('catch');assert.equal(f.sources.length,1);
    const partial=fixture(url=>({ok:url.endsWith('catch-2.mp3'),arrayBuffer:async()=>new ArrayBuffer(8)}));
    await partial.player.load();partial.player.setActive(true);partial.player.play('catch',0);assert.equal(partial.sources.length,1);
    partial.player.play('kick');partial.player.play('ball_ground');assert.equal(partial.sources.length,1);partial.player.dispose();
});

test('recorded kicks/catches/ground sounds use Effects and stop at zero, pause or mute',async()=>{
    const sources=[],gains=[];let change,toggle;
    class Context {
        constructor(){this.currentTime=0;this.state='running';}
        createGain(){const n={gain:{value:0,setTargetAtTime(v){this.value=v;}},connect(){},disconnect(){}};gains.push(n);return n;}
        decodeAudioData(){return Promise.resolve({duration:.4});}
        createBufferSource(){const n={connect(){},disconnect(){},start(){},stop(){this.stopped=true;this.onended?.();}};sources.push(n);return n;}
        close(){return Promise.resolve();}
    }
    const input={dataset:{soundVolume:'effects'},addEventListener(t,fn){change=fn;}};
    const button={addEventListener(t,fn){toggle=fn;},setAttribute(){}};
    const root={dataset:{},querySelector:()=>button,querySelectorAll:()=>[input],addEventListener(){},removeEventListener(){}};
    const audio=stadiumAudio(root,{AudioContext:Context,fetch:async url=>({ok:url.includes('/ball/'),arrayBuffer:async()=>new ArrayBuffer(8)}),localStorage:{getItem:()=>null,setItem(){}}});
    audio.setActive(true);await audio.prepare();audio.play({type:'kick',time:1.2});assert.equal(sources.length,1);
    assert.equal(gains[2].gain.value,.7);input.value=0;change();assert.equal(sources[0].stopped,true);
    audio.play({type:'catch',time:5.8});assert.equal(sources.length,1);
    input.value=70;change();audio.play({type:'ball_ground',time:5.8});assert.equal(sources.length,2);
    audio.setActive(false);assert.equal(sources[1].stopped,true);
    audio.setActive(true);audio.play({type:'catch',time:5.8});toggle();assert.equal(sources[2].stopped,true);
    audio.play({type:'kick',time:1.2});assert.equal(sources.length,3);audio.dispose();
});
