// Stage B only: synthetic roster, visible-state scripted inputs, one live snap.
// No RNG inspection, speculative simulation, Coach outcomes, or balance tuning.
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {performance} from 'node:perf_hooks';
import {createFullTeamPlay, startFullTeamPlay, pauseFullTeamPlay, stepFullTeamPlay, getFullTeamPassTrajectory} from '../../resources/js/arcade/full-team.js';
import {createDrillRecording} from '../../resources/js/arcade/recording.js';
import {actorSpeed, rating} from '../../resources/js/arcade/tuning.js';
const clamp = (n, a, b) => Math.max(a, Math.min(b, n));
const distance = (a, b) => Math.hypot(a.x-b.x, a.z-b.z);
function routeLead(receiver, seconds) {
    let target = {x:receiver.x,z:receiver.z}, remaining = actorSpeed(receiver, .925)*seconds;
    for (const point of receiver.route.points.slice(receiver.route.index)) {
        const d = distance(point,target);
        if (d > remaining) return {x:target.x+(point.x-target.x)*remaining/d,z:target.z+(point.z-target.z)*remaining/d};
        remaining-=d; target={...point};
    }
    return target;
}
function candidates(state, outlet = false) {
    return state.receivers.filter(r=>!outlet || ['TE','RB'].includes(r.role)).map(receiver=>{
        let duration=.5,target;
        for(let i=0;i<4;i++) {
            target=routeLead(receiver,duration);
            duration=clamp(distance(state.quarterback,target)/(21+rating(state.quarterback,'throwing')*.1),.4,1.8);
        }
        target={x:clamp(target.x,.5,119.5),z:clamp(target.z,.5,52.83)};
        const trajectory=getFullTeamPassTrajectory(state,target,{samples:16});
        if(!trajectory.length)return null;
        const projected = (d,t) => ({x:d.x+d.vx*Math.min(t,.3),z:d.z+d.vz*Math.min(t,.3)});
        const separation=Math.min(...state.defenders.map(d=>distance(target,projected(d,duration))));
        const laneClearance=Math.min(...trajectory.slice(8).flatMap((p,i)=>p.y<=2.55 ? state.defenders.map(d=>distance(p,projected(d,duration*(i+8)/15))) : []));
        const gain=(target.x-state.startX)*state.direction;
        const desired={short_pass:5,medium_pass:10,deep_pass:20}[state.call];
        return {key:receiver.key,target,duration,separation,laneClearance,gain,score:Math.min(separation,laneClearance)-Math.abs(gain-desired)*.12};
    }).filter(Boolean).sort((a,b)=>b.score-a.score || a.key.localeCompare(b.key));
}
function createPolicy(profile) {
    let decision=null;
    return {get decision(){return decision;}, input(state){
        if(profile==='hold')return {};
        if(state.mode==='running' && !state.handoffComplete)return {};
        if(state.controlledKey!==state.quarterback.key) {
            // Follow the called run lane, then take a nearby visible gap, without predicting RNG.
            const desired=state.call==='outside_run'?12:state.runner.z;
            const threats=state.defenders.filter(d=>(d.x-state.runner.x)*state.direction>-1 && distance(d,state.runner)<4);
            const avoid=threats.reduce((n,d)=>n+(state.runner.z<d.z?-1:1)/Math.max(.5,distance(d,state.runner)),0);
            return {x:state.direction,z:clamp((desired-state.runner.z)*.2+avoid,-.65,.65)};
        }
        if(state.mode==='running' || state.ball.phase!=='held')return {};
        const earliest={short_pass:42,medium_pass:66,deep_pass:90}[state.call];
        const pressure=Math.min(...state.defenders.map(d=>distance(d,state.quarterback)));
        if(state.tick>=earliest && state.tick%6===0) {
            const best=candidates(state,profile==='outlet')[0];
            if(best && (Math.min(best.separation,best.laneClearance)>=1.8 || pressure<2.5 || state.tick>=168)) {
                decision={kind:'pass',tick:state.tick,pressure,receiverSeparationNow:Math.min(...state.defenders.map(d=>distance(d,state.actorByKey[best.key]))),...best};return {pass:best.target};
            }
        }
        if(state.tick>=180){decision??={kind:'scramble',tick:state.tick};return {x:state.direction,z:.25};}
        return {};
    }};
}
export function fullteamBenchmark(setup, profile='decisions') {
    const state=startFullTeamPlay(createFullTeamPlay(setup)), recorder=createDrillRecording(state,{appearance:setup.appearance});
    const policy=createPolicy(profile);const stepTimes=[];let simMs=0,captureMs=0;
    for(let tick=0;tick<1200 && state.status==='running';tick++) {
        if(profile==='pause' && tick===30) {
            pauseFullTeamPlay(state);
            for(let frame=0;frame<300;frame++){stepFullTeamPlay(state,{});recorder.capture(state);}
            startFullTeamPlay(state);
        }
        let start=performance.now();stepFullTeamPlay(state,policy.input(state));const took=performance.now()-start;simMs+=took;stepTimes.push(took);
        start=performance.now();recorder.capture(state);captureMs+=performance.now()-start;
    }
    if(!state.result)throw new Error('Full-team play did not finish within 1200 ticks');
    const start=performance.now(),recording=recorder.export(),serialized=JSON.stringify(recording),serializationMs=performance.now()-start;
    const nodes=value=>1+(value && typeof value==='object'?Object.values(value).reduce((n,v)=>n+nodes(v),0):0);
    const events=state.events, caught=events.find(e=>e.type==='catch'), tackled=events.find(e=>e.type==='tackle'), blocks=events.filter(e=>e.type==='block');
    const quantile=(arr,q)=>arr.length?[...arr].sort((a,b)=>a-b)[Math.min(arr.length-1,Math.floor(arr.length*q))]:null;
    const sample = time => {
        let left=recording.frames[0];
        for(const right of recording.frames) {
            if(right[1]>=time) {
                const t=right[1]===left[1]?1:(time-left[1])/(right[1]-left[1]);
                return left[3].map((p,i)=>[p[0]+(right[3][i][0]-p[0])*t,p[1]+(right[3][i][1]-p[1])*t]);
            }
            left=right;
        }
        return left[3];
    };
    const contactDistances=recording.events.filter(e=>['block','block_shed','pressure'].includes(e[2])).map(e=>{
        const positions=sample(e[1]),a=positions[e[3]],b=positions[e[4]];
        return {type:e[2],tick:e[0],time:e[1]/1e6,actor:recording.actors[e[3]].key,target:recording.actors[e[4]].key,distance:Math.hypot(a[0]-b[0],a[1]-b[1])/100};
    });
    const eventCounts=Object.fromEntries([...new Set(events.map(e=>e.type))].map(type=>[type,events.filter(e=>e.type===type).length]));
    const blockDurations=blocks.map(b=>{
        const shed=events.find(e=>e.type==='block_shed' && e.actorKey===b.actorKey && e.targetKey===b.targetKey && e.time>=b.time);
        return {blocker:b.actorKey,defender:b.targetKey,seconds:(shed?.time??state.elapsed)-b.time,censored:!shed};
    });
    const catchActors=caught?.snapshot?.actors;
    const caughtActor=catchActors?.find(a=>a.key===caught.actorKey);
    const catchSeparation=caughtActor?Math.min(...catchActors.filter(a=>a.key.startsWith('defense_')).map(a=>distance(a,caughtActor))):null;
    const eventSummary=e=>e?Object.fromEntries(Object.entries(e).filter(([key])=>key!=='snapshot')):null;
    const metrics={call:state.call,direction:state.direction,seed:state.seed,profile,reason:state.result.reason,passOutcome:state.passOutcome,
        gain:state.result.yards,seconds:state.elapsed,ticks:state.tick,actorCount:state.actors.length,
        firstBlockSeconds:blocks[0]?.time??null,blockCount:blocks.length,blockShedCount:events.filter(e=>e.type==='block_shed').length,
        blockDurations,catchSeparation,blockPairs:blocks.map(e=>({time:e.time,blocker:e.actorKey,defender:e.targetKey})),
        handoff:eventSummary(events.find(e=>e.type==='handoff')),catch:eventSummary(caught),tackle:eventSummary(tackled),
        yac:caught?(state.runner.x-(caught.contact?.x??state.runner.x))*state.direction:null,
        decision:policy.decision,contactDistances,eventCounts,frames:recording.frames.length,events:recording.events.length,bytes:Buffer.byteLength(serialized),nodes:nodes(recording),truncated:recording.truncated,
        recordingHash:createHash('sha256').update(serialized).digest('hex'),
        timing:{simulationMs:simMs,captureMs,serializationMs,stepP50Ms:quantile(stepTimes,.5),stepP95Ms:quantile(stepTimes,.95),stepMaxMs:Math.max(...stepTimes)}};
    return {recording,metrics};
}
const input=JSON.parse(readFileSync(0,'utf8'));
process.stdout.write(JSON.stringify(fullteamBenchmark(input.setup,input.profile)));
