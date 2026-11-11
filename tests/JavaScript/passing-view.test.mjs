import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { arcadeCameraFrame, arcadeGroundPoint, arcadeActorEntries, arcadeRoutePoints, attachArcadeBall, createArcadeView } from '../../resources/js/arcade/view.js';
import { createPassingDrill, getPassTrajectory } from '../../resources/js/arcade/passing.js';
import { createDrill, startDrill } from '../../resources/js/arcade/simulation.js';

const player = (id, number, side = 'home') => ({ id, number, name: `Player ${id}`, side, ratings: { speed: 70, acceleration: 70, throwing_accuracy: 80, throwing_power: 80 } });
const payload = { mode: 'passing', runner: player(1, 22), quarterback: player(2, 12),
    receivers: [player(3, 81), player(4, 82), player(5, 83)], defenders: [player(6, 31, 'away'), player(7, 32, 'away')],
    appearance: { home: { uniform: { shirt: '#1256ab', helmet: '#fff', number: '#fff' } }, away: { uniform: { shirt: '#a12635' } } } };
const close = (actual, expected, epsilon = 1e-7) => assert.ok(Math.abs(actual - expected) < epsilon, `${actual} should equal ${expected}`);

function fixture(options = {}) {
    const text = [], lifecycle = { disposed: 0, contextLost: 0, removed: 0, renders: 0 };
    const document = { createElement: () => ({ getContext: () => ({
        clearRect() {}, fillRect() {}, strokeText() {}, fillText: (...args) => text.push(args), measureText: () => ({ width: 100 }),
    }) }) };
    const host = { ownerDocument: document, clientWidth: 1280, clientHeight: 720, appendChild() {}, left: 37, top: 84 };
    const renderer = { shadowMap: {}, domElement: { style: {}, setAttribute() {},
        getBoundingClientRect: () => ({ left: host.left, top: host.top, width: host.clientWidth, height: host.clientHeight }),
        remove() { lifecycle.removed++; } },
    info: { memory: { geometries: 0, textures: 0 }, render: { calls: 0 } },
    setPixelRatio() {}, setClearColor() {}, setSize(width, height) { this.size = [width, height]; },
    render(scene, camera) { this.scene = scene; this.camera = camera; scene.updateMatrixWorld(true); lifecycle.renders++; },
    dispose() { lifecycle.disposed++; }, forceContextLoss() { lifecycle.contextLost++; } };
    const view = createArcadeView(host, { ...payload, ...options }, { rendererFactory: () => renderer });
    return { view, renderer, host, text, lifecycle };
}

function resources(scene) {
    const geometry = new Set(), material = new Set(), texture = new Set();
    scene.traverse(object => {
        if (object.geometry) geometry.add(object.geometry);
        for (const paint of (Array.isArray(object.material) ? object.material : [object.material])) {
            if (!paint) continue;
            material.add(paint);
            for (const value of Object.values(paint)) if (value?.isTexture) texture.add(value);
        }
    });
    return { geometry, material, texture };
}

function assertSameResources(before, after) {
    for (const kind of Object.keys(before)) {
        assert.equal(after[kind].size, before[kind].size, `${kind} count is stable`);
        for (const value of before[kind]) assert.ok(after[kind].has(value), `${kind} identity is reused`);
    }
}

