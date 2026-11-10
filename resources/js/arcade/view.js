import * as THREE from 'three';
import { buildFootballPlayer, animateFootballPlayer } from '../practice/player-model.js';
import { buildFieldSurface } from '../practice/field-scene.js';

export function arcadeCameraFrame(width, height, direction = 1) {
    const aspect = Math.max(1, width) / Math.max(1, height);
    const viewHeight = Math.max(38, 48 / aspect);
    return { left: -viewHeight * aspect / 2, right: viewHeight * aspect / 2,
        top: viewHeight / 2, bottom: -viewHeight / 2,
        target: [direction < 0 ? 24 : 96, 0, 26.665],
        position: [direction < 0 ? 24 : 96, 65, 116.665] };
}

export function arcadePlayerPose(actor, state) {
    const speed = Math.hypot(actor.vx || 0, actor.vz || 0);
    const tackled = state.status === 'finished' && state.result?.reason === 'tackle';
    return { speed, moving: speed > .08 && !tackled,
        heading: Math.PI / 2 - (actor.facing ?? Math.atan2(actor.vz || 0, actor.vx || 1)),
        jukeLean: !tackled && actor.jukeRemaining > 0 ? Math.sign(actor.vz || 1) * .22 : 0,
        tackled };
}

export function setArcadeDefenderVisibility(models, defenderCount) {
    models.forEach(({ model, ring }, key) => {
        const visible = key === 'runner' || key < defenderCount;
        model.visible = visible;
        ring.visible = visible;
    });
}

export function disposeArcadeScene(scene, extraTextures = []) {
    const geometries = new Set(), materials = new Set(), textures = new Set(extraTextures);
    scene.traverse(object => {
        if (object.geometry) geometries.add(object.geometry);
        for (const material of (Array.isArray(object.material) ? object.material : [object.material])) {
            if (!material) continue;
            materials.add(material);
            for (const value of Object.values(material)) if (value?.isTexture) textures.add(value);
        }
    });
    geometries.forEach(geometry => geometry.dispose());
    materials.forEach(material => material.dispose());
    textures.forEach(texture => texture.dispose());
}

