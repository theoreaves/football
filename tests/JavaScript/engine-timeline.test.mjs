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
