import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { createDrillRecording, FULL_TEAM_RECORDING_LIMITS } from '../../resources/js/arcade/recording.js';
import { createReplayTimeline, FULL_TEAM_REPLAY_LIMITS } from '../../resources/js/arcade/replay.js';
import { createArcadeView, arcadeActorEntries, attachArcadeBall, disposeArcadeScene } from '../../resources/js/arcade/view.js';
import { createReplayView } from '../../resources/js/arcade/replay-view.js';
import { createFullTeamPlay, startFullTeamPlay, stepFullTeamPlay, FULL_TEAM_CALLS } from '../../resources/js/arcade/full-team.js';
import { createPlayerRegistry } from '../../resources/js/practice/exhibition-scene-host.js';
import { buildFootballPlayer } from '../../resources/js/practice/player-model.js';

const keys = [ ...['QB','C','LG','RG','LT','RT','RB','TE','WR1','WR2','WR3'].map(role => `offense_${role}`),
    ...['DE1','DT1','DT2','DE2','LB1','LB2','LB3','CB1','CB2','S1','S2'].map(role => `defense_${role}`) ];
function fixture() {
    const actors = keys.map((key, index) => ({ key, id: index + 1, role: key.split('_')[1], side: index < 11 ? 'home' : 'away', number: index + 1,
        x: 40 + index, z: 3 + index * 2, vx: 1, vz: 0, facing: 0, jukeRemaining: 0 }));
    return { fullTeam: true, actors, quarterback: actors[0], runner: actors[0], receivers: actors.slice(6, 11), defenders: actors.slice(11),
        call: 'inside_run', tuningVersion: 'arcade-fullteam-1', mode: 'running', direction: 1, seed: 123, speedScale: .925,
        startX: 45, goalX: 110, rearX: 10, elapsed: 0, tick: 0, status: 'running', controlledKey: 'offense_QB',
        ball: { x: 40, y: 1.28, z: 3, phase: 'held', holderKey: 'offense_QB' }, events: [], result: null };
}
const tick = (state, number) => { state.tick = number; state.elapsed = number / 60; };
const copy = value => JSON.parse(JSON.stringify(value));

test('v2 records every actual actor and handoff/block event boundary without aliasing or changing state', () => {
    const state = fixture(), recorder = createDrillRecording(state);
    tick(state, 1); state.actors[1].blockingKey = 'defense_DT1'; state.actors[0].handoffRemaining = .2;
    state.ball.holderKey = 'offense_RB'; state.controlledKey = 'offense_RB'; state.runner = state.actors[6];
    state.events.push({ type: 'handoff', actorKey: 'offense_QB', targetKey: 'offense_RB', time: state.elapsed, tick: 1 });
    state.events.push({ type: 'block', actorKey: 'offense_C', targetKey: 'defense_DT1', time: state.elapsed, tick: 1 });
    const untouched = JSON.stringify(state); recorder.capture(state); assert.equal(JSON.stringify(state), untouched);
    tick(state, 2); state.actors.forEach((actor, index) => { actor.x += index / 10; actor.z += .2; }); recorder.capture(state);
    const data = recorder.export(); assert.equal(data.version, 2); assert.equal(data.call, 'inside_run');
    assert.deepEqual(data.actors.map(actor => actor.key), keys); assert.equal(data.frames[1][3].length, 22);
    assert.deepEqual(data.frames.map(frame => frame[0]), [0, 1, 2]);
    assert.equal(data.poses[data.frames[1][3][0][3]], 'handoff'); assert.equal(data.poses[data.frames[1][3][1][3]], 'block');
    const replay = createReplayTimeline(data);
    assert.equal(replay.sample(.01).ball.holderKey, 'offense_QB');
    assert.equal(replay.sample(16667 / 1000000).ball.holderKey, 'offense_RB');
    assert.equal(replay.sample(16667 / 1000000).controlledKey, 'offense_RB');
    for (let index = 0; index < 22; index++) assert.equal(replay.sample(replay.duration).actors[index].x, state.actors[index].x);
    assert.ok(Object.isFrozen(data.frames[0][3]));
});

