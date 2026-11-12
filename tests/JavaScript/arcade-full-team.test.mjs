import test from 'node:test';
import assert from 'node:assert/strict';
import { createFullTeamPlay, startFullTeamPlay, pauseFullTeamPlay, stepFullTeamPlay,
    getFullTeamPassTrajectory, fullTeamCatchTime, FULL_TEAM_CALLS } from '../../resources/js/arcade/full-team.js';
import { actorSpeed } from '../../resources/js/arcade/tuning.js';
import { STEP_SECONDS } from '../../resources/js/arcade/simulation.js';

const OFFENSE = { QB: [-5,26.7], C: [-1,26.7], LG: [-1,24.5], RG: [-1,28.9], LT: [-1,22.3], RT: [-1,31.1], RB: [-7,29], TE: [-1,34], WR1: [-1,9], WR2: [-1,43], WR3: [-3,16] };
const DEFENSE = { DE1: [1,22], DT1: [1,25], DT2: [1,28], DE2: [1,31], LB1: [5,22], LB2: [5,27], LB3: [5,33], CB1: [3,9], CB2: [3,43], S1: [11,20], S2: [12,34] };
function setup({ call = 'inside_run', direction = 1, seed = 832, ratings = {}, startX = direction === 1 ? 50 : 70 } = {}) {
    const players = [];
    for (const [unit, roles] of Object.entries({offense: OFFENSE, defense: DEFENSE})) for (const [role,[x,z]] of Object.entries(roles)) {
        const key = `${unit}_${role}`;
        players.push({key, id: players.length + 1, name: key, role, side: unit === 'offense' ? 'home' : 'away', x: startX + direction*x, z,
            ratings: {speed:70,acceleration:70,awareness:70,strength:70,blocking:70,tackling:70,coverage:70,throwing:85,catching:80,stamina:80,...ratings[key]}});
    }
    return {fullTeam:true,call,players,offenseSide:'home',direction,seed,speedScale:.925,
        exhibition:{startX,goalX:direction===1?110:10,rearX:direction===1?10:110,firstDownX:startX+direction*10}};
}
const play = options => startFullTeamPlay(createFullTeamPlay(setup(options)));
function advance(state, ticks, input = {}) { for(let i=0;i<ticks;i++) stepFullTeamPlay(state,typeof input === 'function' ? input(state,i):input); return state; }
const near = (a,b,tolerance=1e-7) => assert.ok(Math.abs(a-b)<tolerance,`${a} != ${b}`);

test('all five calls keep 22 unique real actor identities and immutable source ratings', () => {
    for (const call of FULL_TEAM_CALLS) {
        const config=setup({call}), snapshot=structuredClone(config), state=play({call});
        advance(state,100,{x:1});
        assert.equal(state.actors.length,22); assert.equal(new Set(state.actors.map(a=>a.id)).size,22);
        assert.equal(state.actors.filter(a=>a.side==='home').length,11);
        assert.ok(state.actors.every(a=>a.x!==a.origin.x || a.z!==a.origin.z),'Every actor participates');
        const copy=createFullTeamPlay(config); copy.actors[0].ratings.speed=1;
        assert.deepEqual(config,snapshot); assert.equal(state.speedScale,.925);
    }
});

test('invalid or duplicate roster actors cannot become a full-team play', () => {
    for(const mutate of [s=>s.players.pop(),s=>s.players[1].id=s.players[0].id,s=>s.players[1].key=s.players[0].key,s=>s.players[0].id=-1,s=>s.speedScale=1,s=>s.call='qb_sneak']) {
        const config=setup(); mutate(config); assert.throws(()=>createFullTeamPlay(config));
    }
});