test('ground projection round-trips CSS pixels at portrait and landscape sizes in both directions', () => {
    for (const [width, height] of [[1280, 720], [390, 540], [844, 390]]) for (const direction of [1, -1]) {
        const frame = arcadeCameraFrame(width, height, direction);
        const camera = new THREE.OrthographicCamera(frame.left, frame.right, frame.top, frame.bottom, .1, 300);
        camera.position.set(...frame.position); camera.lookAt(...frame.target); camera.updateProjectionMatrix(); camera.updateMatrixWorld();
        const rect = { left: 42, top: 77, width, height };
        for (const [x, z] of [[direction === 1 ? 80 : 40, 26.665], [direction === 1 ? 110 : 10, 0], [direction === 1 ? 74 : 46, 53.33]]) {
            const projected = new THREE.Vector3(x, 0, z).project(camera);
            const point = arcadeGroundPoint(camera, rect, rect.left + (projected.x + 1) * width / 2, rect.top + (1 - projected.y) * height / 2);
            close(point.x, x); close(point.z, z);
        }
        assert.equal(arcadeGroundPoint(camera, { ...rect, width: 0 }, 5, 5), null);
        assert.equal(arcadeGroundPoint(camera, rect, NaN, 5), null);
    }
});

test('passing actor identities exclude the runner alias and preserve individual roster models', () => {
    const state = createPassingDrill(payload);
    assert.deepEqual(arcadeActorEntries(state).map(([key]) => key), ['qb', 'receiver:0', 'receiver:1', 'receiver:2', 'defender:0']);
    const { view, renderer } = fixture();
    view.render(state);
    assert.equal(view.metrics().visibleActors, 5);
    assert.equal(view.metrics().actors, 6);
    assert.equal(renderer.scene.getObjectByName('arcade-player-runner'), undefined);
    const qb = renderer.scene.getObjectByName('arcade-player-qb');
    const receiver = renderer.scene.getObjectByName('arcade-player-receiver:0');
    assert.notEqual(qb, receiver);
    assert.ok(qb.getObjectByName('helmet-shell'));
    assert.ok(receiver.getObjectByName('jersey-number-front-surface'));
    view.dispose();
});

test('one ball moves from quarterback to flight, receiver, interception and ground without stale carries', () => {
    const scene = new THREE.Scene(), ball = new THREE.Mesh(new THREE.SphereGeometry(.25), new THREE.MeshBasicMaterial());
    scene.add(ball);
    const models = new Map(['qb', 'receiver:0', 'defender:0'].map(key => {
        const model = new THREE.Group(), waist = new THREE.Group();
        model.userData.waist = waist; model.add(waist); scene.add(model);
        return [key, { model }];
    }));
    const state = { mode: 'passing', elapsed: .5, ball: { phase: 'held', holderKey: 'qb', x: 80, y: 1.4, z: 26 } };
    assert.equal(attachArcadeBall(ball, scene, models, state), 'qb');
    assert.equal(ball.parent, models.get('qb').model.userData.waist);
    close(ball.position.y, .45);
    state.ball = { phase: 'flight', holderKey: null, x: 88, y: 4, z: 20 };
    assert.equal(attachArcadeBall(ball, scene, models, state), null);
    assert.equal(ball.parent, scene); assert.deepEqual(ball.position.toArray(), [88, 4, 20]);
    for (const { model } of models.values()) assert.equal(model.userData.cradlingBall, false);
    for (const key of ['receiver:0', 'defender:0']) {
        state.ball = { phase: key === 'defender:0' ? 'dead' : 'held', holderKey: key };
        assert.equal(attachArcadeBall(ball, scene, models, state), key);
        assert.equal(ball.parent, models.get(key).model.userData.waist);
        assert.deepEqual([...models].filter(([, { model }]) => model.userData.cradlingBall).map(([k]) => k), [key]);
    }
    state.ball = { phase: 'dead', holderKey: null, x: 96, y: 0, z: 10 };
    attachArcadeBall(ball, scene, models, state);
    assert.equal(ball.parent, scene); assert.deepEqual(ball.position.toArray(), [96, .22, 10]);
    let balls = 0; scene.traverse(object => { if (object === ball) balls++; }); assert.equal(balls, 1);
    ball.geometry.dispose(); ball.material.dispose();
});

test('receiver route path keeps immutable waypoints and does not follow the moving actor', () => {
    const state = createPassingDrill(payload), receiver = state.receivers[0];
    const points = arcadeRoutePoints(receiver);
    assert.ok(points.length > 1);
    receiver.x += 10; receiver.z += 2; receiver.route.index++;
    assert.deepEqual(arcadeRoutePoints(receiver), points);
    assert.ok(points.every(point => point.y === .14));
});

