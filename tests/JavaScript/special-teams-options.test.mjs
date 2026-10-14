import test from 'node:test';
import assert from 'node:assert/strict';
import {wizardSteps,playGraphic} from '../../resources/js/practice/play-wizard.js';
import {trickCalls,isSpecialTeamCall,kickFormation} from '../../resources/js/practice/special-teams-options.js';
test('fake kicks appear under their formations with no motion or defensive formation step',()=>{
    for(const call of trickCalls){
        const formation=call.startsWith('fake_punt')?'punt':'field_goal';
        assert.equal(kickFormation(call),formation);assert.equal(isSpecialTeamCall(call),true);
        const steps=wizardSteps(true,true,call,[call,'punt']);
        assert.equal(steps.some(s=>s.name==='motion'),false);assert.equal(steps.some(s=>s.name==='defense_formation'),false);
        const svg=playGraphic('play',call,formation);assert.match(svg,/<path/);assert.doesNotMatch(svg,/>KICK</);
    }
});
test('declared onside is offered at kickoff without an offensive formation step',()=>{
    const steps=wizardSteps(true,true,'onside_kick',['kickoff','onside_kick']);
    assert.deepEqual(steps.map(s=>s.kind),['play','defense-coverage','review']);
    assert.match(playGraphic('play','onside_kick'),/DECLARED ONSIDE/);
});
test('onside kicks use kick and recovery audio rather than a snap or QB cadence',async()=>{
    const {soundCues,cadenceCues}=await import('../../resources/js/practice/sound-cues.js');
    const animation={call:'onside_kick',duration:6,outcome:'onside_recovered',events:[[1.2,'Onside kick'],[6,'Recovery']],ballHolders:[[0,null,null],[3.6,'offense','TE']]};
    const before={possession:'home',phase:'kickoff',home_score:20,away_score:22,stats:{home:{turnovers:0},away:{turnovers:0}}};
    const after={...before,phase:'scrimmage',down:1};
    const cues=soundCues(animation,before,after);
    assert.ok(cues.some(c=>c.type==='kick'));assert.ok(cues.some(c=>c.type==='catch'));
    assert.equal(cues.some(c=>c.type==='snap'),false);assert.deepEqual(cadenceCues(animation),[]);
    assert.ok(cues.some(c=>c.type==='crowd'&&c.mood==='cheer'));
});
