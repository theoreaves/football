import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {recordedCrowd} from '../../resources/js/practice/recorded-crowd.js';
import {stadiumAudio} from '../../resources/js/practice/stadium-audio.js';

function fixture(fetch) {
    const sources=[], gains=[], requested=[];
    const parameter=()=>({value:0,setValueAtTime(v){this.value=v;},linearRampToValueAtTime(){},
        setTargetAtTime(v){this.value=v;},cancelScheduledValues(){},exponentialRampToValueAtTime(){}});
    const node=()=>({connect(){},disconnect(){this.disconnected=true;},gain:parameter(),frequency:parameter()});
    const context={state:'running',currentTime:10,sampleRate:100,
        createGain(){const gain=node();gains.push(gain);return gain;},
        createBufferSource(){const source={...node(),start(...args){this.started=args;},stop(){this.stopped=true;this.onended?.();}};sources.push(source);return source;},
        createOscillator(){return this.createBufferSource();},
        createBuffer(){throw new Error('Recorded crowd must never synthesize noise');},
        decodeAudioData:async()=>({duration:20}),close:async()=>{},
    };
    const environment={fetch:async(url,options)=>{requested.push(url);return fetch ? fetch(url,options) : {ok:true,arrayBuffer:async()=>new ArrayBuffer(8)};}};
    const crowd=recordedCrowd(context,{},environment,'/football/audio/crowd/v1/');
    return {crowd,context,environment,sources,gains,requested};
}

test('loads each local clip once and uses a recorded looping bed plus distinct reaction buffers', async()=>{
    const f=fixture();f.crowd.setActive(true);
    await f.crowd.load();await f.crowd.load();
    assert.deepEqual(f.requested,['/football/audio/crowd/v1/ambience.mp3','/football/audio/crowd/v1/cheer.mp3','/football/audio/crowd/v1/boo.mp3']);
    const ambience=f.sources.find(s=>s.loop);
    assert.ok(ambience && ambience.started);
    f.crowd.play('cheer',{variant:2});f.crowd.play('groan');
    assert.equal(f.sources.length,3);
    assert.notEqual(f.sources[1].buffer,f.sources[2].buffer);
    assert.deepEqual(f.sources[1].started,[10,10,5]);
    assert.equal(f.sources[2].started[2],3.4);
    f.crowd.dispose();assert.ok(f.sources.every(s=>s.stopped && s.disconnected));
});

test('pause stops the bed and reactions, then resumes the bed at its prior position', async()=>{
    const f=fixture();await f.crowd.load();f.crowd.setActive(true);f.crowd.play('cheer');
    f.context.currentTime=12.5;f.crowd.setActive(false);
    assert.ok(f.sources.every(s=>s.stopped));
    f.crowd.play('cheer');assert.equal(f.sources.length,2);
    f.crowd.setActive(true);
    assert.deepEqual(f.sources.at(-1).started,[12.5,2.5]);
    f.crowd.dispose();
});

test('loading never queues a stale reaction or starts sound after pause/disposal', async()=>{
    let resolve;
    const response=new Promise(r=>{resolve=r;});
    const f=fixture(()=>response);f.crowd.setActive(true);
    const loading=f.crowd.load();f.crowd.play('cheer');f.crowd.setActive(false);
    resolve({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)});await loading;
    assert.equal(f.sources.length,0);
    f.crowd.setActive(true);assert.equal(f.sources.length,1);
    f.crowd.dispose();f.crowd.setActive(true);f.crowd.play('groan');assert.equal(f.sources.length,1);
    let complete;
    const delayed=new Promise(r=>{complete=r;});
    const pending=fixture(()=>delayed);
    const job=pending.crowd.load();pending.crowd.dispose();
    complete({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)});await job;
    pending.crowd.setActive(true);assert.equal(pending.sources.length,0);
});

test('partial network/decode failures stay silent without synthetic fallback', async()=>{
    const f=fixture(url=>{
        if(url.endsWith('ambience.mp3')) throw new Error('offline');
        return {ok:!url.endsWith('boo.mp3'),arrayBuffer:async()=>new ArrayBuffer(8)};
    });
    f.crowd.setActive(true);await f.crowd.load();f.crowd.play('groan');
    assert.equal(f.sources.length,0);
    f.crowd.play('cheer');assert.equal(f.sources.length,1);f.crowd.dispose();
    const decode=fixture();decode.context.decodeAudioData=async()=>{throw new Error('invalid MP3');};
    decode.crowd.setActive(true);await decode.crowd.load();decode.crowd.play('cheer');
    assert.equal(decode.sources.length,0);decode.crowd.dispose();
});

test('stadium integration replaces result and third-down noise, retains mute and crowd volume controls', async()=>{
    const f=fixture(), callbacks={}, values=[];
    const button={addEventListener:(name,callback)=>callbacks[name]=callback,setAttribute(){}};
    const volume={dataset:{soundVolume:'crowd'},value:35,addEventListener:(name,callback)=>callbacks.volume=callback};
    const root={dataset:{crowdAudioBase:'/football/audio/crowd/v1',playNumber:'2'},querySelector:()=>button,
        querySelectorAll:()=>[volume],addEventListener(){},removeEventListener(){}};
    const audio=stadiumAudio(root,{...f.environment,AudioContext:class{constructor(){return f.context;}},
        localStorage:{getItem:()=>null,setItem:(key,value)=>values.push(JSON.parse(value))}});
    audio.setActive(true);await audio.prepare();
    audio.play({type:'crowd',mood:'cheer'});audio.play({type:'third_down'});
    assert.equal(f.sources.filter(source=>source.buffer&&!source.loop).length,2);
    volume.value=12;callbacks.volume();assert.equal(values.at(-1).crowd,.12);
    callbacks.click();assert.equal(values.at(-1).enabled,false);
    assert.ok(f.sources.every(source=>source.stopped));
    audio.play({type:'crowd',mood:'groan'});assert.equal(f.sources.filter(source=>source.buffer&&!source.loop).length,2);
    audio.dispose();
});

test('bundled MP3s are nonempty and credits identify all three CC0 source authors',()=>{
    const base=new URL('../../public/audio/crowd/v1/',import.meta.url);
    for(const file of ['ambience.mp3','cheer.mp3','boo.mp3']) assert.ok(readFileSync(new URL(file,base)).length>10000);
    const credits=readFileSync(new URL('CREDITS.txt',base),'utf8');
    for(const author of ['stomachache','FoolBoyMedia','NeoSpica']) assert.ok(credits.includes(author));
    assert.ok(credits.includes('CC0 1.0'));
});
