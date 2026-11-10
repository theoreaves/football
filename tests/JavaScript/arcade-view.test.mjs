import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { arcadeCameraFrame, arcadePlayerPose, disposeArcadeScene, setArcadeDefenderVisibility } from '../../resources/js/arcade/view.js';
import { createDrill } from '../../resources/js/arcade/simulation.js';
import { buildFieldSurface } from '../../resources/js/practice/field-scene.js';

test('fixed orthographic drill camera projects advancing right and up-field upward at all aspect ratios', () => {
    for (const [width, height] of [[1280, 720], [390, 540], [844, 390]]) {
        const frame = arcadeCameraFrame(width, height);
        const camera = new THREE.OrthographicCamera(frame.left, frame.right, frame.top, frame.bottom, .1, 300);
        camera.position.set(...frame.position); camera.lookAt(...frame.target); camera.updateMatrixWorld();
        const project = (x, z) => new THREE.Vector3(x, 0, z).project(camera);
        assert.ok(project(100, 26).x > project(90, 26).x);
        assert.ok(project(90, 15).y > project(90, 35).y);
        assert.ok(Math.abs(project(90, 15).x - project(90, 35).x) < 1e-10);
        for (const x of [80, 110]) for (const z of [0, 53.33]) {
            const point = project(x, z);
            assert.ok(Math.abs(point.x) < 1 && Math.abs(point.y) < 1);
        }
        assert.ok(frame.right - frame.left < 100, 'not a tiny full-field view');
    }
});

test('velocity drives gait and juke remains distinct from a tackle', () => {
    const actor = { vx: 6, vz: -2, facing: Math.atan2(-2, 6), jukeRemaining: .2 };
    const running = { status: 'running', result: null };
    const pose = arcadePlayerPose(actor, running);
    assert.equal(pose.speed, Math.hypot(6, -2));
    assert.equal(pose.moving, true); assert.equal(pose.jukeLean, -.22); assert.equal(pose.tackled, false);
    assert.equal(pose.heading, Math.PI / 2 - actor.facing);
    assert.equal(arcadePlayerPose({ vx: 0, vz: 0 }, running).moving, false);
    const tackle = arcadePlayerPose(actor, { status: 'finished', result: { reason: 'tackle' } });
    assert.equal(tackle.moving, false); assert.equal(tackle.jukeLean, 0); assert.equal(tackle.tackled, true);
    assert.equal(arcadePlayerPose(actor, { status: 'finished', result: { reason: 'touchdown' } }).tackled, false);
});

test('shared field retains Coach dimensions, endzone design, yard lines, hashes and artwork placement', () => {
    const scene = new THREE.Group(), boxes = [], drawn = [];
    const document = { createElement: () => ({ getContext: () => ({ fillText: (...args) => drawn.push(args) }) }) };
    const box = (...args) => { boxes.push(args); };
    const loaded = [];
    const textureFor = (url, callback) => {
        loaded.push(url); const texture = new THREE.Texture(); texture.image = { width: 800, height: 200 }; callback?.(texture); return texture;
    };
    buildFieldSurface(scene, { endzone_text: 'WEBSPORTS', field_25_logo: '25.png', midfield_logo: 'mid.png', endzone_logo_right: 'end.png' }, {}, document, textureFor, box, { load: textureFor });
    assert.deepEqual(boxes[0], [144, .3, 78, 0x18392a, 60, -.4, 26.7]);
    assert.deepEqual(boxes[1], [120, .15, 53.33, 0x285d38, 60, -.1, 26.665]);
    assert.equal(boxes.filter(box => box[0] === .13).length, 21);
    assert.equal(boxes.filter(box => box[0] === .1).length, 396);
    assert.deepEqual(loaded, ['25.png', 'mid.png', 'end.png']);
    assert.equal(scene.getObjectByName('field-25-logo-right').position.x, 85);
    assert.ok(drawn.some(([text]) => text === 'WEBSPORTS'));
    const rightEnd = scene.children.find(mesh => mesh.position.x === 115);
    assert.equal(rightEnd.rotation.z, -Math.PI / 2);
    assert.ok(Math.abs(rightEnd.scale.x - 28) < 1e-10);
    assert.ok(Math.abs(rightEnd.scale.y - 7) < 1e-10);
    disposeArcadeScene(scene);
});

test('resource disposal releases shared geometry materials and textures once', () => {
    const scene = new THREE.Group(), geometry = new THREE.BoxGeometry(), texture = new THREE.Texture();
    const material = new THREE.MeshBasicMaterial({ map: texture });
    const calls = { geometry: 0, material: 0, texture: 0 };
    geometry.addEventListener('dispose', () => calls.geometry++);
    material.addEventListener('dispose', () => calls.material++);
    texture.addEventListener('dispose', () => calls.texture++);
    scene.add(new THREE.Mesh(geometry, material), new THREE.Mesh(geometry, material));
    disposeArcadeScene(scene, [texture]);
    assert.deepEqual(calls, { geometry: 1, material: 1, texture: 1 });
});


test('changing from two defenders to one hides inactive meshes and restores them on return', () => {
    const models = new Map(['runner', 0, 1].map(key => [key, { model: new THREE.Group(), ring: new THREE.Group() }]));
    setArcadeDefenderVisibility(models, 2);
    for (const entry of models.values()) assert.equal(entry.model.visible && entry.ring.visible, true);
    setArcadeDefenderVisibility(models, 1);
    assert.equal(models.get(1).model.visible, false);
    assert.equal(models.get(1).ring.visible, false);
    assert.equal(models.get(0).model.visible, true);
    assert.equal(models.get('runner').ring.visible, true);
    const reusedModel = models.get(1).model;
    setArcadeDefenderVisibility(models, 2);
    assert.equal(models.get(1).model, reusedModel);
    assert.equal(models.get(1).model.visible, true);
    assert.equal(models.get(1).ring.visible, true);
});


test('fixed camera contains every playable corner including the retreat limit in both directions', () => {
    for (const [width, height] of [[1280, 720], [390, 540], [844, 390]]) {
        for (const direction of [1, -1]) {
            const state = createDrill({runner: {ratings: {}}, defenders: [{ratings: {}}], direction});
            const frame = arcadeCameraFrame(width, height, direction);
            const camera = new THREE.OrthographicCamera(frame.left, frame.right, frame.top, frame.bottom, .1, 300);
            camera.position.set(...frame.position); camera.lookAt(...frame.target); camera.updateMatrixWorld();
            for (const x of [state.rearX, state.goalX]) for (const z of [0, 53.33]) {
                const point = new THREE.Vector3(x, 0, z).project(camera);
                assert.ok(Math.abs(point.x) < 1 && Math.abs(point.y) < 1, `${width}x${height}: ${x},${z} remains visible`);
            }
        }
    }
});
