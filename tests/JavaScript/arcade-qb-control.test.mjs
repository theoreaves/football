import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    createFullTeamPlay, getFullTeamPassTrajectory, startFullTeamPlay, stepFullTeamPlay,
} from '../../resources/js/arcade/full-team.js';
import { createDrillRecording } from '../../resources/js/arcade/recording.js';
import { createReplayTimeline } from '../../resources/js/arcade/replay.js';

const setup = JSON.parse(readFileSync(new URL('../Fixtures/fullteam-observed-run.json', import.meta.url), 'utf8')).setup;
const LEGACY = 'arcade-fullteam-1';
const QB_ONLY = 'arcade-fullteam-2';
const QB = 'offense_QB';
const RB = 'offense_RB';

function play({ direction = 1, call = 'short_pass', legacy = false } = {}) {
    const config = structuredClone(setup);
    config.call = call;
    if (!legacy) config.tuningVersion = QB_ONLY;
    if (direction === -1) {
        config.direction = -1;
        for (const key of ['startX', 'firstDownX', 'goalX', 'rearX']) config.exhibition[key] = 120 - config.exhibition[key];
        for (const actor of config.players) actor.x = 120 - actor.x;
    }
    const state = startFullTeamPlay(createFullTeamPlay(config));
    return { state, recorder: createDrillRecording(state) };
}

function step(play, input = {}) {
    stepFullTeamPlay(play.state, input);
    play.recorder.capture(play.state);
}

function spam(state) {
    return {
        x: -state.direction,
        z: state.tick % 2 ? 1 : -1,
        juke: state.tick % 2 ? 1 : -1,
        pass: { x: state.startX + 12 * state.direction, z: 46 },
    };
}

function assertNoInput(play, carrier) {
    assert.equal(play.state.inputActorKey, null);
    assert.equal(play.state.ball.holderKey, carrier);
}

function finishTogether(quiet, noisy) {
    for (let i = 0; i < 1200 && quiet.state.status === 'running'; i++) {
        step(quiet);
        step(noisy, spam(noisy.state));
    }
    assert.equal(quiet.state.status, 'finished');
    assert.deepEqual(noisy.state, quiet.state);
    const quietRecording = quiet.recorder.export();
    assert.deepEqual(noisy.recorder.export(), quietRecording);
    assert.equal(quietRecording.truncated, false);
    return createReplayTimeline(quietRecording);
}

function assertPossession(timeline, eventName, before, after) {
    const event = timeline.sample(timeline.duration).events.find(event => event.type === eventName);
    assert.ok(event && event.time > 0, 'expected a real in-play possession event');
    assert.equal(timeline.sample(event.time - 0.000001).ball.holderKey, before);
    assert.equal(timeline.sample(event.time).ball.holderKey, after);
    if (after) assert.equal(timeline.sample(event.time).controlledKey, after);
    return event;
}

