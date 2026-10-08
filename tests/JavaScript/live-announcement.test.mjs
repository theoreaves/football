import test from 'node:test';
import assert from 'node:assert/strict';
import { turnoverMoment, crossedTurnover } from '../../resources/js/practice/live-announcement.js';

test('turnover banners follow the interception catch and fumble recovery animation events', () => {
    const interception=turnoverMoment({events:[[0,'Snap'],[2.2,'Pass in flight'],[3.8,'Intercepted'],[5.3,'Smith intercepted the pass']]});
    assert.deepEqual(interception,{time:3.8,title:'INTERCEPTION!'});
    assert.equal(crossedTurnover(interception,3.7,3.8),true);
    assert.equal(crossedTurnover(interception,0,2.2),false);
    assert.equal(crossedTurnover(interception,3.8,4),false);
    assert.equal(crossedTurnover(interception,5,2),false);
    const fumble=turnoverMoment({events:[[0,'Snap'],[3.8,'Pursuit'],[5.3,'Smith fumbles, recovered by Jones']]});
    assert.deepEqual(fumble,{time:5.3,title:'FUMBLE!'});
    assert.equal(crossedTurnover(fumble,5.2,5.4),true);
    assert.equal(turnoverMoment({events:[[3.8,'Catch'],[5.3,'Tackle']]}),null);
    assert.equal(turnoverMoment(null),null);
});
