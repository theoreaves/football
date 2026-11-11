import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { createReplayView } from '../../resources/js/arcade/replay-view.js';
import { arcadeCameraFrame } from '../../resources/js/arcade/view.js';

const recording = { direction: 1, mode: 'passing', field: { startX: 80, goalX: 110 }, appearance: {},
    actors: [{ key: 'qb', number: 12 }, { key: 'receiver:0', number: 81 }, { key: 'defender:0', number: 31, side: 'away' }] };
const sample = (elapsed = 0, changes = {}) => ({ elapsed, direction: 1, mode: 'passing', controlledKey: 'qb', events: [],
    actors: recording.actors.map((actor, index) => ({ ...actor, x: 80 + index * 5 + elapsed, z: 26 + index * 3,
        facing: 0, pose: 'run', speed: 7, gaitPhase: elapsed, jukeLean: 0 })),
    ball: { x: 80 + elapsed, y: 1.28, z: 26, phase: 'held', holderKey: 'qb' }, ...changes });
function fixture(record = recording) {
    const lifecycle = { disposed: 0, removed: 0, lost: 0, renders: 0, controlsDisposed: 0 };
    const document = { createElement: () => ({ getContext: () => ({ clearRect() {}, fillRect() {}, strokeText() {},
        fillText() {}, measureText: () => ({ width: 100 }) }) }) };
    const host = { ownerDocument: document, clientWidth: 1280, clientHeight: 720, appendChild() {} };
    const renderer = { shadowMap: {}, domElement: { style: {}, setAttribute() {}, remove() { lifecycle.removed++; } },
        setPixelRatio() {}, setClearColor() {}, setSize() {},
        render(scene, camera) { this.scene = scene; this.camera = camera; scene.updateMatrixWorld(true); lifecycle.renders++; },
        dispose() { lifecycle.disposed++; }, forceContextLoss() { lifecycle.lost++; } };
    let controls;
    const view = createReplayView(host, record, { rendererFactory: () => renderer, controlsFactory: () => {
        controls = { target: new THREE.Vector3(), listeners: new Map(),
            addEventListener(name, callback) { this.listeners.set(name, callback); },
            removeEventListener(name) { this.listeners.delete(name); }, update() {},
            dispose() { lifecycle.controlsDisposed++; } };
        return controls;
    } });
    return { view, host, renderer, lifecycle, get controls() { return controls; } };
}
function pose(renderer) {
    const model = renderer.scene.getObjectByName('replay-player-qb');
    return [model.position.toArray(), model.rotation.toArray(), model.userData.waist.rotation.toArray(),
        ...model.userData.arms.map(arm => arm.rotation.toArray()), ...model.userData.elbows.map(elbow => elbow.rotation.toArray())];
}

test('absolute samples restore identical pose through arbitrary scrub, throws, juke and tackle', () => {
    const { view, renderer } = fixture();
    const first = sample(.2), originalData = JSON.stringify(first);
    view.render(first); const expected = pose(renderer);
    for (const action of ['throw', 'juke', 'tackled']) {
        const next = sample(2); next.actors[0].pose = action; next.actors[0].jukeLean = .22;
        next.events = [{ type: 'throw', time: 1.9 }];
        view.render(next); assert.notDeepEqual(pose(renderer), expected);
        view.render(first); assert.deepEqual(pose(renderer), expected);
    }
    assert.equal(JSON.stringify(first), originalData);
    view.dispose();
});

test('single football moves from QB to recorded flight, receiver, interception and terminal possession', () => {
    const { view, renderer } = fixture();
    const first = sample(); view.render(first);
    const ball = renderer.scene.getObjectByName('replay-football');
    assert.equal(ball.parent, renderer.scene.getObjectByName('replay-player-qb').userData.waist);
    const airborne = sample(1, { ball: { x: 92.31, y: 8.1, z: 25.7, phase: 'flight', holderKey: null } });
    view.render(airborne);
    assert.equal(ball.parent, renderer.scene); assert.deepEqual(ball.position.toArray(), [92.31, 8.1, 25.7]);
    for (const holderKey of ['receiver:0', 'defender:0']) {
        const caught = sample(2, { controlledKey: holderKey, ball: { x: 90, y: 1.28, z: 32, phase: 'held', holderKey } });
        view.render(caught); assert.equal(ball.parent, renderer.scene.getObjectByName(`replay-player-${holderKey}`).userData.waist);
        caught.ball.phase = 'dead'; caught.actors.find(actor => actor.key === holderKey).pose = 'tackled';
        view.render(caught); assert.equal(ball.parent, renderer.scene.getObjectByName(`replay-player-${holderKey}`).userData.waist);
        assert.equal(renderer.scene.getObjectByName(`replay-player-${holderKey}`).userData.cradlingBall, true);
    }
    view.render(airborne); assert.equal(ball.parent, renderer.scene);
    view.render(first);
    let count = 0; renderer.scene.traverse(object => { if (object.name === 'replay-football') count++; });
    assert.equal(count, 1); view.dispose();
});