for (const direction of [-1, 1]) {
    for (const call of ['inside_run', 'outside_run']) {
        test(`QB-only ${call} (${direction}): autonomous mesh and RB ignore all human input`, () => {
            const quiet = play({ direction, call }), noisy = play({ direction, call });
            assert.equal(quiet.state.tuningVersion, QB_ONLY);
            assertNoInput(quiet, QB);
            for (let i = 0; i < 120 && !quiet.state.handoffComplete && !quiet.state.result; i++) {
                step(quiet);
                step(noisy, spam(noisy.state));
            }
            assert.equal(quiet.state.handoffComplete, true);
            assert.deepEqual(noisy.state, quiet.state);
            assertNoInput(quiet, RB);
            assert.equal(quiet.state.controlledKey, RB);
            const onHandoff = quiet.state.runner.x;
            for (let i = 0; i < 10 && !quiet.state.result; i++) {
                step(quiet);
                step(noisy, spam(noisy.state));
            }
            assert.ok((quiet.state.runner.x - onHandoff) * direction > 0, 'RB advances without human steering');
            const timeline = finishTogether(quiet, noisy);
            assert.equal(timeline.recording.version, 2);
            assert.equal(timeline.recording.catalogVersion, LEGACY);
            assert.equal(timeline.recording.tuningVersion, QB_ONLY);
            assert.equal(timeline.recording.actors.length, 22);
            assertPossession(timeline, 'handoff', QB, RB);
            assert.equal(timeline.sample(timeline.duration).ball.holderKey, RB);
            assert.deepEqual(timeline.sample(timeline.duration).result, quiet.state.result);
        });
    }

    test(`QB-only pass (${direction}): release, flight and autonomous YAC ignore human movement, juke and stray passes`, () => {
        const quiet = play({ direction }), noisy = play({ direction });
        for (let i = 0; i < 5; i++) {
            step(quiet);
            step(noisy);
        }
        assert.equal(quiet.state.inputActorKey, QB);
        const pass = { x: quiet.state.startX + direction * 4, z: 36 };
        assert.ok(getFullTeamPassTrajectory(quiet.state, pass).length > 0);
        step(quiet, { pass });
        step(noisy, { ...spam(noisy.state), pass });
        assert.deepEqual(noisy.state, quiet.state, 'the accepted release tick does not also take movement/juke');
        assert.equal(quiet.state.ball.phase, 'flight');
        assertNoInput(quiet, null);
        assert.deepEqual(getFullTeamPassTrajectory(quiet.state, pass), []);
        for (let i = 0; i < 120 && !quiet.state.passOutcome && !quiet.state.result; i++) {
            step(quiet);
            step(noisy, spam(noisy.state));
        }
        assert.equal(quiet.state.passOutcome, 'complete');
        const receiver = quiet.state.runner.key;
        assert.notEqual(receiver, QB);
        assert.equal(quiet.state.status, 'running', 'fixture must exercise movement after catch');
        assertNoInput(quiet, receiver);
        assert.equal(quiet.state.controlledKey, receiver);
        assert.deepEqual(noisy.state, quiet.state);
        const catchX = quiet.state.runner.x;
        for (let i = 0; i < 10 && !quiet.state.result; i++) {
            step(quiet);
            step(noisy, spam(noisy.state));
        }
        assert.notEqual(quiet.state.runner.x, catchX, 'receiver continues through YAC without human steering');
        const timeline = finishTogether(quiet, noisy);
        const release = assertPossession(timeline, 'throw', QB, null);
        assert.equal(timeline.sample(release.time).ball.phase, 'flight');
        assertPossession(timeline, 'catch', null, receiver);
        assert.equal(timeline.sample(timeline.duration).ball.holderKey, receiver);
        assert.deepEqual(timeline.sample(timeline.duration).result, quiet.state.result);
    });

    test(`QB-only scramble (${direction}): crossing scrimmage retains QB movement and permanently closes the throw window`, () => {
        const running = play({ direction });
        const { state } = running;
        // Isolate the legal-line crossing from early contact in the pocket.
        for (const defender of state.defenders) {
            defender.x = state.startX + direction * 20;
            defender.z = 50;
        }
        const qb = state.quarterback;
        qb.x = state.startX - direction * .02;
        qb.z = 7;
        qb.vx = direction * 8;
        const pass = { x: state.startX + direction * 7, z: 10 };
        assert.ok(getFullTeamPassTrajectory(state, pass).length > 0);
        step(running, { x: direction });
        assert.ok((qb.x - state.startX) * direction > 0);
        assert.equal(state.inputActorKey, QB);
        assert.equal(state.ball.holderKey, QB);
        for (let i = 0; i < 100 && (qb.x - state.startX) * direction >= 0 && !state.result; i++) {
            step(running, { x: -direction });
        }
        assert.equal(state.status, 'running');
        assert.ok((qb.x - state.startX) * direction < 0);
        assert.equal(state.inputActorKey, QB);
        assert.deepEqual(getFullTeamPassTrajectory(state, pass), []);
        step(running, { pass });
        assert.equal(state.ball.holderKey, QB);
        assert.equal(state.events.some(event => event.type === 'throw'), false);
    });
}

test('historical setup without QB-only tuning retains legacy handoff input and replay semantics', () => {
    const quiet = play({ call: 'inside_run', legacy: true }), noisy = play({ call: 'inside_run', legacy: true });
    assert.equal(quiet.state.tuningVersion, LEGACY);
    assert.equal(Object.hasOwn(quiet.state, 'inputActorKey'), false);
    for (let i = 0; i < 120 && !quiet.state.handoffComplete; i++) {
        step(quiet);
        step(noisy);
    }
    assert.equal(quiet.state.handoffComplete, true);
    step(quiet);
    step(noisy, { x: 1, juke: 1 });
    assert.notEqual(noisy.state.runner.vz, quiet.state.runner.vz);
    assert.equal(noisy.state.events.find(event => event.type === 'juke').actorKey, RB);
    for (let i = 0; i < 1200 && noisy.state.status === 'running'; i++) step(noisy, { x: 1 });
    const timeline = createReplayTimeline(noisy.recorder.export());
    assert.equal(timeline.recording.version, 2);
    assert.equal(timeline.recording.tuningVersion, LEGACY);
    assertPossession(timeline, 'handoff', QB, RB);
});
