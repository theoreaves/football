import test from 'node:test';
import assert from 'node:assert/strict';
import {recordedFieldEffects} from '../../resources/js/practice/recorded-field-effects.js';
import {soundCues} from '../../resources/js/practice/sound-cues.js';

function fixture(fetch) {
    const sources=[],requests=[];
    const context={currentTime:12,state:'running',decodeAudioData:async data=>({duration:.5,data}),
        createGain:()=>({gain:{value:0},connect(){},disconnect(){}}),
        createBufferSource(){const source={connect(){},disconnect(){this.disconnected=true;},
            start(at){this.at=at;},stop(){this.stopped=true;this.onended?.();}};sources.push(source);return source;}};
    const environment={fetch:async url=>{requests.push(url);return fetch?fetch(url):{ok:true,arrayBuffer:async()=>new ArrayBuffer(8)};}};
    return {sources,requests,context,player:recordedFieldEffects(context,{},environment,'/football/audio/referee/v1/')};
}

test('whistle recordings vary deterministically and pre-snap stops use two separated blasts',async()=>{
    const f=fixture();await f.player.load();await f.player.load();f.player.setActive(true);
    assert.equal(f.requests.length,2);assert.ok(f.requests.every(url=>url.startsWith('/football/audio/referee/v1/')));
    f.player.playWhistle('short',0);f.player.playWhistle('short',1);f.player.playWhistle('short',0);
    assert.notEqual(f.sources[0].buffer,f.sources[1].buffer);assert.equal(f.sources[0].buffer,f.sources[2].buffer);
    f.player.playWhistle('stop',1);
    assert.equal(f.sources.at(-2).at,12);assert.equal(f.sources.at(-1).at,12.6);
    assert.equal(f.sources.at(-2).buffer,f.sources.at(-1).buffer);
    f.player.setActive(false);assert.ok(f.sources.every(source=>source.stopped && source.disconnected));
    f.player.playWhistle();assert.equal(f.sources.length,5);f.player.dispose();
});

test('mute, pause, disposal and unavailable clips never replay a delayed whistle',async()=>{
    let resolve;const response=new Promise(r=>{resolve=r;});const f=fixture(()=>response);
    f.player.setActive(true);const loading=f.player.load();f.player.playWhistle();f.player.setActive(false);
    resolve({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)});await loading;
    assert.equal(f.sources.length,0);f.player.setActive(true);assert.equal(f.sources.length,0);
    f.player.playWhistle('stop');f.player.dispose();assert.ok(f.sources.every(source=>source.stopped));
    f.player.setActive(true);f.player.playWhistle();assert.equal(f.sources.length,2);
    const missing=fixture(async()=>{throw new Error('offline');});await missing.player.load();missing.player.setActive(true);missing.player.playWhistle();assert.equal(missing.sources.length,0);missing.player.dispose();
});

test('one failed recording falls back to the other available real whistle',async()=>{
    const f=fixture(url=>({ok:url.endsWith('whistle-short.mp3'),arrayBuffer:async()=>new ArrayBuffer(8)}));
    await f.player.load();f.player.setActive(true);f.player.playWhistle('short',1);
    assert.equal(f.sources.length,1);f.player.dispose();
});

test('whistles follow contact and dead-ball timing without ending a live flag early',()=>{
    const animation={call:'inside_run',duration:10,contact_at:7.8,result_at:8,reveal_at:7,events:[[8,'Gain 12 yards']]};
    const before=JSON.stringify(animation);
    assert.ok(Math.abs(soundCues(animation).find(c=>c.type==='whistle').time-7.9)<1e-9);
    const stopped=soundCues({...animation,no_snap:true}).filter(c=>c.type==='whistle');
    assert.deepEqual(stopped,[{time:.48,type:'whistle',mood:'stop'}]);
    const live=soundCues({...animation,outcome:'penalty',no_snap:false});
    assert.equal(live.find(c=>c.type==='whistle').mood,'short');
    assert.ok(Math.abs(live.find(c=>c.type==='whistle').time-7.9)<1e-9);
    assert.ok(!soundCues({...animation,call:'clock_event',no_snap:true}).some(c=>c.type==='whistle'));
    assert.equal(soundCues({...animation,contact_at:null,result_at:9.98}).find(c=>c.type==='whistle').time,10);
    assert.equal(JSON.stringify(animation),before);
});