test('camera presets are distinct, original matches approved frame and never mutate recorded movement', () => {
    for (const direction of [-1, 1]) for (const [width, height] of [[1280, 720], [390, 540], [844, 390]]) {
        const { view, renderer, host } = fixture({ ...recording, direction });
        host.clientWidth = width; host.clientHeight = height; view.resize();
        const input = sample(1); const before = JSON.stringify(input); view.render(input);
        const expected = arcadeCameraFrame(width, height, direction);
        assert.deepEqual(renderer.camera.position.toArray(), expected.position);
        assert.equal(renderer.camera.left, expected.left);
        const positions = [];
        for (const preset of ['broadcast', 'follow', 'field']) {
            assert.equal(view.setCamera(preset), true);
            positions.push(renderer.camera.position.toArray().join(','));
            const point = new THREE.Vector3(input.actors[0].x, 1.2, input.actors[0].z).project(renderer.camera);
            assert.ok(Math.abs(point.x) < .001 && Math.abs(point.y) < .1);
            const previous = renderer.camera.position.x;
            view.render(sample(2)); assert.notEqual(renderer.camera.position.x, previous);
            view.render(input);
        }
        assert.equal(new Set(positions).size, 3);
        assert.equal(view.setCamera('invalid'), false);
        view.setCamera('original'); assert.deepEqual(renderer.camera.position.toArray(), expected.position);
        assert.equal(JSON.stringify(input), before); view.dispose();
    }
});

test('free orbit preserves user transform through pause, scrub, resize and bounds pan safely', () => {
    const setup = fixture(); const { view, renderer, host } = setup;
    view.render(sample()); view.setCamera('free');
    const controls = setup.controls;
    renderer.camera.position.set(75, 15, 46); renderer.camera.lookAt(84, 1, 26);
    controls.target.set(84, 1, 26);
    const position = renderer.camera.position.toArray(), rotation = renderer.camera.quaternion.toArray();
    view.render(sample(8)); view.render(sample(1)); host.clientWidth = 390; host.clientHeight = 540; view.resize();
    assert.deepEqual(renderer.camera.position.toArray(), position); assert.deepEqual(renderer.camera.quaternion.toArray(), rotation);
    assert.deepEqual(controls.target.toArray(), [84, 1, 26]);
    assert.equal(controls.enablePan && controls.enableZoom && controls.enableRotate, true);
    assert.equal(controls.enableDamping, false);
    assert.ok(controls.minDistance > 0 && controls.maxDistance < 200 && controls.maxPolarAngle < Math.PI / 2);
    controls.target.set(999, -50, 999); controls.listeners.get('change')();
    assert.deepEqual(controls.target.toArray(), [130, 0, 63.33]);
    view.setCamera('original'); assert.equal(controls.listeners.size, 0); assert.equal(setup.lifecycle.controlsDisposed, 1);
    view.dispose();
});

test('repeated mounts reuse resources while rendering and release every resource exactly once on return', () => {
    for (let mount = 0; mount < 3; mount++) {
        const { view, renderer, lifecycle } = fixture(); view.render(sample()); view.setCamera('free');
        const resources = new Set();
        renderer.scene.traverse(object => {
            if (object.geometry) resources.add(object.geometry);
            for (const material of (Array.isArray(object.material) ? object.material : [object.material])) {
                if (!material) continue;
                resources.add(material);
                Object.values(material).filter(value => value?.isTexture).forEach(value => resources.add(value));
            }
        });
        const counts = new Map([...resources].map(value => [value, 0]));
        resources.forEach(value => value.addEventListener('dispose', () => counts.set(value, counts.get(value) + 1)));
        for (let frame = 0; frame < 10; frame++) view.render(sample(frame / 2));
        assert.ok([...counts.values()].every(count => count === 0));
        view.dispose(); view.dispose(); const renders = lifecycle.renders;
        view.render(sample()); view.resize(); view.setCamera('free');
        assert.equal(lifecycle.renders, renders);
        assert.ok([...counts.values()].every(count => count === 1));
        assert.deepEqual([lifecycle.disposed, lifecycle.lost, lifecycle.removed, lifecycle.controlsDisposed], [1, 1, 1, 1]);
        assert.equal(view.metrics().disposed, true); assert.equal(renderer.scene.children.length, 0);
    }
});

