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

test('camera follows the snap point while retaining orbit zoom pan and camera mode', async () => {
    const { captureCamera, restoreCamera } = await import('../../resources/js/practice/camera-state.js');
    const saved = captureCamera('overhead', [35, 70, 29.7], [35, 0, 29.7], [35, 0, 26.7]);
    const next = restoreCamera(saved, [63, 0, 26.7]);
    assert.equal(next.mode, 'overhead');
    assert.deepEqual(next.target, [63, 0, 29.7]);
    assert.deepEqual(next.position, [63, 70, 29.7]);
    assert.equal(restoreCamera({mode: 'bogus', offset: [1, 2, 3], pan: [0, 0, 0]}, [60, 0, 26.7]), null);
    assert.equal(restoreCamera({mode: 'broadcast', offset: [NaN, 2, 3], pan: [0, 0, 0]}, [60, 0, 26.7]), null);
});

test('both teams move smoothly into separate huddles without changing the saved play', async () => {
    const { sampleHuddle } = await import('../../resources/js/practice/huddle.js');
    const frame = { players: Array.from({length:22},(_,i)=>({id:i,side:i<11?'home':'away',x:40+i/2,z:12+i})), ball:{x:52,y:1,z:27},event:'Tackle' };
    const snapshot=JSON.stringify(frame);
    const start=sampleHuddle(frame,65,'away',0), end=sampleHuddle(frame,65,'away',1);
    assert.equal(start.players[0].x,frame.players[0].x);
    assert.equal(end.players.length,22);
    assert.equal(new Set(end.players.map(player=>`${player.x},${player.z}`)).size,22);
    assert.ok(end.players.slice(0,11).every(player=>player.x<65));
    assert.ok(end.players.slice(11).every(player=>player.x>65));
    assert.equal(end.ball.x,66); assert.equal(end.ball.y,.25);
    assert.ok(end.huddle); assert.equal(JSON.stringify(frame),snapshot);
    for(const line of [10,110]) assert.ok(sampleHuddle(frame,line,'home',1).players.every(player=>player.x>0&&player.x<120));
});

test('players break huddle into the selected formation before the snap', async () => {
    const { sampleHuddle, sampleBreakHuddle } = await import('../../resources/js/practice/huddle.js');
    const formation = { players: Array.from({length:22},(_,i)=>({id:i,side:i<11?'home':'away',x:40+i/2,z:12+i})), ball:{x:39,y:1,z:26.7},event:'Snap' };
    const snapshot=JSON.stringify(formation);
    const huddle=sampleHuddle(formation,40,'home',1);
    const start=sampleBreakHuddle(formation,40,'home',0);
    const end=sampleBreakHuddle(formation,40,'home',1);
    assert.deepEqual(start.players,huddle.players.map(({facingX,facingZ,...player})=>player));
    for(let i=0;i<22;i++) { assert.equal(end.players[i].x,formation.players[i].x);assert.equal(end.players[i].z,formation.players[i].z); }
    assert.deepEqual(end.ball,formation.ball);
    assert.equal(start.event,'Breaking huddle · Moving into formation');
    assert.equal(end.event,'Set · Ready for the snap');
    assert.equal(JSON.stringify(formation),snapshot);
});

test('scoreboard uses the selected state without exposing the saved result', async () => {
    const {scoreboardText}=await import('../../resources/js/practice/scoreboard.js');
    const before={clock:32,quarter:1,status:'playing',possession:'home',phase:'scrimmage',down:2,distance:4,spot:99,home_score:0,away_score:0};
    const after={...before,clock:0,phase:'extra_point',home_score:6};
    assert.equal(scoreboardText(before,{home:'Hawks',away:'Tigers'}).score,'Tigers 0 — Hawks 0');
    assert.equal(scoreboardText(before,{home:'Hawks',away:'Tigers'}).clock,'Q1 · 00:32');
    assert.equal(scoreboardText(after,{home:'Hawks',away:'Tigers'}).score,'Tigers 0 — Hawks 6');
    assert.equal(scoreboardText(after,{home:'Hawks',away:'Tigers'}).situation,'Hawks · Extra point');
});

