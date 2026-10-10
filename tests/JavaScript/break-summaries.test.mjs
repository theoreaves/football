import test from 'node:test';
import assert from 'node:assert/strict';
import {completedDrives,latestBreakSummary,periodSummary,clockText} from '../../resources/js/practice/break-summaries.js';
import {recordedStadiumReactions} from '../../resources/js/practice/recorded-stadium-reactions.js';
import {stadiumAudio} from '../../resources/js/practice/stadium-audio.js';
const zero=()=>({plays:0,yards:0,turnovers:0,penalties:0,penalty_yards:0});
const state=(changes={})=>({phase:'scrimmage',possession:'home',quarter:1,clock:600,status:'playing',home_score:0,away_score:0,stats:{home:zero(),away:zero()},...changes});
function play(number,before,changes={},fields={}){return {number,call:'inside_run',outcome:'tackle',clock_seconds:40,before,after:{...before,...changes},...fields};}
test('drive recaps use saved stat deltas and clock time and exclude conversion/kickoff plays',()=>{
 const a=state(),b=state({clock:560,stats:{home:{...zero(),plays:1,yards:12},away:zero()}}),c=state({clock:520,phase:'extra_point',home_score:6,stats:{home:{...zero(),plays:2,yards:32},away:zero()}});
 const history=[play(1,a,b),play(2,b,c,{outcome:'touchdown'}),play(3,c,{phase:'kickoff',home_score:7},{call:'extra_point',clock_seconds:0})];
 assert.deepEqual(completedDrives(history),[{side:'home',plays:2,yards:32,seconds:80,startQuarter:1,startClock:600,number:2,result:'Touchdown'}]);
 assert.equal(latestBreakSummary(history),null);assert.equal(latestBreakSummary(history.slice(0,2)).result,'Touchdown');
 assert.equal(clockText(80),'1:20');
});
test('a drive spans Q1/Q2 and ends at halftime, without counting reset clocks as time gained',()=>{
 const a=state({clock:10}),b=state({quarter:2,clock:600,stats:{home:{...zero(),plays:1,yards:5},away:zero()}}),c={...b,clock:30},d={...c,quarter:3,clock:600,phase:'kickoff',possession:'away'};
 const history=[play(1,a,b,{clock_seconds:40}),play(2,b,c,{clock_seconds:570}),play(3,c,d,{call:'clock_event',no_snap:true,clock_seconds:30})];
 assert.equal(completedDrives(history).length,1);assert.equal(completedDrives(history)[0].seconds,610);assert.equal(completedDrives(history)[0].result,'End of half');
 assert.equal(periodSummary(history,1).home.possession,10);assert.equal(periodSummary(history,2,true).home.possession,610);
});
test('punts, field goals, takeaways and downs end drives while pending decisions do not',()=>{
 for(const [fields,changes,label] of [[{call:'punt'},{phase:'scrimmage',possession:'away'},'Punt'],[{call:'field_goal',outcome:'field_goal_good'},{phase:'kickoff',home_score:3},'Field goal'],[{call:'field_goal',outcome:'field_goal_missed'},{possession:'away'},'Missed / blocked field goal'],[{outcome:'interception'},{possession:'away',stats:{home:{...zero(),turnovers:1},away:zero()}},'Turnover'],[{}, {possession:'away'},'Turnover on downs']]){
 const p=play(1,state(),changes,fields);assert.equal(latestBreakSummary([p]).result,label);assert.equal(latestBreakSummary([{...p,after:{...p.after,penalty_pending:true}}]),null);
 }
});
test('quarter summaries reconcile with snapshots and halftime combines only first-half periods',()=>{
 const a=state(),b=state({quarter:2,stats:{home:{...zero(),plays:1,yards:-3,penalties:1,penalty_yards:5},away:zero()}}),c={...b,quarter:3,home_score:3,stats:{home:{...b.stats.home,plays:2,yards:20},away:zero()}},d={...c,quarter:4,home_score:10};
 const history=[play(1,a,b),play(2,b,c),play(3,c,d)];const snapshot=JSON.stringify(history);assert.equal(periodSummary(history,1).home.yards,-3);assert.equal(periodSummary(history,2).home.yards,23);assert.equal(periodSummary(history,2,true).home.yards,20);assert.equal(periodSummary(history,2,true).home.points,3);assert.equal(periodSummary(history,2,true).home.penalties,1);assert.equal(JSON.stringify(history),snapshot);
});
test('a recap can await natural sting completion or interruption without leaving a pending promise',async()=>{
 let source;const context={state:'running',currentTime:0,decodeAudioData:async()=>({duration:2}),createGain:()=>({gain:{value:0},connect(){},disconnect(){}}),createBufferSource(){return source={connect(){},disconnect(){},start(){},stop(){this.onended?.();}};}};
 const recordings=recordedStadiumReactions(context,{}, {fetch:async()=>({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)})});await recordings.load();recordings.setActive(true);recordings.play('touchdown');let ended=false;const pending=recordings.whenFinished().then(()=>ended=true);await Promise.resolve();assert.equal(ended,false);source.onended();await pending;assert.equal(ended,true);recordings.play('home_turnover');const stopped=recordings.whenFinished();recordings.setActive(false);await stopped;recordings.dispose();
});
test('break music uses Stadium volume, pauses on mute and fades before continuing',async()=>{
 const instances=[],timers=new Map();let id=0,change;
 class Audio{constructor(url){this.url=url;this.paused=true;instances.push(this);}addEventListener(){}play(){this.paused=false;return Promise.resolve();}pause(){this.paused=true;}removeAttribute(){}load(){}}
 const input={dataset:{soundVolume:'stadium'},addEventListener(t,fn){change=fn;}};const root={dataset:{stadiumAudioBase:'/custom/stadium'},querySelector:()=>null,querySelectorAll:()=>[input],addEventListener(){},removeEventListener(){}};
 const audio=stadiumAudio(root,{Audio,localStorage:{getItem:()=>null,setItem(){}},setTimeout:fn=>{timers.set(++id,fn);return id;},clearTimeout:k=>timers.delete(k)});
 audio.startBreakMusic('drive');await Promise.resolve();assert.equal(instances[0].url,'/custom/stadium/drive-break.mp3');assert.equal(instances[0].volume,.3);
 input.value=0;change();assert.equal(instances[0].paused,true);input.value=50;change();await Promise.resolve();assert.equal(instances[0].paused,false);
 const closing=audio.finishBreakMusic();assert.equal(instances[0].paused,false);while(timers.size){const [k,fn]=timers.entries().next().value;timers.delete(k);fn();}await closing;assert.equal(instances[0].paused,true);
 audio.startBreakMusic('halftime');assert.equal(instances[1].url,'/custom/stadium/halftime-break.mp3');audio.dispose();assert.equal(instances[1].paused,true);
});