test('imported artwork cannot initiate cross-origin, file, script or data URL loads', () => {
    for (const url of ['https://external.invalid/logo.png', '//external.invalid/logo.png', 'file:///etc/passwd',
        'javascript:alert(1)', 'data:image/png;base64,AAAA']) {
        const { view } = fixture({ ...recording, appearance: { home: { midfield_logo: url, helmet_logo_left: url } } });
        view.render(sample()); view.dispose();
    }
});

test('all tracking cameras prefer the interception holder even when controlled actor remains QB', () => {
    const setup = fixture(); const { view, renderer } = setup;
    const interception = sample(3, { controlledKey: 'qb',
        ball: { x: 93, y: 1.28, z: 32, phase: 'held', holderKey: 'defender:0' } });
    const holder = interception.actors.find(actor => actor.key === 'defender:0');
    view.render(interception);
    for (const mode of ['follow', 'broadcast', 'field', 'free']) {
        view.setCamera(mode);
        const target = new THREE.Vector3(holder.x, mode === 'broadcast' ? 0 : 1.2, holder.z).project(renderer.camera);
        assert.ok(Math.abs(target.x) < 1e-10 && Math.abs(target.y) < 1e-10, `${mode} follows holder`);
    }
    view.setCamera('follow');
    view.render(sample(3, { ball: { x: 93, y: 5, z: 32, phase: 'flight', holderKey: null } }));
    const qb = interception.actors[0];
    const target = new THREE.Vector3(qb.x, 1.2, qb.z).project(renderer.camera);
    assert.ok(Math.abs(target.x) < 1e-10 && Math.abs(target.y) < 1e-10);
    view.dispose();
});

test('constructor failure releases renderer, canvas and completed scene resources', () => {
    const lifecycle = { disposed: 0, removed: 0, lost: 0 };
    const resources = new Set(), disposed = new Set();
    const add = THREE.Scene.prototype.add;
    THREE.Scene.prototype.add = function (...objects) {
        for (const object of objects) object.traverse(child => {
            for (const resource of [child.geometry, child.material].filter(Boolean)) {
                resources.add(resource); resource.addEventListener('dispose', () => disposed.add(resource));
            }
        });
        return add.apply(this, objects);
    };
    const renderer = { shadowMap: {}, domElement: { style: {}, setAttribute() {}, remove() { lifecycle.removed++; } },
        setPixelRatio() {}, setClearColor() {}, dispose() { lifecycle.disposed++; }, forceContextLoss() { lifecycle.lost++; } };
    const host = { appendChild() {}, ownerDocument: { createElement() { throw new Error('canvas construction failed'); } } };
    try {
        assert.throws(() => createReplayView(host, recording, { rendererFactory: () => renderer }), /canvas construction failed/);
        assert.ok(resources.size > 0);
        assert.deepEqual(disposed, resources);
        assert.deepEqual(lifecycle, { disposed: 1, removed: 1, lost: 1 });
    } finally { THREE.Scene.prototype.add = add; }
});

test('reset camera restores the active preset and resets free exploration around current holder', () => {
    const setup = fixture(); const { view, renderer, lifecycle } = setup;
    view.render(sample(2)); view.setCamera('free');
    const expected = renderer.camera.position.toArray(), firstControls = setup.controls;
    renderer.camera.position.set(1, 2, 3); firstControls.target.set(100, 8, 40);
    assert.equal(view.resetCamera(), true);
    assert.equal(view.metrics().camera, 'free'); assert.deepEqual(renderer.camera.position.toArray(), expected);
    assert.equal(firstControls.listeners.size, 0); assert.equal(lifecycle.controlsDisposed, 1);
    assert.notEqual(setup.controls, firstControls);
    view.setCamera('field'); const field = renderer.camera.position.toArray();
    renderer.camera.position.set(1, 2, 3); view.resetCamera();
    assert.equal(view.metrics().camera, 'field'); assert.deepEqual(renderer.camera.position.toArray(), field);
    view.dispose(); assert.equal(view.resetCamera(), false);
});
