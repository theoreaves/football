import {createDrill,startDrill,stepDrill} from '../../../resources/js/arcade/simulation.js';
import {createPassingDrill,stepPassingDrill} from '../../../resources/js/arcade/passing.js';
import {createDrillRecording} from '../../../resources/js/arcade/recording.js';
const player=(id,side,role,ratings={})=>({id,side,role,name:role,number:id%99,ratings:{speed:75,acceleration:80,throwing:100,catching:100,awareness:100,strength:75,tackling:100,coverage:100,...ratings}});
const samples=[];
function fixture(mode,seed,direction=1,spot=30,input=null, receiverRatings={}) {
 const startX=direction===1?10+spot:110-spot;
 const options={seed,direction,exhibition:{startX,goalX:direction===1?110:10,rearX:direction===1?10:110},runner:player(101,'home','RB'),quarterback:player(102,'home','QB'),receivers:[player(103,'home','WR1',receiverRatings),player(104,'home','WR2',receiverRatings)],defenders:[player(201,'away','CB1'),player(202,'away','S1')],defenderCount:2};
 const state=startDrill(mode==='running'?createDrill(options):createPassingDrill(options));
 const entries=mode==='running'?[['runner',state.runner],...state.defenders.map((a,i)=>[`defender:${i}`,a])]:[['qb',state.quarterback],...state.receivers.map((a,i)=>[`receiver:${i}`,a]),...state.defenders.map((a,i)=>[`defender:${i}`,a])];
 const actors=Object.fromEntries(entries.map(([key,a])=>[key.replace(':','_'),{replay_key:key,player_id:a.id,side:a.side,role:a.role,x:a.x,y:a.z}]));
 const roster={home:{players:{}},away:{players:{}}}; for(const [,a]of entries)roster[a.side].players[a.role]=a;
 const recorder=createDrillRecording(state);
 for(let i=0;i<1200&&!state.result;i++){
  const command=input?input(state,i):{x:direction};
  (mode==='running'?stepDrill:stepPassingDrill)(state,command);recorder.capture(state);
 }
 if(state.result?.reason!=='timeout')samples.push({name:`${mode}-${seed}-${direction}-${spot}-${samples.length}`,state:{status:'playing',phase:'scrimmage',possession:'home',quarter:direction===1?1:2,spot,version:4,clock:900,controls:{home:'human',away:'cpu'}},roster,actors,recording:recorder.export()});
}
for(const direction of [1,-1]){
 fixture('running',1,direction);
 fixture('running',10,direction,30,(s,i)=>({x:direction,juke:i%90===0?1:0}));
 fixture('running',2,direction,99);
 fixture('running',3,direction,1,()=>({x:-direction}));
 fixture('running',4,direction,30,()=>({z:1}));
 fixture('passing',1,direction,96,(s,i)=>i===20?{pass:{x:s.startX+direction*6,z:22}}:{x:s.controlledKey==='qb'?0:direction});
 fixture('passing',5,direction,30,()=>({}));
 fixture('passing',6,direction,1,()=>({}));
 fixture('passing',7,direction,30,(s,i)=>i===1?{pass:{x:s.startX+direction*30,z:50}}:{});
 for(let seed=1;seed<=30;seed++)fixture('passing',seed,direction,30,(s,i)=>i===20?{pass:{x:s.startX+direction*6,z:22}}:{x:s.controlledKey==='qb'?0:direction});
}
for(let seed=1;seed<=60;seed++)fixture('passing',seed,1,30,(s,i)=>i===1?{pass:{x:s.startX+4,z:19}}:{});
for(const x of [3,4,5,6,7,8])for(const z of [17,18,19,20,21,22,23,24])fixture('passing',9,1,30,(s,i)=>i===1?{pass:{x:s.startX+x,z}}:{});
for(let seed=1;seed<=20;seed++)for(const x of [5,7,9])fixture('passing',seed,1,30,(s,i)=>i===1?{pass:{x:s.startX+x,z:18.665}}:{},{speed:0,catching:0});
const unique=new Map();for(const sample of samples){const r=sample.recording;const key=[r.mode,r.direction,r.result.reason,r.result.passOutcome,sample.state.spot,r.events.some(e=>e[2]==='juke'),r.events.find(e=>e[2]==='incomplete')?.[5]?.cause].join('/');if(!unique.has(key))unique.set(key,sample);}
console.log(JSON.stringify([...unique.values()]));