test('physical mesh transfers QB to RB once within one yard, without teleporting', () => {
    const state=play();
    for(let i=0;i<120&&!state.handoffComplete;i++) {
        const previous=state.actors.map(a=>({x:a.x,z:a.z})); stepFullTeamPlay(state,{x:1});
        state.actors.forEach((a,index)=>assert.ok(Math.hypot(a.x-previous[index].x,a.z-previous[index].z)<=actorSpeed(a)*STEP_SECONDS+1e-8));
    }
    assert.equal(state.handoffComplete,true); assert.equal(state.controlledKey,'offense_RB');
    assert.ok(Math.hypot(state.quarterback.x-state.runner.x,state.quarterback.z-state.runner.z)<=1);
    assert.equal(state.events.filter(e=>e.type==='handoff').length,1);
    assert.equal(state.ball.holderKey,state.runner.key);
});

test('QB contact before mesh is a run tackle with no invented RB possession', () => {
    const state=play({ratings:{offense_QB:{strength:0},defense_DE1:{tackling:100}}});
    const defender=state.actorByKey.defense_DE1;
    defender.x=state.quarterback.x;defender.z=state.quarterback.z;
    stepFullTeamPlay(state);
    assert.equal(state.result.reason,'tackle'); assert.equal(state.controlledKey,'offense_QB');
    assert.equal(state.events.some(e=>e.type==='handoff'),false);
});

test('identical input and seeds reproduce full state, terminal result and ordered events', () => {
    for(const call of FULL_TEAM_CALLS) {
        const a=play({call}),b=play({call});
        for(let i=0;i<1200;i++) {const input={x:1,z:i<80?.3:0,juke:i===60?-1:0}; stepFullTeamPlay(a,input);stepFullTeamPlay(b,input);}
        assert.deepEqual(a,b);assert.ok(a.result);
        assert.ok(a.events.every((event,i)=>i===0||event.time>=a.events[i-1].time));
        assert.equal(a.events.filter(e=>e.type==='finished').length,1);
        assert.ok(a.events.every(event=>event.time<=a.result.time+1e-9));
    }
});

test('all 22 mirror in attack direction with matching run/pass outcomes', () => {
    for(const call of FULL_TEAM_CALLS) {
        const a=play({call}),b=play({call,direction:-1});
        for(let i=0;i<300;i++) {
            stepFullTeamPlay(a,{x:1,z:.1}); stepFullTeamPlay(b,{x:-1,z:.1});
            a.actors.forEach((actor,index)=>{near(actor.x+b.actors[index].x,120);near(actor.z,b.actors[index].z);});
        }
        assert.equal(a.result?.reason,b.result?.reason);
    }
});

test('actual line contacts create blocks and bounded shedding, without deleting actors', () => {
    const state=play({call:'medium_pass'});
    advance(state,180);
    assert.ok(state.events.some(e=>e.type==='block'));
    assert.ok(state.actors.some(a=>a.blockTicks>0));
    assert.equal(state.actors.length,22);
});

test('higher DL strength sheds faster while higher OL blocking holds longer across seeds', () => {
    for(const seed of [1,832,2026]) {
        const low=play({call:'short_pass',seed,ratings:{defense_DT1:{strength:10}}});
        const high=play({call:'short_pass',seed,ratings:{defense_DT1:{strength:95}}});
        advance(low,90);advance(high,90);
        assert.ok(high.actorByKey.defense_DT1.shedProgress>low.actorByKey.defense_DT1.shedProgress);
        const weak=play({call:'short_pass',seed,ratings:{offense_LG:{blocking:10}}});
        const strong=play({call:'short_pass',seed,ratings:{offense_LG:{blocking:95}}});
        advance(weak,90);advance(strong,90);
        assert.ok(weak.actorByKey.defense_DT1.shedProgress>strong.actorByKey.defense_DT1.shedProgress);
    }
});

test('awareness changes delayed LB and safety observations, not future input knowledge', () => {
    for(const role of ['LB1','S1']) for(const seed of [1,832,2026]) {
        const low=play({call:'short_pass',seed,ratings:{[`defense_${role}`]:{awareness:10}}});
        const high=play({call:'short_pass',seed,ratings:{[`defense_${role}`]:{awareness:95}}});
        advance(low,45,{z:1});advance(high,45,{z:1});
        const l=low.actorByKey[`defense_${role}`],h=high.actorByKey[`defense_${role}`];
        assert.ok(h.readCount>l.readCount);assert.ok(h.observed.tick>l.observed.tick);
        assert.ok(h.observed.tick<high.tick);assert.ok(l.observed.tick<low.tick);
    }
});

