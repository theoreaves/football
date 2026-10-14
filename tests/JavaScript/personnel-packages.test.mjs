import test from 'node:test';
import assert from 'node:assert/strict';
import {formationPoints,playGraphic} from '../../resources/js/practice/play-wizard.js';
test('package diagrams show actual TE2 FB and WR4 positions with exactly eleven players',()=>{
    const packages={singleback:{in:['TE2'],out:['WR3']},i_form:{in:['FB'],out:['WR3']},goal_line:{in:['FB','TE2'],out:['WR2','WR3']},empty:{in:['WR4'],out:['RB']}};
    for(const [formation,roles] of Object.entries(packages)){
        const points=formationPoints('offense',formation);assert.equal(Object.keys(points).length,11);
        for(const role of roles.in)assert.ok(points[role]);
        for(const role of roles.out)assert.equal(points[role],undefined);
        const svg=playGraphic('formation',formation,formation);for(const role of roles.in)assert.ok(svg.includes('>'+role+'</text>'));
    }
});
