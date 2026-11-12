import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {ARCADE_CALLS,exhibitionReservation,exhibitionCanPass,exhibitionControlHint,exhibitionLiveLabel} from '../../resources/js/arcade/exhibition-play-selection.js';

test('five explicit full-team calls preserve reservation identity and tempo',()=>{
 assert.equal(Object.keys(ARCADE_CALLS).length,5);
 for(const call of Object.keys(ARCADE_CALLS))for(const tempo of ['normal','hurry','drain']){
  assert.deepEqual(exhibitionReservation({version:17,call,tempo}),{version:17,call,kind:call.endsWith('_run')?'run':'pass',tempo,contract_version:'live-recording-2'});
 }
 assert.throws(()=>exhibitionReservation({version:17,call:'punt',tempo:'normal'}),/five Arcade plays/);
 const blade=readFileSync(new URL('../../resources/views/arcade/exhibition.blade.php',import.meta.url),'utf8');
 for(const [call,label] of Object.entries(ARCADE_CALLS))assert.ok(blade.includes(`<option value="${call}">${label}</option>`));
});
test('passing uses actual full-team quarterback identity, never a receiver after catch',()=>{
 const state={fullTeam:true,mode:'passing',status:'running',quarterback:{key:'offense_QB'},ball:{phase:'held',holderKey:'offense_QB'}};
 assert.equal(exhibitionCanPass(state),true);
 for(const holderKey of ['qb','offense_WR1',null])assert.equal(exhibitionCanPass({...state,ball:{phase:'held',holderKey}}),false);
 assert.equal(exhibitionCanPass({...state,status:'paused'}),false);
 assert.equal(exhibitionCanPass({...state,mode:'running'}),false);
 assert.equal(exhibitionCanPass({mode:'passing',status:'running',ball:{phase:'held',holderKey:'qb'}}),true);
});
test('control hints distinguish handoff, QB passing, flight and automatic catch control',()=>{
 const state={fullTeam:true,mode:'running',quarterback:{key:'offense_QB'},ball:{phase:'held',holderKey:'offense_QB'}};
 assert.match(exhibitionControlHint(state),/Automatic handoff/);
 assert.match(exhibitionControlHint({...state,mode:'passing'}),/quarterback.*receiver after a catch/);
 assert.match(exhibitionControlHint({...state,mode:'passing',ball:{phase:'flight'}}),/flight/);
 assert.match(exhibitionControlHint({...state,ball:{phase:'held',holderKey:'offense_RB'}}),/ball carrier/);
 assert.equal(exhibitionControlHint({...state,fullTeam:false}),'');
});

test('terminal full-team HUD names actual interception holder and never invites a return',()=>{
 const state={fullTeam:true,status:'finished',runner:{name:'Quarterback',x:20},actorByKey:{defense_CB1:{name:'Actual interceptor'}},ball:{holderKey:'defense_CB1'},startX:30,direction:1,elapsed:1.2,result:{reason:'interception',yards:0}};
 assert.match(exhibitionLiveLabel(state),/^Actual interceptor · interception · 0.0 yards/);
 assert.doesNotMatch(exhibitionLiveLabel(state),/Quarterback|Run with/);
 assert.match(exhibitionControlHint(state),/ends at the catch/);
 assert.match(exhibitionLiveLabel({...state,result:{reason:'incomplete',yards:0}}),/^Incomplete pass · 0.0 yards/);
 assert.equal(exhibitionLiveLabel(null),'');
 const legacy={...state,fullTeam:false};
 assert.equal(exhibitionLiveLabel(legacy),'Quarterback · -10.0 yards · 1.2s live');
});
