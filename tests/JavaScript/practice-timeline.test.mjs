import test from 'node:test';
import assert from 'node:assert/strict';
import { DURATION, samplePlay } from '../../resources/js/practice/timeline.js';

for (const type of ['run', 'pass']) {
    test(`${type} has 22 players, bounded finite positions and a stable replay`, () => {
        for (let t = 0; t <= DURATION; t += 0.02) {
            const frame = samplePlay(type, t);
            assert.equal(frame.players.length, 22);
            assert.equal(new Set(frame.players.map(p => `${p.team}:${p.role}`)).size, 22);
            for (const point of [...frame.players, frame.ball]) {
                assert.ok(Number.isFinite(point.x) && point.x >= 0 && point.x <= 120);
                assert.ok(Number.isFinite(point.z) && point.z >= 0 && point.z <= 53.33);
            }
            assert.deepEqual(samplePlay(type, t), frame);
        }
        assert.equal(samplePlay(type, -5).time, 0);
        assert.equal(samplePlay(type, 100).time, DURATION);
        assert.equal(samplePlay(type, 100).event, 'Tackle · play complete');
    });
    test(`${type} ball remains continuous during snap transfer and catch`, () => {
        for (const time of [0.35, 0.6, 1, 2.2, 3.8]) {
            const before = samplePlay(type, time - 0.001).ball;
            const after = samplePlay(type, time + 0.001).ball;
            assert.ok(Math.hypot(before.x - after.x, before.z - after.z, before.y - after.y) < 0.1);
        }
        const end = samplePlay(type, DURATION);
        const carrier = end.players.find(p => p.team === 'offense' && p.role === end.carrier);
        assert.equal(end.ball.x, carrier.x);
        assert.equal(end.ball.z, carrier.z);
    });
}
