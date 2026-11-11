import test from 'node:test';
import assert from 'node:assert/strict';
import { createDrill, startDrill, pauseDrill, stepDrill, STEP_SECONDS } from '../../resources/js/arcade/simulation.js';
import { createPassingDrill, stepPassingDrill } from '../../resources/js/arcade/passing.js';
import { createDrillRecording, RECORDING_VERSION, RECORDING_LIMITS } from '../../resources/js/arcade/recording.js';

const player = id => ({ id, name: `Player ${id}`, lastname: `Surname ${id}`, position: 'RB', side: 'home', number: 12,
    skin_tone: '#c78e61', height_inches: 74, weight_pounds: 215, appearance: { head_shape: 'oval' },
    ratings: { speed: 70, acceleration: 70, strength: 60, tackling: 60, awareness: 70 } });
const drill = () => createDrill({ runner: player('RB1'), defenders: [player('LB1'), player('S1')], defenderCount: 2 });
const actor = (key, index) => ({ ...player(key), key, x: 80 + index, z: 26 + index, facing: 0,
    vx: 0, vz: 0, jukeRemaining: 0 });
function passing() {
    const quarterback = actor('qb', 0), receivers = [actor('receiver:0', 1), actor('receiver:1', 2)];
    return { mode: 'passing', status: 'running', tick: 0, elapsed: 0, direction: 1, startX: 80, goalX: 110, rearX: 74,
        quarterback, receivers, defenders: [actor('defender:0', 3), actor('defender:1', 4)], runner: quarterback,
        controlledKey: 'qb', ball: { x: 80, y: 1.28, z: 26, phase: 'held', holderKey: 'qb' }, events: [], result: null };
}
function tick(state, number) {
    state.tick = number;
    state.elapsed = number * STEP_SECONDS;
}
const near = (actual, expected, tolerance = .000001) => assert.ok(Math.abs(actual - expected) < tolerance, `${actual} != ${expected}`);

test('versioned running snapshots keep identity and appearance once, with quantized poses and held ball', () => {
    const state = drill(), appearance = { home: { uniform: { shirt: '#123456' } } };
    state.runner.x = 80.1234; state.runner.z = 26.6678; state.runner.facing = .12345;
    const recorder = createDrillRecording(state, { appearance });
    const data = recorder.export();
    assert.equal(data.version, RECORDING_VERSION);
    assert.equal(data.tickRate, 60); assert.equal(data.sampleRate, 30);
    assert.deepEqual(data.actors.map(actor => actor.key), ['runner', 'defender:0', 'defender:1']);
    assert.equal(data.actors[0].position, 'RB');
    assert.equal(data.seed, state.seed); assert.equal(data.tuningVersion, state.tuningVersion);
    assert.equal(data.speedScale, state.speedScale); assert.equal(data.routeSet, null);
    assert.equal(data.actors[0].id, 'RB1'); assert.equal(data.actors[0].lastname, 'Surname RB1');
    assert.equal(data.actors[0].appearance.head_shape, 'oval'); assert.equal(data.actors[0].height_inches, 74);
    assert.equal(data.actors[0].ratings, undefined); assert.equal(data.actors[0].x, undefined);
    assert.deepEqual(data.appearance, appearance);
    assert.deepEqual(data.frames[0][3][0], [8012, 2667, 123, 0, 0, 0, 0]);
    assert.deepEqual(data.frames[0][4], [8012, 128, 2667, 0, 0]);
    assert.deepEqual(data.events[0].slice(0, 5), [0, 0, 'start', 0, -1]);
    assert.deepEqual(JSON.parse(JSON.stringify(data)), data);
});

test('recording is deterministic and does not change simulation outcomes', () => {
    const recorded = startDrill(drill()), plain = startDrill(drill()), duplicate = startDrill(drill());
    const a = createDrillRecording(recorded), b = createDrillRecording(duplicate);
    for (let number = 0; number < 1200; number++) {
        const input = { x: 1, z: number > 25 && number < 100 ? -1 : 0, juke: number === 25 ? -1 : 0 };
        stepDrill(recorded, input); a.capture(recorded);
        stepDrill(plain, input);
        stepDrill(duplicate, input); b.capture(duplicate);
    }
    assert.deepEqual(recorded, plain); assert.deepEqual(a.export(), b.export());
    assert.deepEqual(a.export().result, recorded.result);
});