test('a final drive is closed without inventing a possession change',()=>{
 const p=play(1,state(),{status:'final',clock:0});assert.equal(latestBreakSummary([p]).result,'End of game');
 const safety=play(1,state(),{phase:'kickoff',away_score:2},{outcome:'safety'});assert.equal(latestBreakSummary([safety]).result,'Safety');
});

test('recap Continue waits for the music fade and exposes the drive result and halftime totals',async()=>{
 const {mountBreakDialogs}=await import('../../resources/js/practice/break-dialogs.js');
 const node=()=>({dataset:{},events:{},children:[],append(...children){this.children.push(...children);},addEventListener(k,fn){this.events[k]=fn;}});
 const previous=globalThis.document;globalThis.document={createElement:node};
 try{
  const a=state({quarter:2}),p=play(1,a,{quarter:3,phase:'kickoff',home_score:3,stats:{home:{...zero(),plays:1,yards:25},away:zero()}},{call:'field_goal',outcome:'field_goal_good'});
  const result=node(),dl=node(),heading=node(),body=node(),team=node();team.dataset.driveSide='home';const dialogs=[];
  for(const kind of ['drive','halftime']){const dialog=node(),form=node(),button=node(),music=node();dialog.dataset={breakKind:kind,breakQuarter:'2'};dialog.querySelector=s=>({'[data-drive-result]':result,'[data-drive-stats]':dl,'[data-period-heading]':heading,tbody:body,'form[method="dialog"]':form,'form[method="dialog"] button':button,'[data-break-play]':music}[s]);dialog.querySelectorAll=()=>[team];dialog.close=()=>{dialog.closed=true;dialog.events.close?.();};dialogs.push({dialog,form,button,music});}
  const root={querySelector:s=>({'[data-drive-dialog]':dialogs[0].dialog,'[data-quarter-dialog]':dialogs[1].dialog,'[data-break-history]':{textContent:JSON.stringify([p])}}[s])};
  let resolveFade;const starts=[];let unlocks=0;const mounted=mountBreakDialogs(root,{startBreakMusic:k=>starts.push(k),unlockBreakMusic:()=>unlocks++,finishBreakMusic:seconds=>seconds===0?Promise.resolve():new Promise(r=>resolveFade=r)});
  assert.equal(result.textContent,'Field goal');assert.equal(team.hidden,false);assert.equal(heading.textContent,'First-half team stats');assert.equal(body.children.find(row=>row.children[0].textContent==='Yards').children[2].textContent,25);
  mounted.startDrive();mounted.startQuarter();assert.deepEqual(starts,['drive','halftime']);dialogs[0].music.events.click();assert.equal(unlocks,1);
  let prevented=false;dialogs[0].form.events.submit({preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(dialogs[0].button.disabled,true);assert.equal(dialogs[0].dialog.closed,undefined);resolveFade();await new Promise(r=>setImmediate(r));assert.equal(dialogs[0].dialog.closed,true);assert.equal(dialogs[0].button.disabled,false);
 }finally{globalThis.document=previous;}
});
