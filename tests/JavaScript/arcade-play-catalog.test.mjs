import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {FULL_TEAM_PLAYBOOK, FULL_TEAM_CALLS, FULL_TEAM_CATALOG_VERSION, fullTeamFormation, fullTeamDiagram, fullTeamRoute, fullTeamPlay} from '../../resources/js/arcade/full-team-plays.js';
import {createFullTeamPlay,startFullTeamPlay,stepFullTeamPlay,getFullTeamPassTrajectory} from '../../resources/js/arcade/full-team.js';
import {createDrillRecording} from '../../resources/js/arcade/recording.js';
import {createReplayTimeline} from '../../resources/js/arcade/replay.js';
const DEFENSE={DE1:[1,22],DT1:[1,25],DT2:[1,28],DE2:[1,31],LB1:[5,22],LB2:[5,27],LB3:[5,33],CB1:[3,9],CB2:[3,43],S1:[11,20],S2:[12,34]};
function setup(call,direction=1) {
    const players=[]; const line=direction===1?45:75;
    for(const [unit,roles] of Object.entries({offense:fullTeamFormation(call),defense:DEFENSE})) for(const [role,[x,z]] of Object.entries(roles)) {
        players.push({key:`${unit}_${role}`,id:players.length+1,side:unit==='offense'?'home':'away',role,x:line+direction*x,z,
            ratings:{speed:70,acceleration:70,awareness:70,strength:70,blocking:70,tackling:70,coverage:70,throwing:85,catching:80,stamina:80}});
    }
    return {fullTeam:true,call,players,offenseSide:'home',direction,seed:42,speedScale:.925,catalogVersion:FULL_TEAM_CATALOG_VERSION,tuningVersion:'arcade-fullteam-2',exhibition:{startX:line,goalX:direction===1?110:10,rearX:direction===1?10:110}};
}
const play=(call,d=1)=>startFullTeamPlay(createFullTeamPlay(setup(call,d)));
const advance=(s,n,input={})=>{for(let i=0;i<n && s.status==='running';i++)stepFullTeamPlay(s,input);return s;};
test('13 advertised calls are distinct, backed by server-authoritative kinds, and exclude toss',()=>{
    assert.equal(FULL_TEAM_PLAYBOOK.length,13); assert.equal(new Set(FULL_TEAM_PLAYBOOK.map(p=>p.id)).size,13);
    const php=readFileSync(new URL('../../app/Services/Arcade/FullTeamSetup.php',import.meta.url),'utf8');
    const ids=php.match(/public const PLAYBOOK_CALLS = \[([^\]]+)\]/)[1].match(/'[^']+'/g).map(s=>s.slice(1,-1));
    assert.deepEqual(ids,FULL_TEAM_PLAYBOOK.map(p=>p.id));
    const runs=php.match(/public const RUN_CALLS = \[([^\]]+)\]/)[1].match(/'[^']+'/g).map(s=>s.slice(1,-1));
    assert.deepEqual(runs,FULL_TEAM_PLAYBOOK.filter(p=>p.kind==='run').map(p=>p.id));
    const routeSets=FULL_TEAM_PLAYBOOK.filter(p=>p.kind==='pass').map(p=>JSON.stringify(fullTeamDiagram(p.id).routes));
    assert.equal(new Set(routeSets).size,9); assert.equal(fullTeamPlay('hb_toss'),null);
});
test('old reservations keep legacy route/formation/mechanics and reject new call IDs',()=>{
    for(const call of FULL_TEAM_CALLS) {
        const config=setup(call);config.catalogVersion='arcade-fullteam-1';
        assert.equal(createFullTeamPlay(config).catalogVersion,'arcade-fullteam-1');
    }
    const config=setup('qb_sneak');config.catalogVersion='arcade-fullteam-1';assert.throws(()=>createFullTeamPlay(config));
    config.catalogVersion=FULL_TEAM_CATALOG_VERSION;config.tuningVersion='arcade-fullteam-1';assert.throws(()=>createFullTeamPlay(config));
});
test('catalog routes and diagram points share the exact on-field assignment geometry',()=>{
    for(const p of FULL_TEAM_PLAYBOOK) {
        const d=fullTeamDiagram(p.id);
        for(const [role,points] of Object.entries(d.routes)) assert.deepEqual(points,fullTeamRoute(p.id,role,50,1).map(p=>[p.x-50,p.z]));
        for(const direction of [1,-1]) {
            const state=play(p.id,direction);
            for(const a of state.actors.filter(a=>a.side==='home')) assert.deepEqual([a.x,a.z],[state.startX+direction*d.formation[a.role][0],d.formation[a.role][1]]);
        }
    }
});
test('sneak is an under-center human QB run with no handoff, pass or sack',()=>{
    for(const direction of [1,-1]) {
        const s=play('qb_sneak',direction),start=s.quarterback.x;
        assert.equal(s.inputActorKey,'offense_QB');assert.equal(s.mode,'running');assert.equal(s.handoffComplete,true);
        assert.deepEqual(getFullTeamPassTrajectory(s,{x:start+direction*8,z:30}),[]);
        advance(s,30,{x:direction,juke:1});
        assert.ok((s.quarterback.x-start)*direction>0);assert.equal(s.ball.holderKey,'offense_QB');
        assert.ok(!s.events.some(e=>['handoff','throw'].includes(e.type)));assert.notEqual(s.result?.reason,'sack');
    }
});
test('draw delays its mesh, holds pass protection, then physically hands to CPU RB',()=>{
    const draw=play('hb_draw'),inside=play('inside_run');
    advance(draw,40);advance(inside,40);assert.equal(draw.handoffComplete,false);assert.equal(inside.handoffComplete,true);
    assert.equal(draw.ball.holderKey,'offense_QB');assert.ok(Math.abs(draw.quarterback.x-draw.quarterback.origin.x)<.1);
    for(let i=0;i<120&&!draw.handoffComplete&&draw.status==='running';i++)stepFullTeamPlay(draw,{x:-1,z:-1});
    assert.equal(draw.handoffComplete,true);assert.equal(draw.inputActorKey,null);
    const event=draw.events.find(e=>e.type==='handoff');assert.ok(event.time>=.75);
    assert.ok(Math.hypot(draw.runner.x-draw.quarterback.x,draw.runner.z-draw.quarterback.z)<=1);
});
test('play action performs physical fake mesh while QB retains ball, then allows real pass/scramble',()=>{
    for(const direction of [1,-1]) {
        const s=play('play_action_boot',direction),qb=s.quarterback;
        assert.equal(s.fakeComplete,false);assert.deepEqual(getFullTeamPassTrajectory(s,{x:s.startX+direction*10,z:35}),[]);
        for(let i=0;i<100&&!s.fakeComplete&&s.status==='running';i++)stepFullTeamPlay(s);
        assert.equal(s.fakeComplete,true);assert.equal(s.inputActorKey,qb.key);assert.equal(s.ball.holderKey,qb.key);
        assert.ok(Math.hypot(qb.x-s.actorByKey.offense_RB.x,qb.z-s.actorByKey.offense_RB.z)<=1);
        assert.ok(qb.handoffRemaining>0);assert.ok(!s.events.some(e=>e.type==='handoff'));
        assert.ok(getFullTeamPassTrajectory(s,{x:s.startX+direction*10,z:35}).length>0);
        advance(s,3,{x:0,z:1});assert.ok(qb.vz>0);
    }
});
test('screen guards release pass protection and move laterally behind LOS with ordinary speed',()=>{
    const s=play('hb_screen');advance(s,70);
    assert.equal(s.status,'running');
    for(const role of ['LG','RG']) {
        const g=s.actorByKey[`offense_${role}`];assert.ok(g.z>g.origin.z);assert.ok(g.x<s.startX);
    }
    assert.equal(s.ball.holderKey,'offense_QB');assert.equal(s.inputActorKey,'offense_QB');assert.equal(s.speedScale,.925);
});
test('all 13 calls record exact catalog2 actors and replay without simulation in both directions',()=>{
    for(const p of FULL_TEAM_PLAYBOOK) for(const direction of [1,-1]) {
        const state=play(p.id,direction),rec=createDrillRecording(state);
        for(let i=0;i<1200&&state.status==='running';i++) {
            let input=p.mechanic==='sneak'?{x:direction}:{};
            if(p.kind==='pass'&&state.fakeComplete&&state.tick===70)input={pass:{x:state.startX+direction*5,z:35}};
            if(p.kind==='pass'&&state.tick>180&&state.ball.phase==='held')input={x:direction};
            stepFullTeamPlay(state,input);rec.capture(state);
        }
        assert.ok(state.result,`${p.id} terminal`);assert.equal(state.actors.length,22);
        const data=rec.export(),timeline=createReplayTimeline(data);assert.equal(data.catalogVersion,FULL_TEAM_CATALOG_VERSION);
        assert.equal(data.truncated,false);assert.equal(timeline.sample(timeline.duration).controlledKey,state.controlledKey);
        assert.equal(timeline.sample(timeline.duration).ball.holderKey,state.ball.holderKey);
        for(const a of timeline.sample(timeline.duration).actors) {
            const actual=state.actorByKey[a.key];assert.ok(Math.abs(a.x-actual.x)<=.00501);assert.ok(Math.abs(a.z-actual.z)<=.00501);
        }
    }
});

