import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createReplayTimeline, createReplayPlayback, REPLAY_LIMITS, REPLAY_SPEEDS } from '../../resources/js/arcade/replay.js';
import { createDrillRecording } from '../../resources/js/arcade/recording.js';
import { createDrill, startDrill, stepDrill } from '../../resources/js/arcade/simulation.js';
import { createPassingDrill, stepPassingDrill } from '../../resources/js/arcade/passing.js';

const near = (actual, expected) => assert.ok(Math.abs(actual - expected) < .000001, `${actual} != ${expected}`);
function fixture(mode = 'passing') {
    return { version: 1, mode, direction: -1, tickRate: 60, sampleRate: 30,
        scales: { position: 100, angle: 1000, time: 1000000, gait: 1000 },
        poses: ['idle', 'run', 'juke', 'tackled', 'throw'], ballPhases: ['held', 'flight', 'dead'],
        field: { startX: 40, goalX: 10, rearX: 46 }, appearance: { home: { color: 'blue' } },
        actors: [{ key: mode === 'running' ? 'runner' : 'qb', name: 'Ada', appearance: { head_shape: 'oval' } }, { key: 'receiver:0', name: 'Bee' }],
        frames: [
            [0, 0, 0, [[4000, 2600, 3100, 0, 0, 0, 0], [3500, 2000, 0, 1, 700, 1000, 0]], [4000, 128, 2600, 0, 0]],
            [30, 500000, 0, [[3900, 2400, -3100, 4, 400, 500, 0], [3000, 2200, 0, 2, 900, 2000, 220]], [3500, 500, 2300, 1, -1]],
            [60, 1000000, 1, [[3800, 2300, -3000, 0, 0, 500, 0], [2500, 2400, 0, 3, 0, 3000, 0]], [2500, 128, 2400, 0, 1]],
        ],
        events: [[0, 0, 'start', 0, -1, {}], [60, 1000000, 'catch', 1, 0, { success: true }],
            [30, 500000, 'throw', 0, 1, { target: [2500, 2400] }], [60, 1000000, 'finished', 1, -1, { reason: 'tackle' }]],
        result: { reason: 'tackle', yards: 15, time: 1 }, truncated: false };
}

test('decode v1 metadata and interpolate recorded coordinates, gait, lean, speed and shortest-angle facing', () => {
    const timeline = createReplayTimeline(JSON.stringify(fixture()));
    assert.equal(timeline.duration, 1);
    const frame = timeline.sample(.25);
    assert.equal(frame.mode, 'passing'); assert.equal(frame.direction, -1);
    assert.equal(frame.actors[0].name, 'Ada'); assert.equal(frame.actors[0].appearance.head_shape, 'oval');
    near(frame.actors[0].x, 39.5); near(frame.actors[0].z, 25); near(frame.actors[0].facing, Math.PI);
    near(frame.actors[0].speed, 2); near(frame.actors[0].gaitPhase, .25); near(frame.actors[1].jukeLean, .11);
    near(frame.ball.x, 37.5); near(frame.ball.y, 3.14); near(frame.ball.z, 24.5);
    assert.equal(frame.actors[0].pose, 'idle'); assert.equal(timeline.sample(.5).actors[0].pose, 'throw');
    assert.equal(timeline.sample(1).actors[1].pose, 'tackled');
});

test('ownership, control, poses, events and result change only at recorded boundaries', () => {
    const timeline = createReplayTimeline(fixture());
    assert.equal(timeline.sample(.499999).ball.holderKey, 'qb');
    assert.deepEqual(timeline.sample(.5).ball, { x: 35, y: 5, z: 23, phase: 'flight', holderKey: null });
    assert.equal(timeline.sample(.999).controlledKey, 'qb');
    assert.equal(timeline.sample(1).controlledKey, 'receiver:0');
    assert.equal(timeline.sample(1).ball.holderKey, 'receiver:0');
    assert.equal(timeline.sample(.999).result, null);
    assert.equal(timeline.sample(1).result.reason, 'tackle');
    assert.deepEqual(timeline.sample(1).events.map(event => event.type), ['start', 'throw', 'catch', 'finished']);
    assert.deepEqual(timeline.sample(.5).events.at(-1), { id: 2, tick: 30, time: .5, type: 'throw', actorKey: 'qb',
        targetKey: 'receiver:0', data: { target: [2500, 2400] } });
});

test('backward scrubbing is deterministic, bounded, detached and deeply immutable', () => {
    const source = fixture('running'), before = structuredClone(source), timeline = createReplayTimeline(source);
    const early = timeline.sample(.3); timeline.sample(1); timeline.sample(.7);
    assert.deepEqual(timeline.sample(.3), early); assert.deepEqual(source, before);
    assert.deepEqual(timeline.sample(-5), timeline.sample(0)); assert.deepEqual(timeline.sample(99), timeline.sample(1));
    source.actors[0].name = 'Changed'; source.frames[0][3][0][0] = 0;
    assert.equal(timeline.sample(0).actors[0].name, 'Ada'); assert.equal(timeline.sample(0).actors[0].x, 40);
    assert.throws(() => { timeline.recording.appearance.home.color = 'red'; }, TypeError);
    assert.throws(() => { early.actors[0].appearance.head_shape = 'square'; }, TypeError);
    assert.throws(() => { early.events[0].data.changed = true; }, TypeError);
    assert.throws(() => timeline.sample(NaN), /finite/);
});