test('CB coverage affects actual tracking geometry with all other ratings fixed', () => {
    for(const seed of [1,832,2026]) {
        const low=play({call:'medium_pass',seed,ratings:{defense_CB1:{coverage:10}}});
        const high=play({call:'medium_pass',seed,ratings:{defense_CB1:{coverage:95}}});
        advance(low,70);advance(high,70);
        const gap=s=>Math.abs(s.actorByKey.defense_CB1.z-s.actorByKey.offense_WR1.z);
        assert.ok(gap(high)<gap(low),`high ${gap(high)}, low ${gap(low)}`);
    }
});

test('reserved fatigue ratings are used without an extra within-snap speed penalty', () => {
    const state=play({call:'short_pass'}), actor=state.quarterback;
    actor.ratings.stamina=1;actor.fatigue=90;
    advance(state,20,{z:1});
    near(Math.hypot(actor.vx,actor.vz),Math.min(actorSpeed(actor),20*STEP_SECONDS*(14+actor.ratings.acceleration*.16)));
});

test('three pass packages retain short 3–6-yard outlets and distinct deeper targets', () => {
    const depths=[];
    for(const call of ['short_pass','medium_pass','deep_pass']) {
        const state=play({call});
        const ends=state.receivers.map(a=>(a.route.points.at(-1).x-state.startX)*state.direction);
        assert.ok(ends.some(yards=>yards>=3&&yards<=6));depths.push(Math.max(...ends));
    }
    assert.ok(depths[0]<depths[1]&&depths[1]<depths[2]);
});

test('aim preview is deterministic/read-only and releases no pass beyond scrimmage', () => {
    const state=play({call:'short_pass'}),snapshot=structuredClone(state),target={x:54,z:34};
    assert.equal(getFullTeamPassTrajectory(state,target).length,32);assert.deepEqual(state,snapshot);
    state.quarterback.x=51;stepFullTeamPlay(state,{pass:target});assert.equal(state.ball.flight,null);
});

test('catch sweep rejects overhead balls and catches physical between-tick opportunity', () => {
    const flight={start:{x:0,y:1.65,z:0},vx:20,vz:0,vy:7,elapsed:.95};
    const ball0={x:0,z:0},ball1={x:2,z:0},actor={x:1,z:0};
    assert.notEqual(fullTeamCatchTime(ball0,ball1,actor,actor,1,flight),null);
    assert.equal(fullTeamCatchTime(ball0,ball1,actor,actor,1,{...flight,elapsed:.5}),null);
    assert.equal(fullTeamCatchTime(ball0,ball1,{x:1,z:5},{x:1,z:5},1,flight),null);
});

test('pause, ready and terminal steps do not advance simulation or mutate official inputs', () => {
    const state=createFullTeamPlay(setup());const ready=structuredClone(state);stepFullTeamPlay(state,{x:1});assert.deepEqual(state,ready);
    startFullTeamPlay(state);advance(state,30);pauseFullTeamPlay(state);const paused=structuredClone(state);advance(state,50);assert.deepEqual(state,paused);
    startFullTeamPlay(state);advance(state,1200,{x:1});assert.ok(state.result);
    const terminal=structuredClone(state),result=state.result;advance(state,10);startFullTeamPlay(state);assert.deepEqual(state,terminal);assert.equal(result,state.result);
});