test('fixed ticks sample at 30 Hz, preserve odd event ticks, and ignore ready and paused repeats', () => {
    const state = startDrill(drill()); state.defenders = [];
    const recorder = createDrillRecording(state);
    for (let number = 1; number <= 6; number++) {
        stepDrill(state, { x: 1, juke: number === 3 ? -1 : 0 }); recorder.capture(state);
    }
    const before = recorder.export();
    assert.deepEqual(before.frames.map(frame => frame[0]), [0, 2, 3, 4, 6]);
    assert.equal(before.events.filter(event => event[2] === 'juke').length, 1);
    assert.equal(before.frames.find(frame => frame[0] === 3)[3][0][3], 2);
    assert.ok(before.frames.at(-1)[3][0][5] > 0, 'Animation gait is recorded, not recomputed from inputs');
    pauseDrill(state);
    for (let frame = 0; frame < 600; frame++) recorder.capture(state);
    assert.deepEqual(recorder.export(), before);
    startDrill(state); stepDrill(state); recorder.capture(state);
    assert.deepEqual(recorder.export().frames.map(frame => frame[0]), [0, 2, 3, 4, 6]);
});

test('fractional terminal time and final result survive odd ticks and cannot be replaced', () => {
    const state = startDrill(drill()); state.defenders = []; state.runner.z = .001;
    const recorder = createDrillRecording(state);
    stepDrill(state, { z: -1 }); recorder.capture(state);
    assert.equal(state.tick, 1); assert.equal(state.result.reason, 'out_of_bounds');
    assert.ok(state.elapsed > 0 && state.elapsed < STEP_SECONDS);
    const final = recorder.export();
    assert.deepEqual(final.frames.map(frame => frame[0]), [0, 1]);
    near(final.frames.at(-1)[1] / final.scales.time, state.elapsed);
    assert.equal(final.result.time, state.result.time);
    assert.equal(final.events.at(-1)[2], 'finished');
    for (let frame = 0; frame < 100; frame++) recorder.capture(state);
    state.result.reason = 'changed'; state.runner.x += 100;
    recorder.capture(state);
    assert.deepEqual(recorder.export(), final);
});

test('passing keeps stable actor indices, ball flight, throw targets and possession transfer', () => {
    const state = passing(), recorder = createDrillRecording(state);
    tick(state, 1);
    state.ball = { x: 80.556, y: 2.345, z: 26.111, phase: 'flight', holderKey: null, flight: { startedAt: STEP_SECONDS } };
    state.events.push({ id: 0, tick: 1, time: STEP_SECONDS, type: 'throw', actorKey: 'qb', target: { x: 96.543, z: 29.876 } });
    recorder.capture(state);
    tick(state, 3);
    state.controlledKey = 'receiver:1'; state.runner = state.receivers[1];
    state.ball = { x: 90, y: 1.28, z: 28, phase: 'held', holderKey: 'receiver:1' };
    state.events.push({ id: 1, tick: 3, time: state.elapsed, type: 'catch', actorKey: 'receiver:1', targetKey: 'qb', success: true });
    recorder.capture(state);
    const data = recorder.export();
    assert.deepEqual(data.actors.map(actor => actor.key), ['qb', 'receiver:0', 'receiver:1', 'defender:0', 'defender:1']);
    assert.deepEqual(data.frames.map(frame => frame[0]), [0, 1, 3]);
    assert.deepEqual(data.frames[1][4], [8056, 235, 2611, 1, -1]);
    assert.equal(data.frames[1][3][0][3], 4);
    assert.equal(data.frames.at(-1)[2], 2); assert.equal(data.frames.at(-1)[4][4], 2);
    assert.equal(data.frames.at(-1)[3][0][0], 8000, 'QB stays at actor index zero after the runner alias changes');
    assert.deepEqual(data.events[1].slice(2), ['throw', 0, -1, { target: [9654, 2988] }]);
    assert.deepEqual(data.events[2].slice(2), ['catch', 2, 0, { success: true }]);
    tick(state, 4); recorder.capture(state);
    assert.equal(recorder.export().events.length, 3, 'Cumulative events are not duplicated');
});

test('snapshots are deeply immutable and detached from source state, appearance and previous exports', () => {
    const state = passing(), appearance = { home: { uniform: { shirt: 'blue' } } };
    const recorder = createDrillRecording(state, { appearance }), first = recorder.export();
    state.quarterback.appearance.head_shape = 'square'; appearance.home.uniform.shirt = 'red';
    state.quarterback.x = 99; tick(state, 2); recorder.capture(state);
    assert.equal(first.frames.length, 1);
    assert.equal(recorder.export().actors[0].appearance.head_shape, 'oval');
    assert.equal(recorder.export().appearance.home.uniform.shirt, 'blue');
    assert.throws(() => { first.frames[0][3][0][0] = 100; }, TypeError);
    assert.throws(() => { first.actors[0].appearance.head_shape = 'wide'; }, TypeError);
    assert.notEqual(first.frames, recorder.export().frames);
});

test('retry starts a fresh timeline with no previous events, pose phase or result', () => {
    const state = passing(), first = createDrillRecording(state);
    tick(state, 1); state.status = 'finished'; state.result = { reason: 'sack', yards: 0, time: state.elapsed };
    first.capture(state);
    const second = createDrillRecording(passing()).export();
    assert.equal(second.result, null); assert.equal(second.frames.length, 1); assert.equal(second.events.length, 1);
    assert.equal(second.frames[0][3][0][5], 0); assert.equal(first.export().result.reason, 'sack');
    assert.equal(first.export().frames.at(-1)[3][0][3], 3);
});

