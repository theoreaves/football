import test from 'node:test';
import assert from 'node:assert/strict';
import {presentationHandoffNotice} from '../../resources/js/arcade/presentation-handoff.js';

test('early next-offense intent waits without erasing the saved-play presentation',()=>{
 const presentation={recorded:true,completed:false,ready:false,dialogOpen:false};
 assert.match(presentationHandoffNotice({presentation}),/still being presented/);
 assert.deepEqual(presentation,{recorded:true,completed:false,ready:false,dialogOpen:false});
 assert.match(presentationHandoffNotice({presentation:{...presentation,completed:true}}),/still being presented/);
 assert.equal(presentationHandoffNotice({presentation:{...presentation,completed:true,ready:true}}),null);
});
test('mandatory result/dialog, opening and transition gates survive a control-switch intent',()=>{
 const ready={recorded:true,completed:true,ready:true};
 assert.match(presentationHandoffNotice({presentation:{...ready,dialogOpen:true}}),/notice/);
 assert.match(presentationHandoffNotice({presentation:ready,requiredDecision:'penalty'}),/required football decision/);
 assert.match(presentationHandoffNotice({opening:true}),/opening/);
 for(const key of ['staging','transitioning'])assert.match(presentationHandoffNotice({[key]:true}),/moving into position/);
});