export function createArcadeView(host, { appearance = {}, runner, defenders = [] }) {
    const document = host.ownerDocument;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
    renderer.setPixelRatio(Math.min(globalThis.devicePixelRatio || 1, 2));
    renderer.setClearColor(0x101f30);
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    const canvas = renderer.domElement;
    canvas.style.cssText = 'display:block;width:100%;height:100%';
    canvas.setAttribute('aria-label', 'Arcade running drill. Advance right to the goal line.');
    host.appendChild(canvas);
    const scene = new THREE.Scene();
    const camera = new THREE.OrthographicCamera(-24, 24, 19, -19, .1, 300);
    let disposed = false, direction = 1, lastState = null, lastTick = -1, lastElapsed = 0;
    const textures = new Map(), loader = new THREE.TextureLoader();
    const textureFor = (url, onLoad) => {
        if (!url) return null;
        if (textures.has(url)) {
            const texture = textures.get(url);
            if (texture.image) onLoad?.(texture);
            else texture.userData.callbacks.push(onLoad);
            return texture;
        }
        const texture = loader.load(url, loaded => {
            if (disposed) { loaded.dispose(); return; }
            loaded.userData.callbacks.forEach(callback => callback?.(loaded));
            loaded.userData.callbacks = [];
        });
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.userData.callbacks = [onLoad];
        textures.set(url, texture);
        return texture;
    };
    const box = (width, height, depth, color, x, y, z) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(width, height, depth),
            new THREE.MeshStandardMaterial({ color, roughness: .85 }));
        mesh.position.set(x, y, z); mesh.receiveShadow = true; scene.add(mesh); return mesh;
    };
    scene.add(new THREE.HemisphereLight(0xd5e9ff, 0x305239, 2.5));
    const sun = new THREE.DirectionalLight(0xffefd6, 3);
    sun.position.set(70, 75, 40); sun.target.position.set(96, 0, 26.665);
    sun.castShadow = true; sun.shadow.mapSize.set(2048, 2048);
    Object.assign(sun.shadow.camera, { left: -65, right: 65, top: 65, bottom: -65 });
    sun.shadow.normalBias = .03; scene.add(sun, sun.target);
    const home = appearance.home || {}, away = appearance.away || {};
    buildFieldSurface(scene, home, away, document, textureFor, box, { load: textureFor });
    // A low stadium edge frames the drill without hiding the playable sideline.
    for (const z of [-5, 58.33]) {
        box(144, 1.5, 1, home.stadium_wall_color || 0x263d52, 60, .5, z);
        for (let row = 0; row < 3; row++) box(144, .55, 1.8,
            home.stadium_seat_color || home.primary_color || 0x355b79,
            60, .65 + row * .65, z + Math.sign(z - 26) * (2 + row * 1.8));
    }
    const startLine = box(.18, .035, 53.33, 0x4bd4f0, 80, .085, 26.665);
    const goalLine = box(.24, .04, 53.33, 0xffda6a, 110, .09, 26.665);
    for (const x of [0, 120]) {
        box(.18, 3.5, .18, 0xffcc33, x, 1.75, 26.7);
        box(.18, .18, 6.2, 0xffcc33, x, 3.5, 26.7);
        for (const z of [23.6, 29.8]) box(.18, 5, .18, 0xffcc33, x, 6, z);
    }
    const models = new Map();
    const addPlayer = (player, isRunner, index) => {
        const team = appearance[player.side || (isRunner ? 'home' : 'away')] || {};
        const kit = { ...team.uniform, helmet_logo_left: team.helmet_logo_left, helmet_logo_right: team.helmet_logo_right };
        const model = buildFootballPlayer(player, kit, document, textureFor);
        model.userData.cradlingBall = isRunner;
        model.userData.carryingArm = 0;
        scene.add(model);
        const ring = new THREE.Mesh(new THREE.RingGeometry(.85, isRunner ? 1.08 : .98, 40),
            new THREE.MeshBasicMaterial({ color: isRunner ? 0xffda6a : 0xff867d, side: THREE.DoubleSide, depthWrite: false }));
        ring.rotation.x = -Math.PI / 2; scene.add(ring);
        models.set(isRunner ? 'runner' : index, { model, ring, phase: 0 });
        if (isRunner) {
            const ball = new THREE.Mesh(new THREE.SphereGeometry(.25, 12, 8), new THREE.MeshStandardMaterial({ color: 0x9d5829, roughness: .9 }));
            ball.name = 'arcade-carried-football'; ball.scale.set(.85, 1.6, .85);
            ball.position.set(-.40, 1.28 - .83, .29); ball.castShadow = true;
            model.userData.waist.add(ball);
        }
    };
    addPlayer(runner, true, 0);
    defenders.forEach((player, index) => addPlayer(player, false, index));
    function resize() {
        if (disposed) return;
        const width = Math.max(1, host.clientWidth), height = Math.max(1, host.clientHeight);
        renderer.setSize(width, height, false);
        const frame = arcadeCameraFrame(width, height, direction);
        Object.assign(camera, { left: frame.left, right: frame.right, top: frame.top, bottom: frame.bottom });
        camera.position.set(...frame.position); camera.lookAt(...frame.target); camera.updateProjectionMatrix();
        if (lastState) renderer.render(scene, camera);
    }
    function render(state) {
        if (disposed) return;
        const nextDirection = state.direction ?? state.config?.direction ?? 1;
        if (direction !== nextDirection) { direction = nextDirection; resize(); }
        startLine.position.x = direction < 0 ? 40 : 80;
        goalLine.position.x = direction < 0 ? 10 : 110;
        const delta = lastState && state.tick >= lastTick ? Math.min(.1, Math.max(0, (state.elapsed - lastElapsed))) : 0;
        if (state.tick < lastTick || state.status === 'ready') models.forEach(entry => { entry.phase = 0; });
        const update = (actor, key, index) => {
            const entry = models.get(key);
            if (!entry) return;
            const { model, ring } = entry, pose = arcadePlayerPose(actor, state);
            model.position.set(actor.x, 0, actor.z);
            model.rotation.set(0, pose.heading, 0);
            model.userData.waist.rotation.set(0, 0, 0);
            if (state.status === 'running') entry.phase += delta * pose.speed / 7;
            animateFootballPlayer(model, pose.moving, entry.phase, index);
            // Apply a lateral waist cut AFTER gait: a juke must never trigger the shared tackle pose.
            model.userData.waist.rotation.z = pose.jukeLean;
            if (pose.tackled && key === 'runner') {
                model.rotation.z = -1.15; model.position.y = .5;
                animateFootballPlayer(model, false, entry.phase, index);
            }
            ring.position.set(actor.x, .11, actor.z);
        };
        setArcadeDefenderVisibility(models, state.defenders.length);
        update(state.runner, 'runner', 0);
        state.defenders.forEach((actor, index) => update(actor, index, index + 1));
        lastTick = state.tick; lastElapsed = state.elapsed; lastState = state;
        renderer.render(scene, camera);
    }
    resize();
    return { render, resize, dispose() {
        if (disposed) return;
        disposed = true;
        disposeArcadeScene(scene, textures.values());
        sun.shadow.dispose(); renderer.dispose(); renderer.forceContextLoss(); canvas.remove();
        textures.clear(); models.clear(); lastState = null;
    } };
}