test('behind QB camera looks downfield and preserves zoom and pan when possession changes', async () => {
    const { cameraPreset, captureCamera, restoreCamera } = await import('../../resources/js/practice/camera-state.js');
    const focus = [40, 0, 26.7];
    assert.deepEqual(cameraPreset('quarterback', focus, 1).position, [18, 10, 26.7]);
    assert.deepEqual(cameraPreset('quarterback', focus, -1).position, [62, 10, 26.7]);
    const saved = captureCamera('quarterback', [12, 14, 33.7], [42, 1, 29.7], focus, 1);
    assert.deepEqual(restoreCamera(saved, [70, 0, 26.7], 1).position, [42, 14, 33.7]);
    const reversed = restoreCamera(saved, [70, 0, 26.7], -1);
    assert.equal(reversed.mode, 'quarterback');
    assert.deepEqual(reversed.target, [68, 1, 23.7]);
    [98, 14, 19.7].forEach((value, i) => assert.ok(Math.abs(reversed.position[i] - value) < 1e-9));
});

test('jersey numbers render the roster number on both outward-facing shirt surfaces', async () => {
    const THREE = await import('three');
    const { addJerseyNumbers } = await import('../../resources/js/practice/jersey-numbers.js');
    const drawn = [];
    const document = { createElement: () => ({ getContext: () => ({ strokeText: text => drawn.push(text), fillText: text => drawn.push(text) }) }) };
    for (const value of [0, 8, 99]) {
        const jersey = new THREE.Group();
        addJerseyNumbers(jersey, { number: value }, document);
        assert.equal(jersey.children.length, 2);
        for (const mesh of jersey.children) {
            assert.ok(mesh.position.z * new THREE.Vector3(0, 0, 1).applyQuaternion(mesh.quaternion).z > 0);
            assert.equal(mesh.material.map.colorSpace, THREE.SRGBColorSpace);
        }
        assert.equal(jersey.children[0].material, jersey.children[1].material);
        assert.deepEqual(drawn.slice(-2), [String(value), String(value)]);
    }
    const unnumbered = new THREE.Group();
    addJerseyNumbers(unnumbered, {}, document);
    assert.equal(unnumbered.children.length, 0);
});

test('carrier indicator follows explicit possession rather than nearby tacklers and hides during transitions', async () => {
    const { ballCarrier, carrierLabel } = await import('../../resources/js/practice/ball-carrier.js');
    const players = [{ team: 'offense', role: 'WR1', name: 'Receiver', number: 8, x: 50, z: 20 }, { team: 'defense', role: 'CB1', name: 'Corner', number: 21, x: 50, z: 20 }];
    const animation = { duration: 6, players: players.map(player => ({ ...player, path: [[0, 50, 0, 20], [6, 50, 0, 20]] })), ball: [[0, 50, 1, 20], [6, 50, 1, 20]], events: [[0, 'Pass']], ballHolders: [[0, 'offense', 'WR1'], [2.2, null, null], [3.8, 'defense', 'CB1']] };
    assert.equal(ballCarrier(sampleEnginePlay(animation, 1)).name, 'Receiver');
    assert.equal(ballCarrier(sampleEnginePlay(animation, 3)), null);
    const caught = sampleEnginePlay(animation, 4);
    assert.equal(ballCarrier(caught).name, 'Corner');
    assert.equal(carrierLabel(ballCarrier(caught)), 'Ball: #21 Corner');
    assert.equal(ballCarrier(caught, 'huddle'), null);
    assert.equal(ballCarrier(caught, 'liningup'), null);
    assert.equal(ballCarrier(sampleEnginePlay(animation, 0)).name, 'Receiver');
});

test('older saved replays identify holders without highlighting high or loose balls', async () => {
    const { ballCarrier } = await import('../../resources/js/practice/ball-carrier.js');
    const frame = { players: [{ team: 'offense', role: 'QB', x: 35, z: 26 }], ball: { x: 35, y: 1, z: 26 } };
    assert.equal(ballCarrier(frame).role, 'QB');
    assert.equal(ballCarrier({ ...frame, ball: { ...frame.ball, y: 7 } }), null);
    assert.equal(ballCarrier({ ...frame, ball: { ...frame.ball, y: .25 } }), null);
    assert.equal(ballCarrier({ ...frame, ballHolder: null }), null);
});

