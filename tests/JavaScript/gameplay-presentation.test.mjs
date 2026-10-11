import test from 'node:test';
import assert from 'node:assert/strict';
import {autoNotices} from '../../resources/js/practice/auto-notices.js';
import {goalKickBallTrack} from '../../resources/js/practice/goal-kick-flight.js';
import {sampleEnginePlay} from '../../resources/js/practice/engine-timeline.js';

function dialog(hasForm = true) {
    const d = {open: true, submissions: 0};
    d.querySelector = () => hasForm ? {requestSubmit() {d.submissions++;}} : null;
    return d;
}
function advance(tick, seconds, state = {}) {
    for (let i = 0; i < seconds * 4; i++) tick({delta: .25, enabled: true, visible: true, ...state});
}
test('notice timers pause with autoplay and hidden tabs, submit once, and reset for the next notice', () => {
    const injury = dialog(), drive = dialog(); drive.open = false;
    const tick = autoNotices([{dialog: injury, seconds: 7}, {dialog: drive, seconds: 10}]);
    advance(tick, 6); advance(tick, 20, {enabled: false}); advance(tick, 20, {visible: false});
    assert.equal(injury.submissions, 0);
    advance(tick, 1); advance(tick, 10); assert.equal(injury.submissions, 1);
    injury.open = false; drive.open = true;
    advance(tick, 9); assert.equal(drive.submissions, 0);
    advance(tick, 1); assert.equal(drive.submissions, 1);
});
test('human penalty decisions and final result screens never auto-submit', () => {
    const penalty = dialog(false), final = dialog();
    const tick = autoNotices([{dialog: penalty, seconds: 7}, {dialog: final, seconds: 12, allowed: () => false}]);
    advance(tick, 60); assert.equal(penalty.submissions, 0); assert.equal(final.submissions, 0);
    penalty.open = false; advance(tick, 60); assert.equal(final.submissions, 0);
});
function kick(call, outcome, direction) {
    const x = direction === 1 ? 120 : 0;
    return {call, outcome, duration: 6, players: [], events: [], ballHolders: [[0, null, null]],
        ball: [[0,x-direction*45,1,26.7],[1.2,x-direction*45,.5,26.7],
            [2.4,x-direction*22.5,14,26.7],[3.5,x,5,26.7],[5.3,x,5,26.7],[6,x,5,26.7]]};
}
test('made field goals and extra points cross the uprights in both directions without changing saved tracks', () => {
    for (const call of ['field_goal','extra_point']) for (const direction of [1,-1]) {
        const a = kick(call, `${call}_good`, direction), saved = JSON.stringify(a);
        const goal = sampleEnginePlay(a, 3.5).ball, beyond = sampleEnginePlay(a, 4.2).ball;
        assert.equal(goal.y, 5); assert.ok((beyond.x-goal.x)*direction > 0);
        assert.equal(sampleEnginePlay(a, 6).ball.y, .25);
        assert.equal(JSON.stringify(a), saved);
        assert.deepEqual(sampleEnginePlay(a, 2).ball, sampleEnginePlay({...a, call: 'punt'}, 2).ball);
    }
});
test('misses retain their miss position, while blocked kicks and punts retain their original paths', () => {
    const miss = kick('field_goal','field_goal_missed',1); miss.ball.slice(3).forEach(p => {p[2]=.25;p[3]=40;});
    assert.equal(sampleEnginePlay(miss,4.2).ball.z,40); assert.equal(sampleEnginePlay(miss,4.2).ball.y,.25);
    for (const [call,outcome] of [['field_goal','field_goal_blocked'],['punt','punt_touchback']]) {
        const a = kick(call,outcome,1); assert.equal(goalKickBallTrack(a),a.ball);
    }
});