test('an early screen catch does not release a subsequent lead block on the pass-set timer',()=>{
    const s=play('hb_screen'),g=s.actorByKey.offense_LG,d=s.actorByKey.defense_DT1,rb=s.actorByKey.offense_RB;
    s.elapsed=.64;s.tick=39;s.passOutcome='complete';s.runner=rb;s.controlledKey=rb.key;s.ball.holderKey=rb.key;
    g.x=40;g.z=15;d.x=40.8;d.z=15;g.blockingKey=d.key;d.blockedBy=g.key;d.shedProgress=0;
    s.blocks.push({blocker:g.key,defender:d.key,ended:false});
    stepFullTeamPlay(s);assert.equal(s.blocks[0].ended,false);assert.equal(g.blockingKey,d.key);
    assert.ok(!s.events.some(e=>e.type==='block_shed'&&e.actorKey===g.key));
});
test('sneak back can engage his actual blocker assignment without becoming the carrier',()=>{
    const s=play('qb_sneak'),rb=s.actorByKey.offense_RB,d=s.actorByKey.defense_LB1;
    rb.x=35;rb.z=15;d.x=35.7;d.z=15;stepFullTeamPlay(s);
    assert.ok(s.events.some(e=>e.type==='block'&&e.actorKey===rb.key));assert.equal(s.ball.holderKey,'offense_QB');
});