test('jersey text uses the selected uniform number and outline colors with legacy defaults', async () => {
    const THREE = await import('three');
    const { addJerseyNumbers } = await import('../../resources/js/practice/jersey-numbers.js');
    const rendered = [];
    const context = { strokeText() { rendered.push(this.strokeStyle); }, fillText() { rendered.push(this.fillStyle); } };
    const document = { createElement: () => ({ getContext: () => context }) };
    addJerseyNumbers(new THREE.Group(), { number: 12 }, document, { number: '#ffcc00', number_outline: '#223344' });
    assert.deepEqual(rendered, ['#223344', '#ffcc00']);
    addJerseyNumbers(new THREE.Group(), { number: 12 }, document);
    assert.deepEqual(rendered.slice(-2), ['#111111', '#ffffff']);
});

test('CPU autoplay waits for the huddle and stops for pauses quarter notices hidden tabs and final games', async () => {
    const { canAdvanceCpu } = await import('../../resources/js/practice/cpu-flow.js');
    const ready = { enabled: true, visible: true, ready: true, submitting: false, dialogOpen: false, final: false };
    assert.equal(canAdvanceCpu(ready), true);
    for (const blocked of [{ enabled: false }, { visible: false }, { ready: false }, { submitting: true }, { dialogOpen: true }, { final: true }]) {
        assert.equal(canAdvanceCpu({ ...ready, ...blocked }), false);
    }
});

test('camera anchor follows ball flight without changing zoom orbit or manual pan', async () => {
    const { cameraPreset, translateCameraAnchor, captureCamera, restoreCamera } = await import('../../resources/js/practice/camera-state.js');
    for (const mode of ['broadcast', 'overhead', 'quarterback']) {
        const start = [35, 0, 26.7];
        let {position, target} = cameraPreset(mode, start);
        position = position.map((value, i) => value + [2, 0, 3][i]);
        target = target.map((value, i) => value + [2, 0, 3][i]);
        const offset = position.map((value, i) => value - target[i]);
        let anchor = start;
        for (const ball of [[30, 1, 26.7], [50, 12, 18], [75, 1, 5]]) {
            ({position, target} = translateCameraAnchor(position, target, anchor, ball));
            anchor = ball;
            position.forEach((value, i) => assert.ok(Math.abs(value - target[i] - offset[i]) < 1e-10));
            target.forEach((value, i) => assert.ok(Math.abs(value - ball[i] - [2, 0, 3][i]) < 1e-10));
        }
        const saved = captureCamera(mode, position, target, anchor);
        const next = restoreCamera(saved, [80, 0, 26.7]);
        assert.deepEqual(next.target, [82, 0, 29.7]);
        next.position.forEach((value, i) => assert.ok(Math.abs(value - next.target[i] - offset[i]) < 1e-10));
    }
});

test('football player meshes use body data short sleeves exposed skin and optional stripes', async () => {
    const { buildFootballPlayer, animateFootballPlayer } = await import('../../resources/js/practice/player-model.js');
    const document = { createElement: () => ({ getContext: () => ({ strokeText() {}, fillText() {} }) }) };
    const kit = {shirt:'#aa0000', helmet:'#cccccc', helmet_stripe_enabled:true, pants_stripe_enabled:true, shoulder_stripe_enabled:true};
    const small = buildFootballPlayer({number:7,height_inches:68,weight_pounds:180,skin_tone:'#593b2c'}, kit, document);
    const large = buildFootballPlayer({number:73,height_inches:79,weight_pounds:315,skin_tone:'#edc5a3'}, kit, document);
    assert.ok(large.scale.y > small.scale.y); assert.ok(large.scale.x > small.scale.x);
    assert.equal(small.userData.legs.length,2); assert.equal(small.userData.arms.length,2);
    assert.equal(small.userData.arms[0].children[1].material.color.getHexString(),'593b2c');
    assert.ok(small.children.some(mesh=>mesh.geometry?.type==='TorusGeometry'));
    animateFootballPlayer(small,true,.1,0);
    assert.notEqual(small.userData.arms[0].rotation.x,0);
    animateFootballPlayer(small,false,.1,0);
    assert.equal(small.userData.legs[0].rotation.x,0);
});
