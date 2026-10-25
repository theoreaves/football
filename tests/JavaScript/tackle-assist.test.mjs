import test from 'node:test';
import assert from 'node:assert/strict';
import { tackleAssistPose } from '../../resources/js/practice/tackle-assist.js';
const carrier = {team:'offense',role:'RB',x:50,z:25};
const primary = {team:'defense',role:'LB2',x:50,z:26};
const help1 = {team:'defense',role:'LB1',x:51,z:25};
const help2 = {team:'defense',role:'S1',x:52,z:25};
const far = {team:'defense',role:'CB2',x:59,z:30};
const frame={players:[carrier,primary,help1,help2,far]};
const animation={contact_at:5.3,tackle_style:'wrap',tackler_role:'LB2',carrier:'RB',animation_variant:3};
test('presentation-only assists are late and staggered without changing tracks',()=>{
    assert.equal(tackleAssistPose(frame,animation,5.29,help1),null);
    assert.equal(tackleAssistPose(frame,animation,5.38,help1),null);
    const first=tackleAssistPose(frame,animation,5.55,help1);
    assert.ok(first?.blend > 0 && first.displacement <= .12);
    assert.equal(tackleAssistPose(frame,animation,5.55,help2),null);
    const later=tackleAssistPose(frame,animation,5.85,help2);
    assert.ok(later?.blend > 0 && later.displacement <= .12);
    assert.equal(tackleAssistPose(frame,animation,5.85,far),null);
    assert.equal(tackleAssistPose(frame,animation,5.85,primary),null);
    assert.equal(carrier.x,50);assert.equal(primary.z,26);
});
test('solo variants and disabled contact produce no assistants',()=>{
    assert.equal(tackleAssistPose(frame,{...animation,animation_variant:4},5.6,help1),null);
    assert.equal(tackleAssistPose(frame,{...animation,tackle_style:null},5.6,help1),null);
});
