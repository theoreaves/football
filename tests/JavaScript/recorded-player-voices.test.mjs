import test from 'node:test';
import assert from 'node:assert/strict';
import {cadenceCues,crossedCues,soundSettings} from '../../resources/js/practice/sound-cues.js';
import {recordedPlayerVoices} from '../../resources/js/practice/recorded-player-voices.js';
import {stadiumAudio} from '../../resources/js/practice/stadium-audio.js';

function fixture(fetch) {
    const sources=[], requests=[];
    const context={currentTime:10,state:'running',decodeAudioData:async data=>({duration:.4,data}),
        createGain:()=>({gain:{value:0},connect(){},disconnect(){}}),
        createBufferSource(){const source={connect(){},disconnect(){},start(at){this.at=at;},stop(){this.stopped=true;this.onended?.();}};sources.push(source);return source;}};
    const environment={fetch:async url=>{requests.push(url);return fetch ? fetch(url):{ok:true,arrayBuffer:async()=>new ArrayBuffer(8)};}};
    return {sources,requests,context,player:recordedPlayerVoices(context,{},environment,'/football/audio/players/v1/')};
}

test('cadence ends on the snap and excludes no-snap and special-teams plays',()=>{
    const animation={call:'inside_run',duration:12};const original=JSON.stringify(animation);
    const cues=cadenceCues(animation);
    assert.deepEqual(crossedCues(cues,-4,-1.9).map(c=>c.mood),['ready']);
    assert.deepEqual(crossedCues(cues,-1.9,-.9).map(c=>c.mood),['set']);
    assert.deepEqual(crossedCues(cues,-.01,0).map(c=>c.mood),['hut']);
    assert.deepEqual(crossedCues(cues,0,0),[]);
    assert.equal(JSON.stringify(animation),original);
    assert.equal(cadenceCues({call:'short_pass'}).length,3);
    for(const call of ['clock_event','kickoff','punt','field_goal','extra_point'])assert.deepEqual(cadenceCues({call}),[]);
    assert.deepEqual(cadenceCues({...animation,no_snap:true}),[]);
    assert.deepEqual(cadenceCues(null),[]);
});

test('player clips load once, stay local, and interruption stops an old word',async()=>{
    const f=fixture();await f.player.load();await f.player.load();f.player.setActive(true);
    assert.equal(f.requests.length,3);assert.ok(f.requests.every(url=>url.startsWith('/football/audio/players/v1/')));
    f.player.play('ready');f.player.play('hut');assert.equal(f.sources[0].stopped,true);
    assert.equal(f.sources[1].at,10);assert.notEqual(f.sources[0].buffer,f.sources[1].buffer);
    f.player.setActive(false);assert.equal(f.sources[1].stopped,true);
    f.player.play('hut');assert.equal(f.sources.length,2);f.player.dispose();
});

test('slow downloads, missing audio, pauses and disposal never backfill a cadence',async()=>{
    let resolve;const delayed=new Promise(r=>resolve=r);const f=fixture(()=>delayed);
    f.player.setActive(true);const load=f.player.load();f.player.play('ready');f.player.setActive(false);
    resolve({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)});await load;
    f.player.setActive(true);assert.equal(f.sources.length,0);
    f.player.play('hut');f.player.dispose();assert.equal(f.sources[0].stopped,true);
    f.player.setActive(true);f.player.play('hut');assert.equal(f.sources.length,1);
    const missing=fixture(async()=>{throw Error('offline');});await missing.player.load();missing.player.setActive(true);missing.player.play('hut');assert.equal(missing.sources.length,0);
});

test('Players slider defaults for old settings and persists independently of Effects',async()=>{
    assert.equal(soundSettings({effects:.2}).players,.75);
    assert.equal(soundSettings({players:4}).players,1);
    assert.equal(soundSettings({players:-1}).players,0);
    let saved,callback;const gains=[],sources=[];
    const input={dataset:{soundVolume:'players'},addEventListener(type,fn){callback=fn;}};
    const root={dataset:{},querySelector:()=>null,querySelectorAll:()=>[input],addEventListener(){},removeEventListener(){}};
    class Context {
        constructor(){this.currentTime=0;this.state='running';}
        createGain(){const node={gain:{value:0,setTargetAtTime(v){this.value=v;}},connect(){},disconnect(){}};gains.push(node);return node;}
        decodeAudioData(){return Promise.resolve({duration:.4});}
        createBufferSource(){const node={connect(){},disconnect(){},start(){},stop(){this.stopped=true;this.onended?.();}};sources.push(node);return node;}
        close(){return Promise.resolve();}
    }
    const audio=stadiumAudio(root,{AudioContext:Context,fetch:async url=>({ok:url.includes('/players/'),arrayBuffer:async()=>new ArrayBuffer(8)}),localStorage:{getItem:()=>'{"effects":0.2}',setItem(k,v){saved=JSON.parse(v);}}});
    assert.equal(input.value,75);audio.setActive(true);await audio.prepare();
    const bedSources=sources.length;
    audio.play({type:'cadence',mood:'hut'});assert.equal(sources.length,bedSources+1);
    const voice=sources.at(-1);
    input.value=0;callback();assert.equal(saved.players,0);assert.equal(saved.effects,.2);
    assert.equal(gains[2].gain.value,.2);assert.equal(gains[3].gain.value,0);assert.equal(voice.stopped,true);
    audio.play({type:'cadence',mood:'hut'});assert.equal(sources.length,bedSources+1);audio.dispose();
});