test('v2 captures odd-tick ball ownership boundaries even without an event and keeps historical v1 limits', () => {
    const state = fixture(), recorder = createDrillRecording(state);
    tick(state, 1); state.ball.holderKey = 'offense_RB'; recorder.capture(state);
    assert.deepEqual(recorder.export().frames.map(frame => frame[0]), [0, 1]);
    assert.equal(FULL_TEAM_RECORDING_LIMITS.frames, 858);
});

test('maximum 20-second movement and event workload fits measured byte/node budgets and round-trips', t => {
    const state = fixture(), recorder = createDrillRecording(state);
    for (let number = 1; number <= 1200; number++) {
        tick(state, number);
        state.actors.forEach((actor, index) => { actor.x = 50 + Math.sin(number / 60 + index) * 20; actor.z = 26 + Math.cos(number / 90 + index) * 20; });
        if (number % 2 === 1 && number <= 507) state.events.push({ type: number % 4 === 1 ? 'block' : 'block_shed', actorKey: 'offense_C', targetKey: 'defense_DT1', time: state.elapsed - 1 / 120, tick: number,
            snapshot: { actors: copy(state.actors), ball: copy(state.ball), controlledKey: state.controlledKey } });
        if (number === 1200) { state.status = 'finished'; state.result = { reason: 'timeout', yards: 0, time: 20 }; }
        recorder.capture(state);
    }
    const data = recorder.export(), serialized = JSON.stringify(data);
    let nodes = 0; const count = value => { nodes++; if (value && typeof value === 'object') Object.values(value).forEach(count); }; count(data);
    t.diagnostic(`${data.frames.length} frames, ${data.events.length} events, ${Buffer.byteLength(serialized)} bytes, ${nodes} JSON nodes`);
    assert.equal(data.frames.length, 855); assert.equal(data.events.length, 256); assert.equal(data.truncated, false);
    assert.ok(Buffer.byteLength(serialized) < FULL_TEAM_REPLAY_LIMITS.bytes); assert.ok(nodes < FULL_TEAM_REPLAY_LIMITS.nodes);
    assert.equal(createReplayTimeline(serialized).sample(20).actors.length, 22);
    const oversized = copy(data); oversized.frames.push(...data.frames.slice(0, 4));
    assert.throws(() => createReplayTimeline(oversized), /frame count/);
    const duplicate = copy(data); duplicate.actors[1].key = duplicate.actors[0].key;
    assert.throws(() => createReplayTimeline(duplicate), /duplicate actor/);
    const unknown = copy(data); unknown.events[1][2] = 'invented'; assert.throws(() => createReplayTimeline(unknown), /event/);
});

function hostFixture() {
    const context = new Proxy({ measureText: () => ({ width: 100 }) }, { get: (target, key) => target[key] ?? (() => {}) });
    const document = { createElement: () => ({ getContext: () => context }) };
    const host = { ownerDocument: document, clientWidth: 1280, clientHeight: 720 };
    const scene = new THREE.Scene(), camera = new THREE.OrthographicCamera(-24, 24, 19, -19, .1, 300);
    const registry = createPlayerRegistry(scene, (actor, kit) => buildFootballPlayer(actor, kit, document));
    const ball = new THREE.Mesh(new THREE.SphereGeometry(.25), new THREE.MeshBasicMaterial()); scene.add(ball);
    const shared = { scene, ball, renderer: { domElement: {} }, cameras: { arcade: camera },
        cameraDirector: { camera, use(camera) { this.camera = camera; } }, player: (actor, kit) => registry.get(actor, kit),
        hidePlayers: () => registry.hide(), textureFor: () => null, subscribeResize: () => () => {},
        createLayer(name) { const layer = new THREE.Group(); layer.name = name; scene.add(layer); return layer; },
        releaseLayer(layer) { scene.remove(layer); disposeArcadeScene(layer); } };
    return { host, shared, registry };
}