test('playback supports pause, restart, backward seek, speed changes and terminal stop', () => {
    const playback = createReplayPlayback(createReplayTimeline(fixture()));
    assert.equal(playback.playing, false); playback.advance(.5); assert.equal(playback.time, 0);
    for (const speed of REPLAY_SPEEDS) {
        playback.restart(); playback.setSpeed(speed); playback.advance(.1); near(playback.time, .1 * speed);
    }
    playback.pause(); const paused = playback.sample(); playback.advance(3); assert.deepEqual(playback.sample(), paused);
    playback.setSpeed(1); playback.play(); playback.seek(.9); playback.advance(.2);
    assert.equal(playback.time, 1); assert.equal(playback.playing, false);
    playback.play(); assert.equal(playback.time, 0); assert.equal(playback.playing, true);
    playback.seek(.75); playback.seek(.25); assert.equal(playback.sample().events.length, 1);
    playback.seek(-2); assert.equal(playback.time, 0); playback.seek(2); assert.equal(playback.playing, false);
    assert.throws(() => playback.setSpeed(3), /speed/); assert.throws(() => playback.advance(-1), /clock/);
    assert.throws(() => playback.advance(Infinity), /clock/); assert.throws(() => playback.seek(NaN), /finite/);
});

test('single frame, duplicate timestamps, fractional end and truncated recordings remain usable', () => {
    const data = fixture(); data.frames = [data.frames[0]]; data.events = [data.events[0]]; data.result = null; data.truncated = true;
    const timeline = createReplayTimeline(data), playback = createReplayPlayback(timeline);
    playback.play(); assert.equal(playback.playing, false); assert.equal(playback.sample().truncated, true);
    const duplicate = fixture(); duplicate.frames[1][1] = 0;
    assert.equal(createReplayTimeline(duplicate).sample(0).actors[0].pose, 'throw');
    const fractional = fixture(); fractional.frames[2][1] = 999999;
    assert.equal(createReplayTimeline(fractional).sample(.999999).result.reason, 'tackle');
});

test('reject malformed, incompatible, unordered, excessive and unsafe imports without accepting executable metadata', () => {
    const mutations = [data => data.version = 2, data => data.mode = 'game', data => data.direction = 0,
        data => data.scales.time = 0, data => data.poses[0] = 'unknown', data => data.field.startX = Infinity,
        data => data.frames = [], data => data.frames[1][1] = -1, data => data.frames[2][0] = 2,
        data => data.frames[1][3][0][3] = 9, data => data.frames[0][3][0][2] = 9999,
        data => data.frames[0][4][4] = 6, data => data.frames[0][2] = 6,
        data => data.actors[1].key = data.actors[0].key, data => data.frames[1][3].pop(),
        data => data.frames[2][1] = 20000001, data => data.frames = Array(731).fill(data.frames[0]),
        data => data.events = Array(129).fill(data.events[0]), data => data.events[0][3] = 9,
        data => data.events[0][5] = [], data => data.result = 'tackle', data => data.appearance = null,
        data => data.actors[0].name = 'x'.repeat(4097), data => data.frames[0][3][0][0] = NaN, data => delete data.frames[0][3][0][0]];
    for (const mutation of mutations) { const data = fixture(); mutation(data); assert.throws(() => createReplayTimeline(data), /Invalid replay/); }
    assert.throws(() => createReplayTimeline('{'), /JSON/);
    assert.throws(() => createReplayTimeline(' '.repeat(REPLAY_LIMITS.bytes + 1)), /1 MB/);
    assert.throws(() => createReplayTimeline('{"__proto__":{}}'), /unsafe/);
    const cyclic = fixture(); cyclic.appearance.self = cyclic; assert.throws(() => createReplayTimeline(cyclic), /JSON/);
    const accessor = fixture(); Object.defineProperty(accessor.appearance, 'bad', { enumerable: true, get() { throw new Error('invoked'); } });
    assert.throws(() => createReplayTimeline(accessor), /accessors/);
});