test('20-second maximum six-player payload remains bounded with events on every unsampled tick and preserves the terminal result', t => {
    const state = passing(); state.receivers.push(actor('receiver:2', 5));
    const recorder = createDrillRecording(state);
    for (let number = 1; number <= 1200; number++) {
        tick(state, number);
        for (const [index, actor] of [state.quarterback, ...state.receivers, ...state.defenders].entries()) {
            actor.x = 80 + Math.sin(number / 50 + index) * 20; actor.z = 26 + Math.cos(number / 50 + index) * 20;
            actor.facing = number / 50; actor.vx = 7.123; actor.vz = 2.345;
            actor.jukeRemaining = 0;
        }
        state.ball = { x: 91.234, y: 4.567, z: 34.567, phase: 'flight', holderKey: null };
        if (number % 2) state.events.push({ id: state.events.length, tick: number, time: state.elapsed, type: 'catch_attempt',
            actorKey: 'receiver:0', targetKey: 'qb', success: false, chance: .891234567890123 });
        if (number === 1200) {
            state.status = 'finished'; state.result = { reason: 'timeout', yards: 12.345, time: 20 };
        }
        recorder.capture(state);
    }
    const data = recorder.export(), bytes = Buffer.byteLength(JSON.stringify(data));
    assert.ok(data.frames.length <= RECORDING_LIMITS.frames);
    assert.equal(data.events.length, RECORDING_LIMITS.events);
    assert.equal(data.frames.length, 727, '601 regular samples plus 126 accepted odd event ticks');
    assert.equal(data.events.at(-1)[2], 'finished'); assert.equal(data.frames.at(-1)[0], 1200);
    assert.deepEqual(data.result, state.result); assert.equal(data.truncated, true);
    assert.ok(bytes < 230000, `${bytes} bytes exceeds the maximum six-player payload budget`);
    for (let number = 1201; number < 5000; number++) { tick(state, number); recorder.capture(state); }
    assert.deepEqual(recorder.export(), data);
    t.diagnostic(`${bytes} bytes, ${data.frames.length} frames, ${data.events.length} events for a 20-second maximum six-player event-flood fixture`);
});

test('ordinary maximum-duration recording retains 601 samples and does not grow beyond the duration bound', () => {
    const state = passing(), recorder = createDrillRecording(state);
    for (let number = 1; number <= 1200; number++) { tick(state, number); recorder.capture(state); }
    assert.equal(recorder.export().frames.length, 601); assert.equal(recorder.export().truncated, false);
    tick(state, 1201); recorder.capture(state);
    assert.equal(recorder.export().frames.length, 601); assert.equal(recorder.export().truncated, true);
    tick(state, 0); recorder.capture(state);
    assert.equal(recorder.export().frames.length, 601);
});


test('real passing simulation records flight and terminal poses without changing its deterministic result', () => {
    const options = { quarterback: player('QB'), receivers: [player('WR1'), player('WR2'), player('TE')],
        defenders: [player('CB1'), player('S')], defenderCount: 2, seed: 924 };
    const state = startDrill(createPassingDrill(options)), plain = startDrill(createPassingDrill(options));
    const recorder = createDrillRecording(state);
    for (let number = 0; number < 1200 && !state.result; number++) {
        const input = number === 4 ? { pass: { x: 99, z: 26 } } : {};
        stepPassingDrill(state, input); recorder.capture(state);
        stepPassingDrill(plain, input);
        assert.deepEqual(state, plain);
    }
    const data = recorder.export();
    assert.ok(state.result); assert.deepEqual(data.result, state.result);
    assert.equal(data.seed, options.seed); assert.equal(data.tuningVersion, state.tuningVersion);
    assert.equal(data.speedScale, state.speedScale); assert.equal(data.routeSet, state.routeSet);
    for (const event of state.events.filter(event => event.type === 'incomplete')) {
        assert.equal(data.events.find(saved => saved[2] === 'incomplete')[5].cause, event.cause);
    }
    assert.ok(data.frames.some(frame => frame[4][3] === 1));
    assert.equal(data.actors.length, 6);
    const throwEvent = data.events.find(event => event[2] === 'throw');
    assert.ok(throwEvent); assert.ok(throwEvent[5].actualTarget);
    assert.equal(data.events.filter(event => event[2] === 'finished').length, 1);
    assert.equal(data.events.at(-1)[2], 'finished');
    near(data.frames.at(-1)[1] / data.scales.time, state.elapsed);
    assert.equal(data.frames.at(-1)[4][3], data.ballPhases.indexOf(state.ball.phase));
    for (let index = 1; index < data.frames.length; index++) {
        assert.ok(data.frames[index][1] >= data.frames[index - 1][1]);
        assert.equal(data.frames[index][3].length, 6);
    }
});
