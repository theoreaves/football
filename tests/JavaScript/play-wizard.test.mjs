import test from 'node:test';
import assert from 'node:assert/strict';
import { formationPoints, playGraphic, wizardSteps, canAutoOpenWizard } from '../../resources/js/practice/play-wizard.js';

test('wizard sequences each human side and keeps the final call step after all choices', () => {
    const kinds=(offense,defense,call='short_pass',calls=[])=>wizardSteps(offense,defense,call,calls).map(step=>step.kind);
    assert.deepEqual(kinds(true,true), ['formation','play','motion','defense-formation','defense-coverage','defense-type','review']);
    assert.deepEqual(kinds(true,false), ['formation','play','motion','review']);
    assert.deepEqual(kinds(false,true), ['defense-formation','defense-coverage','defense-type','review']);
    assert.deepEqual(kinds(true,true,'kickoff',['kickoff']), ['play','defense-coverage','review']);
    assert.deepEqual(kinds(true,true,'extra_point',['extra_point','two_point_pass']), ['play','defense-coverage','review']);
    assert.deepEqual(kinds(true,false,'kneel'), ['formation','play','review']);
    assert.ok(kinds(true,true,'two_point_pass',['extra_point','two_point_pass']).includes('motion'));
});

test('formation diagrams contain eleven players and distinguish each alignment', () => {
    const formations={offense:['singleback','shotgun','spread','i_form','pistol','trips'], defense:['base_4_3','base_3_5','nickel','two_high','single_high','base_3_4','dime']};
    for(const [side,types] of Object.entries(formations)) {
        const layouts=types.map(type=>{
            const points=formationPoints(side,type);
            assert.equal(Object.keys(points).length,11);
            assert.ok(Object.values(points).flat().every(Number.isFinite));
            return JSON.stringify(points);
        });
        assert.equal(new Set(layouts).size,types.length);
    }
    assert.equal(formationPoints('defense','nickel').CB3[0],5);
    assert.equal(formationPoints('offense','i_form').WR3[1],26.7);
});

test('play and coverage graphics distinguish routes coverage and blitz types', () => {
    assert.notEqual(playGraphic('play','short_pass'),playGraphic('play','deep_pass'));
    assert.notEqual(playGraphic('play','inside_run'),playGraphic('play','outside_run'));
    assert.notEqual(playGraphic('defense-coverage','zone'),playGraphic('defense-coverage','man_to_man'));
    for(const expect of ['run','pass','balanced']) {
        assert.notEqual(playGraphic('defense-type',expect+':regular','nickel'),playGraphic('defense-type',expect+':blitz','nickel'));
    }
    assert.notEqual(playGraphic('motion','none'),playGraphic('motion','WR1'));
});

test('kick formations keep formation and play steps and automatic opening waits for an idle human turn', () => {
    assert.deepEqual(wizardSteps(true,true,'punt',['punt']).map(step=>step.kind), ['formation','play','defense-coverage','review']);
    for(const formation of ['punt','field_goal']) {
        assert.equal(Object.keys(formationPoints('offense',formation)).length,11);
    }
    const ready={ready:true,opened:false,visible:true,dialogOpen:false};
    assert.equal(canAutoOpenWizard(ready),true);
    for(const change of [{ready:false},{opened:true},{visible:false},{dialogOpen:true}]) {
        assert.equal(canAutoOpenWizard({...ready,...change}),false);
    }
});
