// Synthetic characterization only, not a balance target or a saved-game test.
// Baseline/current paths are separate JS arcade directories. No app bootstrap.
// Usage: node tests/Fixtures/arcade-breakaway-observation.mjs [old/full-team.js] [new/full-team.js]
// Set ARCADE_BREAKAWAY_SUMMARY_ONLY=1 to omit all per-play records.
import {resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const currentPath = fileURLToPath(new URL('../../resources/js/arcade/full-team.js', import.meta.url));
const arguments_ = process.argv.slice(2);
const old = await import(pathToFileURL(resolve(arguments_[0] ?? currentPath)));
const next = arguments_[1] ? await import(pathToFileURL(resolve(arguments_[1]))) : old;
const configRoles = {
    offense: {QB:[-5,26.7],C:[-1,26.7],LG:[-1,24.5],RG:[-1,28.9],LT:[-1,22.3],RT:[-1,31.1],RB:[-7,29],TE:[-1,34],WR1:[-1,9],WR2:[-1,43],WR3:[-3,16]},
    defense: {DE1:[1,22],DT1:[1,25],DT2:[1,28],DE2:[1,31],LB1:[5,22],LB2:[5,27],LB3:[5,33],CB1:[3,9],CB2:[3,43],S1:[11,20],S2:[12,34]},
};
const seeds = [1,2,3,4,8,15,42,82,103,211,519,832,1337,1776,2026,9001];
const profiles = {
    even: {},
    runner_edge: {runner:{speed:88,acceleration:86,strength:76},line:{blocking:78}, defense:{speed:64,acceleration:65,awareness:62,tackling:63}},
    defense_edge: {runner:{speed:68,acceleration:68,strength:65},line:{blocking:67}, defense:{speed:84,acceleration:81,awareness:83,tackling:84}},
};

function setup(call, direction, seed, profile) {
    const startX = direction === 1 ? 50 : 70, players = [];
    for (const [unit, roles] of Object.entries(configRoles)) for (const [role,[x,z]] of Object.entries(roles)) {
        const ratings = {speed:70,acceleration:70,awareness:70,strength:70,blocking:70,tackling:70,coverage:70,throwing:85,catching:80,stamina:80};
        Object.assign(ratings, unit === 'defense' ? profile.defense : role === 'RB' ? profile.runner : /^(C|[LR][GT])$/.test(role) ? profile.line : null);
        const key = unit + '_' + role;
        players.push({key,id:players.length+1,name:key,role,side:unit==='offense'?'home':'away',x:startX+x*direction,z,ratings});
    }
    return {fullTeam:true,call,players,offenseSide:'home',direction,seed,speedScale:.925,
        exhibition:{startX,goalX:direction===1?110:10,rearX:direction===1?10:110,firstDownX:startX+direction*10}};
}

const policies = {
    manual_straight: state => ({x:state.direction}),
    manual_call_lane: state => ({
        x:state.direction,
        z: !state.handoffComplete || state.call!=='outside_run' || state.runner.z>=38 ? 0 : .50,
    }),
    qb_only_autonomous: () => ({}),
};
const v2 = runtime => {
    try {
        const state = runtime.createFullTeamPlay({...setup('inside_run',1,seeds[0],profiles.even),tuningVersion:'arcade-fullteam-2'});
        return state.tuningVersion === 'arcade-fullteam-2' && Object.hasOwn(state,'inputActorKey');
    } catch { return false; }
};
const supportsAutonomous = v2(next);
const round = x => Math.round(x * 1000) / 1000;

function measure(runtime, setup, policy) {
    const state = runtime.startFullTeamPlay(runtime.createFullTeamPlay(structuredClone(setup)));
    let pursuitAt = null, nearestAfterMesh = null, atTen = null, atTwenty = null, controlTransferredFromQB = false;
    let pursuitDefenderTicks = 0, blockedDefenderTicks = 0, heldRunnerTicks = 0;
    let previousRead = null;
    const laneReads = [];
    const sample = runner => {
        const byGap = state.defenders.map(d => ({ defender:d, distance:Math.hypot(d.x-runner.x,d.z-runner.z) })).sort((a,b)=>a.distance-b.distance);
        const closest = byGap[0];
        return {distance:round(closest.distance),role:closest.defender.role,
            blocker:closest.defender.blockedBy ?? null,
            defendersAhead:state.defenders.filter(d=>(d.x-runner.x)*state.direction>=0).length};
    };
    for (let i=0; i<2400 && !state.result; i++) {
        runtime.stepFullTeamPlay(state,policy(state));
        const runner = state.actorByKey[state.ball.holderKey] ?? state.runner;
        controlTransferredFromQB ||= Object.hasOwn(state,'inputActorKey') ? state.inputActorKey !== null && state.inputActorKey !== state.quarterback.key : state.controlledKey !== state.quarterback.key;
        if (!state.handoffComplete || state.result || runner.key === state.quarterback.key) continue;
        ++heldRunnerTicks;
        const pursuing = state.defenders.filter(d=>d.assignment==='pursuit'&&!d.blockedBy).length;
        if (pursuing > 0) pursuitAt ??= state.tick;
        pursuitDefenderTicks += pursuing;
        blockedDefenderTicks += state.defenders.filter(d=>d.blockedBy).length;
        const proximity = sample(runner);
        if (state.carrierRead && state.carrierRead !== previousRead && laneReads.length < 8) {
            previousRead = state.carrierRead;
            laneReads.push({tick:state.tick, advance:round((runner.x-setup.exhibition.startX)*state.direction),
                lateralTarget:round(state.carrierRead.target.z-runner.z), nearby:proximity,
                unblockedPursuers:pursuing});
        }
        if (!nearestAfterMesh || proximity.distance < nearestAfterMesh.distance) nearestAfterMesh = proximity;
        const yards = (runner.x-setup.exhibition.startX)*state.direction;
        if (yards >= 10) atTen ??= proximity;
        if (yards >= 20) atTwenty ??= proximity;
    }
    if (!state.result) throw new Error('Run did not finish within the synthetic limit.');
    const events = state.events;
    const firstContact = events.find(e => e.type==='tackle'||e.type==='broken_tackle');
    const mesh = events.find(e=>e.type==='handoff');
    const firstContactYards = firstContact?.snapshot?.ball?.x == null ? null
        : round((firstContact.snapshot.ball.x-setup.exhibition.startX)*state.direction);
    return {yards:round(state.result.yards), reason:state.result.reason, elapsed:round(state.result.time),
        handoffTick:mesh?.tick ?? null, controlTransferredFromQB, firstPursuitTick:pursuitAt, firstContactTick:firstContact?.tick ?? null,
        firstContactRole:firstContact?.targetKey ?? null, firstContactYards, blocks:events.filter(e=>e.type==='block').length,
        uniqueBlocked: new Set(events.filter(e=>e.type==='block').map(e=>e.targetKey)).size,
        sheds:events.filter(e=>e.type==='block_shed').length, contacts:events.filter(e=>e.type==='tackle'||e.type==='broken_tackle').length,
        brokenTackles:state.brokenTackles, nearestAfterMesh, atTen, atTwenty, laneReads, heldRunnerTicks, pursuitDefenderTicks, blockedDefenderTicks};
}

const samples = {};
for (const [name, runtime, selectedPolicies] of [
    ['baseline',old,['manual_straight','manual_call_lane']],
    ['current',next,supportsAutonomous ? ['qb_only_autonomous'] : []],
]) {
    for (const policy of selectedPolicies) {
        samples[name+':'+policy] = [];
        for (const call of ['inside_run','outside_run']) for (const [profile, ratings] of Object.entries(profiles)) {
            for (const direction of [1,-1]) for (const seed of seeds) {
                samples[name+':'+policy].push({call,profile,direction,seed,
                    ...measure(runtime, {...setup(call,direction,seed,ratings), ...(policy==='qb_only_autonomous' ? {tuningVersion:'arcade-fullteam-2'} : {})},policies[policy])});
            }
        }
    }
}

function summary(rows) {
    const mean = k => round(rows.reduce((s,row)=>s+row[k],0)/rows.length);
    const meanOptional = values => values.length ? round(values.reduce((s,value)=>s+value,0)/values.length) : null;
    const breakouts = rows.filter(r=>r.yards>=20);
    const fraction = key => meanOptional(rows.filter(r=>r.heldRunnerTicks).map(r=>r[key]/(11*r.heldRunnerTicks)));
    return {n:rows.length,meanYards:mean('yards'),atLeast10:rows.filter(r=>r.yards>=10).length,
        breakout20:breakouts.length,breakout40:rows.filter(r=>r.yards>=40).length,
        touchdown:rows.filter(r=>r.reason==='touchdown').length,
        noContactBreakout20:breakouts.filter(r=>!r.contacts).length,
        meanFirstContactYards:meanOptional(rows.map(r=>r.firstContactYards).filter(x=>x!==null)),
        meanBreakoutFirstContactYards:meanOptional(breakouts.map(r=>r.firstContactYards).filter(x=>x!==null)),
        meanUnblockedPursuerShare:fraction('pursuitDefenderTicks'),meanBlockedDefenderShare:fraction('blockedDefenderTicks'),
        meanBreakoutNearestDefenderAt20:meanOptional(breakouts.map(r=>r.atTwenty?.distance).filter(x=>x!==undefined)),
        meanBreakoutDefendersAheadAt20:meanOptional(breakouts.map(r=>r.atTwenty?.defendersAhead).filter(x=>x!==undefined)),
        meanBlocks:mean('blocks'),meanContacts:mean('contacts'),meanBrokenTackles:mean('brokenTackles'),
        meanSheds:mean('sheds'),meanBlockedDefenderTicks:mean('blockedDefenderTicks'),meanPursuitDefenderTicks:mean('pursuitDefenderTicks'),
        controlLeftQB:rows.filter(r=>r.controlTransferredFromQB).length, noHandoff:rows.filter(r=>r.handoffTick===null).length};
}

const results = {};
for (const [name,rows] of Object.entries(samples)) {
    results[name] = {overall:summary(rows),byCallAndProfile:[],directionDisagreements:[]};
    for (const call of ['inside_run','outside_run']) for (const profile of Object.keys(profiles)) {
        const matching = rows.filter(r=>r.call===call&&r.profile===profile);
        results[name].byCallAndProfile.push({call,profile,...summary(matching)});
        for (const seed of seeds) {
            const [a,b] = matching.filter(r=>r.seed===seed);
            if (a.reason!==b.reason || Math.abs(a.yards-b.yards)>.01) results[name].directionDisagreements.push({call,profile,seed,forward:[a.reason,a.yards],reverse:[b.reason,b.yards]});
        }
    }
}
const comparisons = {};
const autonomy = samples['current:qb_only_autonomous'];
if (autonomy) {
    for (const policy of ['manual_straight','manual_call_lane']) {
        const manual = samples['baseline:'+policy];
        comparisons[policy] = {
            matched:autonomy.length,
            newlyBreakout20:autonomy.filter((r,i)=>r.yards>=20 && manual[i].yards<20).length,
            lostBreakout20:autonomy.filter((r,i)=>r.yards<20 && manual[i].yards>=20).length,
            meanYardDelta:round(autonomy.reduce((sum,r,i)=>sum+r.yards-manual[i].yards,0)/autonomy.length),
        };
    }
}
console.log(JSON.stringify({scope:'Synthetic inside/outside runs from own 40; 16 fixed non-random seeds, three rating profiles, mirrored directions. No official saves or claims about live balance.',versions:{catalog:old.FULL_TEAM_VERSION,baseline:'arcade-fullteam-1',current:supportsAutonomous?'arcade-fullteam-2':null},seeds,results,comparisons,...(process.env.ARCADE_BREAKAWAY_SUMMARY_ONLY ? {} : {samples})},null,2));
