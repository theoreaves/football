import {shouldAutoOpenCoachWizard,shouldMountCoachWizard} from '../../resources/js/practice/play-mode-presentation.js';
import {readFileSync} from 'node:fs';
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
    const formations={offense:['singleback','shotgun','spread','i_form','pistol','trips','bunch','empty','goal_line'], defense:['base_4_3','base_3_5','nickel','two_high','single_high','base_3_4','dime']};
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
    assert.equal(formationPoints('offense','i_form').FB[1],26.7);
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

test('designed QB and crossing concepts have distinct diagrams and empty fields a fourth receiver',()=>{
    assert.notEqual(playGraphic('play','qb_draw'),playGraphic('play','qb_power'));
    assert.notEqual(playGraphic('play','qb_sneak'),playGraphic('play','qb_sweep'));
    assert.notEqual(playGraphic('play','mesh'),playGraphic('play','flood'));
    assert.equal(formationPoints('offense','empty').WR4[1],6);
    assert.equal(formationPoints('offense','bunch').WR2[1],43);
});

test('persistent Arcade ownership cancels delayed Coach wizard without dismissing required decisions',async()=>{
    const {guardWizardOwnership}=await import('../../resources/js/practice/play-wizard.js');
    let closed=0,cancelled=0;const wizard={open:true,close(){closed++;this.open=false;}},countdown={cancel(){cancelled++;}},penalty={open:true},injury={open:true},drive={open:true};
    assert.equal(guardWizardOwnership(false,wizard,countdown),false);
    assert.equal(closed,1);assert.equal(cancelled,1);assert.equal(wizard.open,false);
    assert.ok(penalty.open && injury.open && drive.open);
    assert.equal(guardWizardOwnership(false,wizard,countdown),false);assert.equal(closed,1);
    assert.equal(guardWizardOwnership(true,wizard,countdown),true);assert.equal(cancelled,2);
});


test('actual wizard timer never interrupts shared offensive handoffs across turnovers, reloads and consecutive possessions',()=>{
    const source=readFileSync(new URL('../../resources/js/practice/play-wizard.js',import.meta.url),'utf8');
    const tickBody=source.match(/return \(\{delta, ready, personnelReady = true\}\) => \{([\s\S]*?)\n    \};\n\}/)[1];
    const fieldSource=readFileSync(new URL('../../resources/js/practice/field.js',import.meta.url),'utf8');
    assert.match(fieldSource,/autoOpen: \(\) => shouldAutoOpenCoachWizard\(arcadeFlow,\{shared:Boolean\(shared\)\}\)/);
    let flow,modal=false,opens=0;
    const tick=new Function('autoOpen','canAutoOpenWizard','root','openWizard',`
        let idleSeconds=0,opened=false;const isEnabled=()=>true,guardWizardOwnership=()=>true;
        const dialog={open:false},countdown={tick:()=>null},open={},form={dataset:{}},document={hidden:false};
        const hurryCalling=()=>{},updateExchangeSkip=()=>{},updateReviewButton=()=>{};
        return ({delta,ready,personnelReady=true})=>{${tickBody}};
    `)(()=>shouldAutoOpenCoachWizard(flow,{shared:true}),canAutoOpenWizard,{isConnected:true,querySelector:()=>modal?{}:null},()=>opens++);
    for(const transition of ['punt','interception','kickoff','quarter change','reload','consecutive possession 1','consecutive possession 2']){
        flow={enabled:true,defense_mode:'coach',phase:'offense',human_side:'home',required_decision:null};
        modal=true;tick({delta:2,ready:true}); // Existing result/quarter notice remains open.
        assert.equal(modal,true,transition);
        modal=false;for(let frame=0;frame<180;frame++)tick({delta:1/60,ready:true});
        assert.equal(opens,0,transition+' must hand off without dismissing a Coach offensive picker');
        assert.equal(shouldMountCoachWizard(flow),true,'manual Coach picker remains mounted');
    }
    flow={enabled:true,defense_mode:'coach',phase:'defense'};tick({delta:1.1,ready:true});assert.equal(opens,1,'Coach Defense automatic picker preserved');
    flow={enabled:true,defense_mode:'watch',phase:'decision',required_decision:'special',can_advance:false};tick({delta:1.1,ready:true});assert.equal(opens,2,'required human special teams preserved');
    for(const defense_mode of ['coach','watch','fast'])assert.equal(shouldAutoOpenCoachWizard({enabled:true,defense_mode,phase:'offense'},{shared:true}),false);
    assert.equal(shouldAutoOpenCoachWizard({enabled:true,phase:'offense'},{shared:false}),true,'standalone Coach unchanged');
    assert.equal(shouldAutoOpenCoachWizard(null,{shared:true}),true);
    for(const required_decision of ['coin','penalty','ot_call','ot_choice'])assert.equal(shouldAutoOpenCoachWizard({enabled:true,phase:'decision',required_decision},{shared:true}),true,'required decision not suppressed');
});
