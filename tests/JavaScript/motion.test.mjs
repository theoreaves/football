import test from 'node:test';
import assert from 'node:assert/strict';
import { samplePreSnapMotion } from '../../resources/js/practice/motion.js';

const frame = { players: [
    { team: 'offense', role: 'WR1', z: 36 },
    { team: 'defense', role: 'CB1', z: 37 },
    { team: 'defense', role: 'CB2', z: 43 },
] };
const animation = { motion: 'WR1', motion_start: 9, motion_end: 36,
    motion_defender: 'CB1', motion_defender_start: 10, motion_defender_end: 37 };

test('man defender travels with motion receiver and joins the snap positions without a jump', () => {
    for (const progress of [0, .25, .5, .75, 1]) {
        const result = samplePreSnapMotion(frame, animation, progress);
        assert.equal(result.players[1].z - result.players[0].z, 1);
        assert.equal(result.players[2].z, 43);
    }
    assert.deepEqual(samplePreSnapMotion(frame, animation, 1), frame);
    assert.equal(frame.players[0].z, 36);
    assert.equal(samplePreSnapMotion(frame, animation, 0).players[1].z, 10);
});

test('zone defenders stay in place and old replays without motion metadata remain valid', () => {
    const zone = { ...animation, motion_defender: null };
    assert.equal(samplePreSnapMotion(frame, zone, .5).players[1].z, 37);
    assert.deepEqual(samplePreSnapMotion(frame, undefined, 0), frame);
});