test('all 22 real models, one ball and registry survive live, replay, seek, camera and return', () => {
    const state = fixture(), { host, shared, registry } = hostFixture();
    const live = createArcadeView(host, state, { sharedHost: shared, rendererFactory: () => assert.fail('no second renderer') });
    live.render(state); assert.equal(live.metrics().visibleActors, 22); assert.equal(registry.models.size, 22);
    const models = [...registry.models.values()]; const qb = registry.models.get('home:1');
    assert.equal(shared.ball.parent, qb.userData.waist);
    state.actors[1].blockingKey = 'defense_DT1'; live.render(state);
    assert.equal(registry.models.get('home:2').userData.arms[0].rotation.x, -1.15);
    const expectedPosition = qb.position.clone(), expectedParent = shared.ball.parent;
    const data = createDrillRecording(state).export(), sample = createReplayTimeline(data).sample(0);
    const replay = createReplayView(host, data, { sharedHost: shared, rendererFactory: () => assert.fail('no second renderer') });
    for (const mode of ['original', 'broadcast', 'follow', 'field']) { replay.setCamera(mode); replay.render(sample); assert.equal(replay.metrics().visibleActors, 22); }
    const moved = copy(sample); moved.actors[0].x += 5; moved.actors[0].pose = 'handoff'; moved.ball.holderKey = 'offense_RB'; replay.render(moved);
    assert.equal(shared.ball.parent, registry.models.get('home:7').userData.waist);
    replay.render(sample); assert.equal(shared.ball.parent, qb.userData.waist);
    replay.dispose(); assert.equal(shared.ball.parent, expectedParent); assert.deepEqual(qb.position, expectedPosition);
    assert.deepEqual([...registry.models.values()], models); assert.equal(live.metrics().visibleActors, 22);
    live.render(state); live.dispose(); assert.equal(registry.models.size, 22); assert.equal(shared.ball.parent, shared.scene);
    disposeArcadeScene(shared.scene);
});


test('actual full-team runtime recordings round-trip all five calls in both directions', t => {
    const offsets = [[-5,26.7],[-1,26.7],[-1,24.5],[-1,28.9],[-1,22.3],[-1,31.1],[-7,29],[-1,34],[-1,9],[-1,43],[-3,16],
        [1,22],[1,25],[1,28],[1,31],[5,22],[5,27],[5,33],[3,9],[3,43],[11,20],[12,34]];
    for (const call of FULL_TEAM_CALLS) for (const direction of [-1, 1]) {
        const players = fixture().actors.map((actor, index) => ({ ...actor, x: 60 + direction * offsets[index][0], z: offsets[index][1],
            ratings: { speed: 70, acceleration: 70, awareness: 70, strength: 70, blocking: 70, tackling: 70, coverage: 70, throwing: 85, catching: 80, stamina: 80 } }));
        const state = startFullTeamPlay(createFullTeamPlay({ players, call, direction, offenseSide: 'home', seed: 832, speedScale: .925,
            exhibition: { startX: 60, goalX: direction === 1 ? 110 : 10, rearX: direction === 1 ? 10 : 110 } }));
        const recorder = createDrillRecording(state);
        for (let tick = 0; tick < 1200 && state.status === 'running'; tick++) {
            const input = { x: direction, z: tick < 80 ? .3 : 0, juke: tick === 60 ? -1 : 0 };
            if (call.endsWith('_pass') && tick === 30) input.pass = { x: state.quarterback.x + direction * 15, z: state.receivers[0].z };
            stepFullTeamPlay(state, input); recorder.capture(state);
        }
        const data = recorder.export(), replay = createReplayTimeline(data), end = replay.sample(replay.duration);
        assert.equal(data.truncated, false); assert.equal(end.actors.length, 22);
        const boundaries = new Map(state.events.filter(event => event.snapshot).map(event => [Math.round(event.time * 1000000), event.snapshot]));
        for (const [time, expected] of boundaries) {
            const observed = replay.sample(time / 1000000);
            assert.equal(observed.ball.holderKey, expected.ball.holderKey);
            assert.equal(observed.ball.phase, expected.ball.phase);
            for (let index = 0; index < 22; index++) assert.ok(Math.abs(observed.actors[index].x - expected.actors[index].x) <= .005001);
        }
        assert.equal(end.result.reason, state.result.reason); assert.equal(end.ball.holderKey, state.ball.holderKey);
        assert.equal(data.events.filter(event => event[2] === 'juke').length, state.events.filter(event => event.type === 'juke').length);
        assert.ok(Math.abs(replay.duration - state.elapsed) < .000001);
        t.diagnostic(`${call} direction ${direction}: ${data.frames.length} frames, ${data.events.length} events, ${JSON.stringify(data).length} bytes, ${state.result.reason}`);
    }
});