test('aim preview follows shared trajectory, contains an arc and hides after throw or cancellation', () => {
    const { view, renderer } = fixture(), state = createPassingDrill(payload);
    startDrill(state);
    const target = { x: 99, z: 18 };
    view.render(state, { aim: target });
    const arc = renderer.scene.getObjectByName('arcade-pass-trajectory'), ring = renderer.scene.getObjectByName('arcade-pass-target');
    const points = getPassTrajectory(state, target, { samples: 32 });
    assert.equal(arc.visible, true); assert.equal(ring.visible, true);
    assert.equal(arc.geometry.drawRange.count, points.length);
    assert.ok(points.some(point => point.y > points[0].y));
    points.forEach((point, index) => {
        close(arc.geometry.attributes.position.getX(index), point.x, 1e-5);
        close(arc.geometry.attributes.position.getY(index), point.y, 1e-5);
        close(arc.geometry.attributes.position.getZ(index), point.z, 1e-5);
    });
    close(ring.position.x, points.at(-1).x); close(ring.position.z, points.at(-1).z);
    view.setAim(null);
    assert.equal(arc.visible, false); assert.equal(ring.visible, false);
    view.setAim(target);
    assert.equal(arc.visible, true);
    state.ball.phase = 'flight'; state.ball.holderKey = null;
    view.render(state);
    assert.equal(arc.visible, false); assert.equal(ring.visible, false);
    view.dispose();
});

test('resizing updates camera picking and keeps receiver labels readable in CSS pixels', () => {
    const { view, renderer, host } = fixture(), state = createPassingDrill(payload);
    view.render(state);
    for (const [width, height] of [[390, 540], [844, 390], [1280, 720]]) {
        host.clientWidth = width; host.clientHeight = height; view.resize();
        const actor = state.receivers[0], point = new THREE.Vector3(actor.x, 0, actor.z).project(renderer.camera);
        const ground = view.groundPoint(host.left + (point.x + 1) * width / 2, host.top + (1 - point.y) * height / 2);
        close(ground.x, actor.x); close(ground.z, actor.z);
        const label = renderer.scene.getObjectByName('arcade-route-label-0');
        close(label.scale.y / (renderer.camera.top - renderer.camera.bottom) * height, 24);
    }
    view.dispose();
    assert.equal(view.groundPoint(100, 100), null);
});

test('route changes, defender toggles, held/caught balls and repeated retries reuse all scene resources', () => {
    const { view, renderer, text } = fixture();
    let state = createPassingDrill({ ...payload, defenderCount: 2 });
    view.render(state);
    const before = resources(renderer.scene);
    const receiver = renderer.scene.getObjectByName('arcade-player-receiver:0');
    const defender = renderer.scene.getObjectByName('arcade-player-defender:1');
    const line = renderer.scene.getObjectByName('arcade-route-0');
    const originalPoints = Array.from(line.geometry.attributes.position.array);
    let changedRoute = false;
    for (let i = 0; i < 40; i++) {
        state = createPassingDrill({ ...payload, defenderCount: i % 2 ? 2 : 1, routeSet: i % 2 ? 'slants' : 'comeback' });
        startDrill(state);
        view.render(state, { aim: { x: 100 + i / 10, z: 15 } });
        assert.equal(defender.visible, Boolean(i % 2));
        assert.equal(renderer.scene.getObjectByName('arcade-player-receiver:0'), receiver);
        assert.equal(renderer.scene.getObjectByName('arcade-route-0'), line);
        changedRoute ||= Array.from(line.geometry.attributes.position.array).some((value, index) => value !== originalPoints[index]);
        state.ball.phase = 'held'; state.ball.holderKey = 'receiver:0'; state.controlledKey = 'receiver:0'; state.runner = state.receivers[0];
        view.render(state, { aim: null });
        assert.equal(renderer.scene.getObjectByName('arcade-football').parent, receiver.userData.waist);
        assert.equal(line.visible, false);
        assertSameResources(before, resources(renderer.scene));
    }
    assert.equal(changedRoute, true);
    assert.ok(text.some(([label]) => label.includes('Slant'.toUpperCase())));
    assert.ok(text.some(([label]) => label.includes('Comeback'.toUpperCase())));
    view.dispose();
});

