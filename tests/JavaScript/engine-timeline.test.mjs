import test from 'node:test';
import assert from 'node:assert/strict';
import { sampleTrack, sampleEnginePlay } from '../../resources/js/practice/engine-timeline.js';

test('engine tracks interpolate in three dimensions and clamp to endpoints', () => {
    const track = [[0, 10, 1, 20], [2, 20, 5, 30], [6, 40, 0, 40]];
    assert.deepEqual(sampleTrack(track, -1), { x: 10, y: 1, z: 20 });
    assert.deepEqual(sampleTrack(track, 1), { x: 15, y: 3, z: 25 });
    assert.deepEqual(sampleTrack(track, 8), { x: 40, y: 0, z: 40 });
});

test('saved engine animation replays without mutating it and preserves team identity', () => {
    const animation = { duration: 6, players: [{ id: 17, number: 8, role: 'WR1', side: 'away', team: 'offense', path: [[0, 80, 0, 9], [6, 60, 0, 20]] }],
        ball: [[0, 80, 1, 9], [6, 60, 1, 20]], events: [[0, 'Snap'], [3, 'Catch'], [6, 'Tackle']] };
    const snapshot = JSON.stringify(animation);
    assert.equal(sampleEnginePlay(animation, 2).event, 'Snap');
    assert.equal(sampleEnginePlay(animation, 3).event, 'Catch');
    const final = sampleEnginePlay(animation, 100);
    assert.equal(final.time, 6); assert.equal(final.event, 'Tackle');
    assert.equal(final.players[0].side, 'away'); assert.equal(final.players[0].id, 17);
    assert.equal(final.players[0].x, final.ball.x); assert.equal(final.players[0].z, final.ball.z);
    assert.deepEqual(sampleEnginePlay(animation, 3), sampleEnginePlay(animation, 3));
    assert.equal(JSON.stringify(animation), snapshot);
});
