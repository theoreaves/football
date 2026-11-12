import {refreshSharedCpuSpecial} from '../../resources/js/arcade/presentation-playback.js';
import {wizardSteps} from '../../resources/js/practice/play-wizard.js';
import {applyPresentationPlayback} from '../../resources/js/arcade/presentation-playback.js';
import {resultRefereeSignal} from '../../resources/js/practice/result-referee.js';
import {scoreboardText} from '../../resources/js/practice/scoreboard.js';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';
import {acceptedPlaybackUrl} from '../../resources/js/arcade/presentation-playback.js';
const snapshot={latest_play:{number:8},state:{version:8},flow:{state_hash:'hash8'},urls:{playback_base:'/exhibitions/3/arcade/playback'}};
const result={receipt:{watch:true},play:{number:8},state:{version:8},flow:{state_hash:'hash8'}};
test('watched playback binds the accepted command number, not an unrelated latest snapshot',()=>{
 assert.equal(acceptedPlaybackUrl(snapshot,result),'/exhibitions/3/arcade/playback/8');
 assert.equal(acceptedPlaybackUrl({...snapshot,latest_play:{number:9},state:{version:9}},result),null);
 assert.equal(acceptedPlaybackUrl({...snapshot,flow:{state_hash:'changed-by-other-tab'}},result),null);
 assert.equal(acceptedPlaybackUrl(snapshot,{...result,receipt:{watch:false}}),null);
 assert.equal(acceptedPlaybackUrl(snapshot,{receipt:{watch:true}}),null);
});

test('non-watch refresh preserves actual run result and prior state, not the next passing preview',()=>{
 const before={down:3,distance:26,spot:20,possession:'away',phase:'scrimmage',clock:300,quarter:1,status:'playing',home_score:0,away_score:0};
 const after={...before,down:4};const run={call:'inside_run',passing:false,outcome:'tackle',gain:0};
 const preview={call:'short_pass',passing:true,outcome:'tackle',gain:0};
 const dataset={animation:JSON.stringify(run),beforeState:JSON.stringify(before),afterState:JSON.stringify(after),nextAnimation:JSON.stringify(preview),penaltyResult:'true'};
 applyPresentationPlayback(dataset);
 assert.equal(dataset.autoplay,'false');assert.deepEqual(JSON.parse(dataset.animation),run);assert.deepEqual(JSON.parse(dataset.beforeState),before);assert.deepEqual(JSON.parse(dataset.nextAnimation),preview);
 assert.equal(resultRefereeSignal(JSON.parse(dataset.animation),before,after),null);
 assert.equal(resultRefereeSignal(preview,after,after),'complete','the old preview substitution falsely announced PASS COMPLETE');
});
test('shared recorded scoreboard shows third then fourth, punt fourth then new first, and non-watch canonical first',()=>{
 const source=readFileSync(new URL('../../resources/js/practice/field.js',import.meta.url),'utf8');
 const body=source.match(/const showState = committed => \{([\s\S]*?)\n    \};/)[1];
 assert.match(source,/if\(shared\)showState\(revealed\)/);
 const before={down:3,distance:26,spot:20,possession:'away',phase:'scrimmage',clock:300,quarter:1,status:'playing',home_score:0,away_score:0};
 const fourth={...before,down:4},first={...before,down:1,distance:10,spot:6,possession:'home'};
 const names={home:'Home',away:'Away'};
 for(const [start,end,expectedStart,expectedEnd] of [[before,fourth,'3rd & 26','4th & 26'],[fourth,first,'4th & 26','1st & 10']]){
   const dataset={};applyPresentationPlayback(dataset,{animation:{call:'punt'},before:start,after:end});
   const nodes=new Map(),root={querySelector(selector){if(!nodes.has(selector))nodes.set(selector,{});return nodes.get(selector);},querySelectorAll(){return [];}};
   let stadiumState;const stadium={updateScoreboard(state){stadiumState=state;}};
   const show=new Function('root','stadium','beforeState','afterState','teamNames','scoreboardText','let revealed;return committed=>{'+body+'};')(root,stadium,JSON.parse(dataset.beforeState),JSON.parse(dataset.afterState),names,scoreboardText);
   show(dataset.autoplay!=='true');assert.ok(nodes.get('[data-situation]').textContent.startsWith(expectedStart));assert.deepEqual(stadiumState,start);
   show(true);assert.ok(nodes.get('[data-situation]').textContent.startsWith(expectedEnd));assert.deepEqual(stadiumState,end);
   applyPresentationPlayback(dataset);show(dataset.autoplay!=='true');assert.ok(nodes.get('[data-situation]').textContent.startsWith(expectedEnd));
 }
});

test('shared CPU punt and kickoff strategy previews select special-team wizard steps without changing human controls',()=>{
 const root={dataset:{cpuSpecial:'false'},querySelector:()=>null};
 for(const call of ['punt','punt_distance','punt_hang','punt_pooch','punt_coffin','kickoff_deep','kickoff_left']){
   root.dataset.cpuSpecial='false';refreshSharedCpuSpecial(root,{call});
   const steps=wizardSteps(false,true,root.dataset.cpuSpecial==='true'?'kickoff':'inside_run');
   assert.deepEqual(steps.map(step=>step.kind),['defense-coverage','review']);
   assert.equal(steps[0].title,'Defense · Special teams');
 }
 refreshSharedCpuSpecial(root,{call:'inside_run'});assert.equal(root.dataset.cpuSpecial,'false');
 assert.equal(wizardSteps(false,true,'inside_run').length,4);
 const human={dataset:{cpuSpecial:'false'},querySelector:()=>({value:'inside_run'})};refreshSharedCpuSpecial(human,{call:'punt'});assert.equal(human.dataset.cpuSpecial,'false');
 refreshSharedCpuSpecial(root,null);assert.equal(root.dataset.cpuSpecial,'false');
});

test('shared repeated punt classification uses canonical CPU plan when no unit animation is needed',()=>{
 const root={dataset:{cpuSpecial:'false',sharedCpuCall:'punt_coffin'},querySelector:()=>null};
 refreshSharedCpuSpecial(root,null);assert.equal(root.dataset.cpuSpecial,'true');
 assert.deepEqual(wizardSteps(false,true,root.dataset.cpuSpecial==='true'?'kickoff':'inside_run').map(step=>step.kind),['defense-coverage','review']);
 root.dataset.cpuSpecial='false';refreshSharedCpuSpecial(root,{call:'inside_run'});assert.equal(root.dataset.cpuSpecial,'true','canonical CPU plan wins over any stale formation track');
 root.dataset.sharedCpuCall='short_pass';refreshSharedCpuSpecial(root,null);assert.equal(root.dataset.cpuSpecial,'false','same-unit ordinary plan resets special metadata');
 const blade=readFileSync(new URL('../../resources/views/exhibitions/partials/play-controls.blade.php',import.meta.url),'utf8');
 assert.match(blade,/@if\(\$playContext && \$cpuOffense && \$cpuPlan\) data-shared-cpu-call="\{\{ \$cpuPlan\['call'\] \}\}"/);
});