test('running mode avoids unused offense and repeated mode toggles reuse lazily added models', () => {
    const { view, renderer } = fixture({ mode: 'running' });
    assert.equal(view.metrics().actors, 3, 'only runner and two defenders are constructed');
    view.render(createDrill({ ...payload, defenderCount: 2 }));
    assert.equal(view.metrics().visibleActors, 3);
    assert.equal(renderer.scene.getObjectByName('arcade-player-qb'), undefined);
    assert.equal(renderer.scene.getObjectByName('arcade-player-receiver:0'), undefined);
    assert.equal(renderer.scene.getObjectByName('arcade-route-0'), undefined);
    const runner = renderer.scene.getObjectByName('arcade-player-runner');
    view.render(createPassingDrill(payload));
    assert.equal(view.metrics().actors, 7, 'passing models are added only when first used');
    const before = resources(renderer.scene);
    for (let i = 0; i < 10; i++) {
        view.render(createDrill({ ...payload, defenderCount: 2 }));
        assert.equal(view.metrics().visibleActors, 3);
        assert.equal(renderer.scene.getObjectByName('arcade-player-qb').visible, false);
        assert.equal(renderer.scene.getObjectByName('arcade-route-0').visible, false);
        assert.equal(renderer.scene.getObjectByName('arcade-football').parent, runner.userData.waist);
        view.render(createPassingDrill(payload));
        assert.equal(view.metrics().visibleActors, 5);
        assert.equal(runner.visible, false);
        assertSameResources(before, resources(renderer.scene));
    }
    view.dispose();
});

test('disposal releases each shared resource and WebGL lifecycle exactly once', () => {
    const { view, renderer, lifecycle } = fixture();
    const state = createPassingDrill(payload);
    view.render(state);
    const before = resources(renderer.scene), calls = new Map();
    for (const set of Object.values(before)) for (const resource of set) {
        calls.set(resource, 0); resource.addEventListener('dispose', () => calls.set(resource, calls.get(resource) + 1));
    }
    view.dispose(); view.dispose();
    const renderCount = lifecycle.renders;
    view.render(state); view.resize(); view.setAim({ x: 98, z: 20 }, state);
    assert.equal(lifecycle.renders, renderCount);
    assert.equal(lifecycle.disposed, 1); assert.equal(lifecycle.contextLost, 1); assert.equal(lifecycle.removed, 1);
    for (const count of calls.values()) assert.equal(count, 1);
    assert.equal(renderer.scene.children.length, 0);
    assert.equal(view.metrics().actors, 0); assert.equal(view.metrics().routes, 0); assert.equal(view.metrics().disposed, true);
});

test('live exhibition pan translates the camera without resizing the drawing buffer',()=>{
    const exhibition={startX:35,goalX:110,rearX:10,firstDownX:45};
    const {view,renderer,host}=fixture({exhibition});
    let sizes=0;const original=renderer.setSize.bind(renderer);renderer.setSize=(...args)=>{sizes++;original(...args);};
    const state=createPassingDrill({...payload,exhibition});
    for(let tick=0;tick<30;tick++){state.runner.x=35+tick*.1;state.tick=tick;state.elapsed=tick/60;view.render(state);}
    assert.equal(sizes,0);close(renderer.camera.position.x,state.runner.x+10);
    host.clientWidth=900;view.resize();assert.equal(sizes,1);view.dispose();
});