function isolateFlight(state, actor, x = actor.x) {
    for (const defender of state.defenders) if (defender.key !== actor.key) { defender.x=115;defender.z=50; }
    for (const receiver of state.receivers) if (receiver.key !== actor.key) { receiver.x=110;receiver.z=4; }
    const flight={start:{x:x-1,y:1.65,z:actor.z},vx:10,vz:0,vy:0,elapsed:.1,duration:.3,gravity:14,attemptedKeys:[],target:{x:x+2,z:actor.z}};
    state.ball={x,y:1.58,z:actor.z,phase:'flight',holderKey:null,flight};
    return flight;
}

test('short catch transfers actual receiver control and allows user-driven YAC', () => {
    const state=play({call:'short_pass',seed:1});
    const receiver=state.actorByKey.offense_TE;receiver.x=state.startX+4;receiver.z=34;receiver.ratings.catching=100;
    isolateFlight(state,receiver);
    stepFullTeamPlay(state);
    assert.equal(state.passOutcome,'complete');assert.equal(state.runner.key,receiver.key);assert.equal(state.controlledKey,receiver.key);
    assert.ok((state.catchPoint.x-state.startX)*state.direction<10);
    const catchX=receiver.x;advance(state,60,{x:1});assert.ok(receiver.x>catchX+5);
});

test('touchdown catch ends at ball crossing even with receiver center behind goal', () => {
    const state=play({call:'short_pass',seed:1}),receiver=state.actorByKey.offense_TE;
    receiver.x=state.goalX-.2;receiver.z=34;receiver.ratings.catching=100;
    isolateFlight(state,receiver,state.goalX+.1);
    stepFullTeamPlay(state);
    assert.equal(state.result.reason,'touchdown');assert.ok(receiver.x<state.goalX);
    near(state.events.find(e=>e.type==='catch').time,state.result.time);
    near(state.result.yards,state.goalX-state.startX);
});

test('interception requires a real defender-ball opportunity and ends at catch', () => {
    const state=play({call:'medium_pass',seed:1}),defender=state.actorByKey.defense_CB1;
    defender.x=state.startX+5;defender.z=20;defender.ratings.catching=100;defender.ratings.coverage=100;defender.ratings.awareness=100;
    isolateFlight(state,defender);stepFullTeamPlay(state);
    assert.equal(state.result.reason,'interception');assert.equal(state.ball.holderKey,defender.key);
    const event=state.events.find(e=>e.type==='interception');assert.equal(event.actorKey,defender.key);near(event.time,state.result.time);
    assert.ok(Math.hypot(event.contact.x-defender.x,event.contact.z-defender.z)<=1.3);
});

test('defensive catch failure can become a contact-only breakup, never an off-ball global roll', () => {
    let breakup=null;
    for(const seed of [500,1000,2000,3000,4000,5000,10000,20000]) {
        const state=play({call:'short_pass',seed}),defender=state.actorByKey.defense_CB1;
        defender.x=state.startX+5;defender.z=20;defender.ratings.catching=0;defender.ratings.awareness=0;defender.ratings.coverage=100;
        isolateFlight(state,defender);stepFullTeamPlay(state);
        if(state.events.some(e=>e.type==='pass_breakup')) {breakup=state;break;}
    }
    assert.ok(breakup);assert.equal(breakup.result.reason,'incomplete');
    assert.equal(breakup.events.find(e=>e.type==='incomplete').cause,'breakup');
    const miss=play({call:'short_pass'}),receiver=miss.actorByKey.offense_TE;receiver.x=55;receiver.z=20;
    const flight=isolateFlight(miss,receiver);receiver.x=80;flight.start.z=40;
    const rng=miss.randomState;stepFullTeamPlay(miss);assert.equal(miss.randomState,rng);
    assert.equal(miss.events.some(e=>e.type==='interception'||e.type==='pass_breakup'),false);
});

test('late contact events are removed after an earlier fractional terminal tackle', () => {
    const state=play({call:'short_pass',ratings:{offense_QB:{strength:0},defense_DE1:{tackling:100}}});
    const qb=state.quarterback,defender=state.actorByKey.defense_DE1;
    qb.vx=100;defender.x=qb.x+1.1;defender.z=qb.z;
    stepFullTeamPlay(state,{x:1});assert.ok(state.result);assert.ok(state.result.time<STEP_SECONDS);
    assert.ok(state.events.every(e=>e.time<=state.result.time+1e-10));
    assert.equal(state.events.at(-1).type,'finished');
});

