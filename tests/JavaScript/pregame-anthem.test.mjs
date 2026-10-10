import test from 'node:test';
import assert from 'node:assert/strict';
import { recordedStadiumMusic } from '../../resources/js/practice/recorded-stadium-music.js';
import { mountPregameAnthem } from '../../resources/js/practice/pregame-anthem.js';
const settle=()=>new Promise(resolve=>setImmediate(resolve));
function fixture(refuse=false){
 const instances=[],listeners=new Map(),timers=new Map();let id=0;
 const document={hidden:false,addEventListener:(k,v)=>listeners.set(k,v),removeEventListener:k=>listeners.delete(k)};
 class Audio {constructor(url){this.url=url;this.refuse=refuse;this.paused=true;this.events={};this.currentTime=0;this.plays=0;instances.push(this);}addEventListener(k,v){this.events[k]=v;}play(){this.plays++;if(this.refuse){this.paused=true;return Promise.reject({name:'NotAllowedError'});}this.paused=false;return this.deferred??Promise.resolve();}pause(){this.paused=true;}removeAttribute(){this.removed=true;}load(){this.loaded=true;}}
 const environment={Audio,document,setTimeout:fn=>{timers.set(++id,fn);return id;},clearTimeout:k=>timers.delete(k)};
 const music=recordedStadiumMusic(environment,'/audio/stadium/v1/anthem.mp3');
 return {music,instances,timers,environment,hide(value){document.hidden=value;listeners.get('visibilitychange')?.();},flush(){while(timers.size){const [key,fn]=timers.entries().next().value;timers.delete(key);fn();}}};
}
test('anthem pauses and resumes without restarting and starts only once',async()=>{
 const f=fixture();let starts=0;f.music.setLevel(.3);f.music.request({onStarted:()=>starts++});await settle();const a=f.instances[0];assert.equal(a.volume,.3);assert.equal(a.loop,false);assert.equal(starts,1);
 a.currentTime=18;f.hide(true);assert.equal(a.paused,true);f.hide(false);await settle();assert.equal(a.currentTime,18);f.music.setLevel(0);assert.equal(a.paused,true);f.music.setLevel(.2);await settle();assert.equal(a.plays,3);assert.equal(starts,1);
 a.events.ended();f.music.request();assert.equal(a.plays,3);f.music.dispose();assert.ok(a.removed&&a.loaded);
});
test('blocked autoplay can retry on a user gesture',async()=>{
 const f=fixture(true),states=[];f.music.setLevel(.3);f.music.request({onState:s=>states.push(s)});await settle();assert.equal(states.at(-1),'gesture');f.instances[0].refuse=false;f.music.unlock();await settle();assert.equal(states.at(-1),'playing');f.music.dispose();
});
test('finishing fades then stops permanently and caches the closing promise',async()=>{
 const f=fixture();f.music.setLevel(.3);f.music.request();await settle();const a=f.instances[0],closing=f.music.finish();assert.equal(f.music.finish(),closing);assert.equal(a.paused,false);f.flush();await closing;assert.equal(a.volume,0);assert.equal(a.paused,true);f.music.request();assert.equal(a.plays,1);f.music.dispose();
});
test('mute, hidden tabs and disposal complete an active fade',async()=>{
 for(const action of ['mute','hide','dispose']){const f=fixture();f.music.setLevel(.3);f.music.request();await settle();const closing=f.music.finish();if(action==='mute')f.music.setLevel(0);else if(action==='hide')f.hide(true);else f.music.dispose();await closing;assert.equal(f.timers.size,0);assert.equal(f.instances[0].paused,true);f.music.dispose();}
});
test('a late play promise cannot start after the intro closes',async()=>{
 const f=fixture();let started=0,resolve;f.environment.Audio=class extends f.environment.Audio{constructor(url){super(url);this.deferred=new Promise(r=>resolve=r);}};f.music.setLevel(.3);f.music.request({onStarted:()=>started++});await f.music.finish(0);resolve();await settle();assert.equal(started,0);assert.equal(f.instances[0].paused,true);f.music.dispose();
});
function intro(storage,key='game-1',opening=true){const panel={},button={addEventListener(){}},status={},calls=[],finishes=[];const dialog={querySelector:s=>s==='[data-anthem-panel]'?panel:s==='[data-anthem-play]'?button:status};const controller=mountPregameAnthem({dataset:{cameraKey:key}},dialog,{startAnthem:c=>calls.push(c),finishAnthem:s=>{finishes.push(s);return Promise.resolve();}},opening,storage);return {controller,panel,button,status,calls,finishes};}
test('anthem persistence belongs to one game and reopening lineups does not play',async()=>{
 const values=new Map(),storage={getItem:k=>values.get(k),setItem:(k,v)=>values.set(k,v)};const first=intro(storage);assert.equal(first.calls.length,1);first.calls[0].onStarted();assert.equal(first.button.hidden,true);assert.equal(intro(storage).calls.length,0);assert.equal(intro(storage,'game-2').calls.length,1);const reopened=intro(storage,'game-1',false);assert.equal(reopened.panel.hidden,true);assert.equal(reopened.calls.length,0);await first.controller.finish();first.controller.closed();assert.equal(first.panel.hidden,true);
});
test('skipping records the intro and unavailable storage remains optional',async()=>{
 const values=new Map(),storage={getItem:k=>values.get(k),setItem:(k,v)=>values.set(k,v)};const skipped=intro(storage);await skipped.controller.finish();assert.equal(intro(storage).calls.length,0);const broken={getItem(){throw Error();},setItem(){throw Error();}};const fallback=intro(broken);fallback.calls[0].onStarted();await fallback.controller.finish();assert.equal(fallback.finishes[0],.6);
});