test('real running and passing recorder exports decode every exact frame without rerunning simulation', () => {
    const player = id => ({ id, name: id, ratings: { speed: 70, acceleration: 70, awareness: 70, catching: 70, throwing: 70 } });
    const states = [createDrill({ runner: player('RB'), defenders: [player('LB')] }),
        createPassingDrill({ quarterback: player('QB'), receivers: [player('WR1'), player('WR2'), player('TE')],
            defenders: [player('CB'), player('S')], defenderCount: 2, seed: 924 })];
    for (const state of states) {
        startDrill(state); const recorder = createDrillRecording(state);
        for (let tick = 0; tick < 1200 && !state.result; tick++) {
            if (state.mode === 'passing') stepPassingDrill(state, tick === 4 ? { pass: { x: 99, z: 26 } } : {});
            else stepDrill(state, { x: 1 });
            recorder.capture(state);
        }
        const data = recorder.export(), timeline = createReplayTimeline(data);
        for (const frame of data.frames) {
            const sample = timeline.sample(frame[1] / data.scales.time);
            near(sample.ball.x, frame[4][0] / 100);
            assert.equal(sample.ball.phase, data.ballPhases[frame[4][3]]);
            sample.actors.forEach((actor, index) => {
                near(actor.x, frame[3][index][0] / 100); near(actor.gaitPhase, frame[3][index][5] / 1000);
                assert.equal(actor.pose, data.poses[frame[3][index][3]]);
            });
        }
        assert.deepEqual(timeline.sample(timeline.duration).result, data.result);
    }
    const source = readFileSync(new URL('../../resources/js/arcade/replay.js', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /import\s|stepDrill|stepPassingDrill|Math\.random/);
});

test('maximum-duration six-actor event-flood export stays within validation bounds', () => {
    const actor = (key, index) => ({ key, name: `Player ${index}`, x: 80 + index, z: 26, facing: Math.PI,
        vx: 7, vz: 0, appearance: { head_shape: 'oval' } });
    const state = { mode: 'passing', direction: 1, tick: 0, elapsed: 0, startX: 80, goalX: 110, rearX: 74,
        quarterback: actor('qb', 0), receivers: [actor('receiver:0', 1), actor('receiver:1', 2), actor('receiver:2', 3)],
        defenders: [actor('defender:0', 4), actor('defender:1', 5)], controlledKey: 'qb',
        ball: { x: 80, y: 1.28, z: 26, phase: 'held', holderKey: 'qb' }, events: [], result: null };
    const recorder = createDrillRecording(state);
    for (let tick = 1; tick <= 1200; tick++) {
        state.tick = tick; state.elapsed = tick / 60;
        if (tick % 2) state.events.push({ tick, time: state.elapsed, type: 'catch_attempt', actorKey: 'receiver:0', success: false });
        if (tick === 1200) state.result = { reason: 'timeout', time: 20, yards: 0 };
        recorder.capture(state);
    }
    const recording = recorder.export(), timeline = createReplayTimeline(JSON.stringify(recording));
    assert.equal(timeline.duration, 20); assert.equal(timeline.recording.frames.length, 727);
    assert.equal(timeline.sample(20).events.length, 128); assert.equal(timeline.sample(20).truncated, true);
    assert.equal(timeline.sample(20).result.reason, 'timeout'); assert.equal(timeline.sample(10).actors.length, 6);
});

test('reject renderer-unsafe actor and team metadata while accepting nullable identities and PHP empty maps', () => {
    for (const field of ['weight_pounds', 'height_inches', 'id', 'number', 'name', 'firstname', 'lastname', 'role', 'position', 'side', 'skin_tone']) {
        const data = fixture(); data.actors[0][field] = { toString: {} };
        assert.throws(() => createReplayTimeline(data), /Invalid replay: invalid actor/);
    }
    for (const mutate of [data => data.actors[0].appearance = 'oval', data => data.actors[0].appearance.nose = { toString: {} },
        data => data.actors[0].appearance.head_shape = 'toString', data => data.actors[0].side = 'toString',
        data => data.appearance.home = 'blue', data => data.appearance.home.uniform = 'blue',
        data => data.appearance.home.uniform = { shoulder_stripe_pattern: 'toString' },
        data => data.appearance.home.endzone_text = { toString: {} }]) {
        const data = fixture(); mutate(data); assert.throws(() => createReplayTimeline(data), /Invalid replay/);
    }
    const data = fixture(); Object.assign(data.actors[0], { id: 4, number: '00', height_inches: 74, weight_pounds: 215, appearance: [] });
    Object.assign(data.actors[1], { height_inches: null, weight_pounds: null, number: null, lastname: null, appearance: null });
    data.appearance.home = { name: 'Home', uniform: [], endzone_transparent: false, midfield_logo: null };
    data.appearance.home.audio = { cues: { score: '/audio/score.mp3' } };
    data.appearance.home.stadium = { presentation_version: 2, field_wall_art: null };
    data.appearance.home.cheerleaders = []; data.appearance.home.mascot = {}; data.appearance.home.staff = [];
    data.appearance.away = {};
    const timeline = createReplayTimeline(JSON.stringify(data));
    assert.equal(timeline.recording.actors[0].number, '00'); assert.equal(timeline.recording.actors[1].weight_pounds, null);
});