test('every block event snapshot is actual opposing-player contact, not double interpolation', () => {
    const state=advance(play({call:'medium_pass'}),120);
    const events=state.events.filter(e=>e.type==='block');assert.ok(events.length>=4);
    for(const event of events) {
        const a=event.snapshot.actors.find(a=>a.key===event.actorKey),b=event.snapshot.actors.find(a=>a.key===event.targetKey);
        assert.ok(Math.hypot(a.x-b.x,a.z-b.z)<=1.0500001);
    }
});

test('outside run has distinct physical mesh and edge/seal assignments', () => {
    const inside=play({call:'inside_run'}),outside=play({call:'outside_run'});
    advance(inside,50);advance(outside,50);
    assert.notEqual(inside.actorByKey.offense_RB.z,outside.actorByKey.offense_RB.z);
    assert.notEqual(inside.actorByKey.offense_C.z,outside.actorByKey.offense_C.z);
    assert.ok(inside.handoffComplete&&outside.handoffComplete);
});

test('deep safety takes a bounded interception angle into an inside gap without a speed boost', () => {
    for (const direction of [1,-1]) {
        const state=play({direction,seed:832,ratings:{offense_RB:{strength:0},defense_S1:{awareness:95,tackling:100}}});
        const runner=state.actorByKey.offense_RB,safety=state.actorByKey.defense_S1;
        state.handoffComplete=true;state.runner=runner;state.controlledKey=runner.key;state.ball.holderKey=runner.key;
        runner.x=state.startX;runner.z=26.7;runner.vx=direction*actorSpeed(runner);runner.vz=0;
        safety.x=state.startX+direction*11;safety.z=34;
        for(const actor of state.actors) if(![runner.key,safety.key].includes(actor.key)) {actor.x=direction===1?115:5;actor.z=50;}
        let maxSpeed=0;
        for(let tick=0;tick<300&&!state.result;tick++) {
            const facing=safety.facing;stepFullTeamPlay(state,{x:direction});
            maxSpeed=Math.max(maxSpeed,Math.hypot(safety.vx,safety.vz));
            assert.ok(Math.abs(Math.atan2(Math.sin(safety.facing-facing),Math.cos(safety.facing-facing)))<=4.3*STEP_SECONDS*.925+1e-8);
        }
        assert.equal(state.result?.reason,'tackle');
        assert.equal(state.events.find(e=>e.type==='tackle').targetKey,safety.key);
        assert.ok(maxSpeed<=actorSpeed(safety)+1e-8);
    }
});

test('tackling changes real contact completion across fixed seeds, not distant outcomes', () => {
    for(const seed of [1,42,500]) {
        const states=[0,100].map(tackling=>play({seed,ratings:{defense_DE1:{tackling}}}));
        for(const state of states) {const d=state.actorByKey.defense_DE1;d.x=state.quarterback.x;d.z=state.quarterback.z;stepFullTeamPlay(state);}
        assert.equal(states[0].result,null);assert.equal(states[0].brokenTackles,1);
        assert.equal(states[1].result.reason,'tackle');
    }
});

test('defender decisions never read hidden intended or random flight target', () => {
    const a=play({call:'medium_pass'}),b=play({call:'medium_pass'});
    stepFullTeamPlay(a,{pass:{x:65,z:30}});stepFullTeamPlay(b,{pass:{x:65,z:30}});
    assert.ok(a.ball.flight&&b.ball.flight);
    a.ball.flight.target={x:5,z:1};b.ball.flight.target={x:115,z:52};
    for(let tick=0;tick<20;tick++) {stepFullTeamPlay(a);stepFullTeamPlay(b);}
    assert.deepEqual(a.defenders,b.defenders);
});
