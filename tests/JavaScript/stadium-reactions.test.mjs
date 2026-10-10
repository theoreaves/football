import test from 'node:test';
import assert from 'node:assert/strict';
import {soundCues,crossedCues,presentationSoundTime} from '../../resources/js/practice/sound-cues.js';
import {recordedStadiumReactions} from '../../resources/js/practice/recorded-stadium-reactions.js';
import {stadiumAudio} from '../../resources/js/practice/stadium-audio.js';
const state={phase:'scrimmage',possession:'away',down:2,quarter:1,home_score:0,away_score:0,stats:{home:{turnovers:0},away:{turnovers:0}}};
const animation={call:'inside_run',duration:9,reveal_at:8,events:[[8,'Intercepted']]};
test('home turnover cues require a committed defensive takeaway or turnover on downs',()=>{
 const after={...state,possession:'home',stats:{home:{turnovers:0},away:{turnovers:1}}};
 assert.equal(soundCues(animation,state,after).find(c=>c.type==='home_turnover').time,8.2);
 assert.ok(!soundCues(animation,state,{...after,penalty_pending:true}).some(c=>c.type==='home_turnover'));
 assert.ok(!soundCues(animation,{...state,possession:'home'},{...state,stats:{home:{turnovers:1},away:{turnovers:0}}}).some(c=>c.type==='home_turnover'));
 assert.ok(!soundCues({...animation,call:'punt'},state,{...state,possession:'home'}).some(c=>c.type==='home_turnover'));
 assert.ok(soundCues({...animation,events:[[8,'Turnover on downs']]},state,{...state,possession:'home'}).some(c=>c.type==='home_turnover'));
 const pickSix=soundCues(animation,state,{...after,home_score:6});assert.equal(pickSix.filter(c=>['touchdown','home_turnover'].includes(c.type)).length,1);
});
test('flagged results wait until after the referee presentation, without duplicate or seek cues',()=>{
 const cues=soundCues(animation,state,{...state,home_score:6},{accepted:false});const cue=cues.find(c=>c.type==='touchdown');assert.ok(Math.abs(cue.time-9.4)<1e-9);
 assert.equal(crossedCues(cues,0,9).filter(c=>c.type==='touchdown').length,0);assert.equal(crossedCues(cues,9,9.5).filter(c=>c.type==='touchdown').length,1);assert.deepEqual(crossedCues(cues,9.5,9.5),[]);assert.deepEqual(crossedCues(cues,10,1),[]);
 assert.ok(!soundCues({...animation,no_snap:true}, {...state,down:3},state).some(c=>c.type==='third_down'));
});
function fixture(fetch){const sources=[],requests=[],gains=[];const context={state:'running',currentTime:0,decodeAudioData:async()=>({duration:3}),createGain(){const n={gain:{value:0,setTargetAtTime(v){this.value=v;}},connect(to){this.to=to;},disconnect(){}};gains.push(n);return n;},createBufferSource(){const n={connect(){},disconnect(){this.disconnected=true;},start(){},stop(){this.stopped=true;this.onended?.();}};sources.push(n);return n;},close:async()=>{}};const environment={fetch:async url=>{requests.push(url);return fetch?fetch(url):{ok:true,arrayBuffer:async()=>new ArrayBuffer(8)};}};return {context,environment,sources,requests,gains,player:recordedStadiumReactions(context,{},environment,'/custom/stadium/')};}
test('stadium recordings load once, replace overlapping stings and stop when disabled',async()=>{
 const f=fixture();await f.player.load();await f.player.load();assert.equal(f.requests.length,3);assert.ok(f.requests.every(u=>u.startsWith('/custom/stadium/')));f.player.setActive(true);f.player.play('touchdown');f.player.play('home_turnover');assert.equal(f.sources[0].stopped,true);f.player.setActive(false);assert.equal(f.sources[1].stopped,true);f.player.play('third_down');assert.equal(f.sources.length,2);f.player.dispose();
});
test('late and missing stadium files never queue stale reactions',async()=>{
 let resolve;const response=new Promise(r=>resolve=r),f=fixture(()=>response);f.player.setActive(true);const loading=f.player.load();f.player.play('touchdown');resolve({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)});await loading;assert.equal(f.sources.length,0);f.player.play('touchdown');f.player.dispose();assert.equal(f.sources[0].stopped,true);f.player.setActive(true);f.player.play('touchdown');assert.equal(f.sources.length,1);
 const missing=fixture(()=>({ok:false}));await missing.player.load();missing.player.setActive(true);missing.player.play('home_turnover');assert.equal(missing.sources.length,0);
});
test('Stadium volume, pause and mute control recorded reactions independently of Effects',async()=>{
 const f=fixture(url=>({ok:url.includes('/stadium/'),arrayBuffer:async()=>new ArrayBuffer(8)}));let change,toggle;const input={dataset:{soundVolume:'stadium'},addEventListener(t,fn){change=fn;}};const button={addEventListener(t,fn){toggle=fn;},setAttribute(){}};const root={dataset:{},querySelector:s=>s==='[data-sound-toggle]'?button:null,querySelectorAll:()=>[input],addEventListener(){},removeEventListener(){}};
 const audio=stadiumAudio(root,{...f.environment,AudioContext:class{constructor(){return f.context;}},localStorage:{getItem:()=>null,setItem(){}}});audio.setActive(true);await audio.prepare();audio.play({type:'touchdown'});assert.equal(f.sources.length,1);assert.equal(f.gains[4].gain.value,.5);input.value=0;change();assert.equal(f.sources[0].stopped,true);audio.play({type:'home_turnover'});assert.equal(f.sources.length,1);input.value=50;change();audio.play({type:'home_turnover'});audio.setActive(false);assert.equal(f.sources[1].stopped,true);audio.setActive(true);audio.play({type:'touchdown'});toggle();assert.equal(f.sources[2].stopped,true);audio.dispose();
});

test('celebration to result transitions cannot replay crossed cues',()=>{
 const cues=[{time:9.2,type:'touchdown'}];let previous=9;
 for(const [phase,post] of [['celebration',.3],['celebration',2],['result',0],['result',.3]]) {
   const current=presentationSoundTime(phase,9,9,post,previous);
   assert.equal(crossedCues(cues,previous,current).length,previous===9?1:0);previous=current;
 }
 assert.equal(presentationSoundTime('set',0,9,0,-2),-1);
 assert.equal(presentationSoundTime('live',3,9,0,2),3);
});

test('third-down horn follows a settled visiting second-down result between downs',()=>{
 const after={...state,down:3};
 const cues=soundCues(animation,state,after);const third=cues.find(c=>c.type==='third_down');
 assert.equal(third.time,9.6);
 assert.ok(!crossedCues(cues,-2,9).some(c=>c.type==='third_down'));
 assert.equal(crossedCues(cues,9,9.7).filter(c=>c.type==='third_down').length,1);
 assert.ok(!crossedCues(cues,9.7,10).some(c=>c.type==='third_down'));
 for(const changed of [{...after,penalty_pending:true},{...after,down:1},{...after,down:2},{...after,possession:'home'},{...after,status:'final'},{...after,phase:'extra_point'}])assert.ok(!soundCues(animation,state,changed).some(c=>c.type==='third_down'));
 assert.ok(!soundCues(animation,{...state,possession:'home'},{...after,possession:'home'}).some(c=>c.type==='third_down'));
 assert.ok(!soundCues(animation,{...state,down:3},{...after,down:4}).some(c=>c.type==='third_down'));
 const flagged=soundCues(animation,state,after,{accepted:false}).find(c=>c.type==='third_down');assert.ok(flagged.time>9.6);
});