test('fresh full-team registry uses complete roster metadata instead of skinny context actors', () => {
    const state = fixture(), { host, shared, registry } = hostFixture();
    const players = state.actors.map(actor => ({ ...actor, height_inches: 84, weight_pounds: 250,
        skin_tone: '#a15837', lastname: 'REAL PLAYER', number: 77, appearance: { head_shape: 'square' } }));
    const actors = state.actors.map(({ key, id, side, role, x, z }) => ({ key, id, side, role, x, z }));
    const built = [], original = shared.player;
    shared.player = (player, kit) => { built.push(player); return original(player, kit); };
    const view = createArcadeView(host, { ...state, actors, players }, { sharedHost: shared });
    view.render(state);
    assert.equal(built.length, 22); assert.equal(built[0].lastname, 'REAL PLAYER');
    assert.equal(built[0].number, 77); assert.equal(built[0].appearance.head_shape, 'square');
    assert.equal(registry.models.get('home:1').scale.y, 84 / 72);
    let skin = false; registry.models.get('home:1').traverse(object => { if (object.material?.color?.getHexString() === 'a15837') skin = true; });
    assert.equal(skin, true);
    view.dispose(); disposeArcadeScene(shared.scene);
});

test('shared Coach kneeling and knee poses reset for Arcade and restore exactly after replay', () => {
    const state = fixture(), { host, shared, registry } = hostFixture();
    const coach = shared.player(state.actors[0], {});
    coach.name = 'coach-qb'; coach.userData.holderKneel = true;
    coach.userData.knees[0].rotation.x = .9;
    const view = createArcadeView(host, state, { sharedHost: shared }); view.render(state);
    assert.equal(coach.userData.holderKneel, false); assert.equal(coach.userData.knees[0].rotation.x, 0);
    coach.userData.holderKneel = true; coach.userData.knees[0].rotation.x = .7; coach.userData.carryingArm = 1;
    const name = coach.name, record = createDrillRecording(state).export();
    const replay = createReplayView(host, record, { sharedHost: shared }); replay.render(createReplayTimeline(record).sample(0));
    assert.equal(coach.userData.holderKneel, false); assert.equal(coach.userData.knees[0].rotation.x, 0);
    replay.dispose(); assert.equal(coach.userData.holderKneel, true); assert.equal(coach.userData.knees[0].rotation.x, .7);
    assert.equal(coach.userData.carryingArm, 1); assert.equal(coach.name, name); assert.equal(registry.models.size, 22);
    view.dispose(); disposeArcadeScene(shared.scene);
});

test('v2 rejects forged identities while v1 identity compatibility remains separately covered', () => {
    const record = createDrillRecording(fixture()).export();
    for (const mutate of [data => data.actors[1].id = data.actors[0].id, data => data.actors[0].id = 0,
        data => delete data.actors[0].side, data => data.actors[0].role = 'RB', data => data.actors[11].side = 'home']) {
        const input = copy(record); mutate(input); assert.throws(() => createReplayTimeline(input), /identity|sides/);
    }
});
