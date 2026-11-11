import * as THREE from 'three';
import { buildFootballPlayer, animateFootballPlayer } from '../practice/player-model.js';
import { buildFieldSurface } from '../practice/field-scene.js';
import { getPassTrajectory } from './passing.js';

const ROUTE_COLORS = [0x6ee7ff, 0xb6fa7b, 0xe7adff];

export function arcadeCameraFrame(width, height, direction = 1) {
    const aspect = Math.max(1, width) / Math.max(1, height);
    const viewHeight = Math.max(38, 48 / aspect);
    return { left: -viewHeight * aspect / 2, right: viewHeight * aspect / 2,
        top: viewHeight / 2, bottom: -viewHeight / 2,
        target: [direction < 0 ? 24 : 96, 0, 26.665],
        position: [direction < 0 ? 24 : 96, 65, 116.665] };
}

// Convert CSS pointer coordinates, not device pixels, through the current camera.
export function arcadeGroundPoint(camera, rect, clientX, clientY) {
    if (!rect || !(rect.width > 0 && rect.height > 0) ||
        ![clientX, clientY, rect.left, rect.top].every(Number.isFinite)) return null;
    camera.updateMatrixWorld();
    const pointer = new THREE.Vector2((clientX - rect.left) / rect.width * 2 - 1,
        1 - (clientY - rect.top) / rect.height * 2);
    const raycaster = new THREE.Raycaster();
    raycaster.setFromCamera(pointer, camera);
    const hit = raycaster.ray.intersectPlane(new THREE.Plane(new THREE.Vector3(0, 1, 0), 0), new THREE.Vector3());
    return hit && Number.isFinite(hit.x) && Number.isFinite(hit.z) ? { x: hit.x, z: hit.z } : null;
}

export function arcadePlayerPose(actor, state) {
    const speed = Math.hypot(actor.vx || 0, actor.vz || 0);
    const tackled = state.status === 'finished' && ['tackle', 'sack'].includes(state.result?.reason);
    return { speed, moving: speed > .08 && !tackled,
        heading: Math.PI / 2 - (actor.facing ?? Math.atan2(actor.vz || 0, actor.vx || 1)),
        jukeLean: !tackled && actor.jukeRemaining > 0 ? Math.sign(actor.vz || 1) * .22 : 0,
        tackled };
}

export function setArcadeDefenderVisibility(models, defenderCount) {
    models.forEach(({ model, ring }, key) => {
        const index = typeof key === 'number' ? key : String(key).startsWith('defender:') ? Number(key.split(':')[1]) : -1;
        const visible = index < 0 || index < defenderCount;
        model.visible = visible;
        ring.visible = visible;
    });
}

export function arcadeActorEntries(state) {
    const offense = state.mode === 'passing' ? [
        ['qb', state.quarterback], ...(state.receivers || []).map((actor, index) => [`receiver:${index}`, actor]),
    ] : [['runner', state.runner]];
    return [...offense, ...(state.defenders || []).map((actor, index) => [`defender:${index}`, actor])]
        .filter(([, actor]) => actor);
}

// Reparent ONE ball rather than leaving a carried copy behind after the throw/catch.
export function attachArcadeBall(ball, scene, models, state) {
    const key = state.mode === 'passing' ? state.ball?.holderKey : 'runner';
    // Terminal plays keep holderKey so tackles, touchdowns and interceptions retain possession.
    const held = state.mode !== 'passing' || (state.ball?.phase !== 'flight' && key != null);
    const holder = held && models.get(key)?.model;
    models.forEach(({ model }, actorKey) => { model.userData.cradlingBall = Boolean(holder && actorKey === key); });
    if (holder) {
        const waist = holder.userData.waist || holder;
        if (ball.parent !== waist) waist.add(ball);
        ball.position.set(-.40, 1.28 - (holder.userData.waist ? .83 : 0), .29);
        ball.rotation.set(0, 0, 0);
        ball.visible = true;
    } else {
        if (ball.parent !== scene) scene.add(ball);
        const point = state.ball;
        ball.visible = Boolean(point && [point.x, point.y, point.z].every(Number.isFinite));
        if (ball.visible) {
            ball.position.set(point.x, Math.max(.22, point.y), point.z);
            ball.rotation.set(0, (state.elapsed || 0) * 16, -Math.PI / 2);
        }
    }
    return holder ? key : null;
}

export function arcadeRoutePoints(actor) {
    return (actor.route?.points || []).filter(point => Number.isFinite(point.x) && Number.isFinite(point.z))
        .map(point => ({ x: point.x, y: .14, z: point.z }));
}

function createPath(color, capacity = 65) {
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(new Float32Array(capacity * 3), 3).setUsage(THREE.DynamicDrawUsage));
    geometry.setAttribute('lineDistance', new THREE.BufferAttribute(new Float32Array(capacity), 1).setUsage(THREE.DynamicDrawUsage));
    geometry.setDrawRange(0, 0);
    const line = new THREE.Line(geometry, new THREE.LineDashedMaterial({ color, dashSize: .65, gapSize: .35, transparent: true, opacity: .95 }));
    line.frustumCulled = false;
    line.visible = false;
    return line;
}

// Buffers remain allocated during aim drags, route changes, pauses and retries.
function updatePath(line, points) {
    const positions = line.geometry.attributes.position, distances = line.geometry.attributes.lineDistance;
    const count = Math.min(points.length, positions.count);
    let distance = 0;
    for (let index = 0; index < count; index++) {
        const point = points[index];
        if (index) distance += Math.hypot(point.x - points[index - 1].x, point.y - points[index - 1].y, point.z - points[index - 1].z);
        positions.setXYZ(index, point.x, point.y, point.z);
        distances.setX(index, distance);
    }
    positions.needsUpdate = true;
    distances.needsUpdate = true;
    line.geometry.setDrawRange(0, count);
    line.visible = count > 1;
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

// The renderer factory permits lifecycle tests without replacing shared player art.
export function createArcadeView(host, { appearance = {}, mode = 'running', runner, quarterback, receivers = [], defenders = [] },
    { rendererFactory = options => new THREE.WebGLRenderer(options) } = {}) {
    const document = host.ownerDocument;
    const renderer = rendererFactory({ antialias: true, alpha: false });
    renderer.setPixelRatio(Math.min(globalThis.devicePixelRatio || 1, 2));
    renderer.setClearColor(0x101f30);
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    const canvas = renderer.domElement;
    canvas.style.cssText = 'display:block;width:100%;height:100%';
    canvas.setAttribute('aria-label', mode === 'passing' ? 'Arcade passing drill. Drag to aim a pass at a receiver.' : 'Arcade running drill. Advance right to the goal line.');
    host.appendChild(canvas);
    const scene = new THREE.Scene();
    const camera = new THREE.OrthographicCamera(-24, 24, 19, -19, .1, 300);
    let disposed = false, direction = 1, lastState = null, lastTick = -1, lastElapsed = 0, aim = null;
    let lastBallPhase = null, throwStartedAt = null, labelUnits = 1;
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
    const groundRing = (inner, outer, color) => {
        const ring = new THREE.Mesh(new THREE.RingGeometry(inner, outer, 40),
            new THREE.MeshBasicMaterial({ color, side: THREE.DoubleSide, depthWrite: false }));
        ring.rotation.x = -Math.PI / 2; scene.add(ring); return ring;
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
    const models = new Map(), routes = new Map();
    const addPlayer = (player, key) => {
        if (!player || models.has(key)) return;
        const offensive = !key.startsWith('defender:');
        const team = appearance[player.side || (offensive ? 'home' : 'away')] || {};
        const kit = { ...team.uniform, helmet_logo_left: team.helmet_logo_left, helmet_logo_right: team.helmet_logo_right };
        const model = buildFootballPlayer(player, kit, document, textureFor);
        model.name = `arcade-player-${key}`;
        model.userData.carryingArm = 0;
        model.visible = false;
        scene.add(model);
        const ring = groundRing(.85, 1.08, offensive ? 0xffda6a : 0xff867d);
        ring.name = `arcade-ring-${key}`;
        ring.visible = false;
        models.set(key, { model, ring, phase: 0 });
    };
    // Payloads include both rosters; construct only the current mode's offense.
    if (mode === 'passing') {
        addPlayer(quarterback, 'qb');
        receivers.forEach((player, index) => addPlayer(player, `receiver:${index}`));
    } else addPlayer(runner, 'runner');
    defenders.forEach((player, index) => addPlayer(player, `defender:${index}`));
    const ball = new THREE.Mesh(new THREE.SphereGeometry(.25, 12, 8), new THREE.MeshStandardMaterial({ color: 0x9d5829, roughness: .9 }));
    ball.name = 'arcade-football'; ball.scale.set(.85, 1.6, .85); ball.castShadow = true; ball.visible = false;
    scene.add(ball);
    const ballShadow = groundRing(0, .35, 0x07130b);
    ballShadow.material.transparent = true; ballShadow.material.opacity = .3; ballShadow.visible = false;
    const trajectory = createPath(0xffe28b);
    trajectory.name = 'arcade-pass-trajectory'; scene.add(trajectory);
    const landingRing = groundRing(.65, .88, 0xffe28b);
    landingRing.name = 'arcade-pass-target'; landingRing.visible = false;

    function routeFor(index) {
        if (routes.has(index)) return routes.get(index);
        const color = ROUTE_COLORS[index % ROUTE_COLORS.length];
        const line = createPath(color);
        line.name = `arcade-route-${index}`; scene.add(line);
        const endpoint = groundRing(.3, .47, color);
        endpoint.name = `arcade-route-end-${index}`;
        const labelCanvas = document.createElement('canvas');
        labelCanvas.width = 256; labelCanvas.height = 64;
        const texture = new THREE.CanvasTexture(labelCanvas); texture.colorSpace = THREE.SRGBColorSpace;
        const label = new THREE.Sprite(new THREE.SpriteMaterial({ map: texture, depthTest: false, depthWrite: false }));
        label.name = `arcade-route-label-${index}`; label.renderOrder = 10; scene.add(label);
        const entry = { line, endpoint, label, labelCanvas, text: '', signature: '' };
        routes.set(index, entry);
        return entry;
    }

    function updateRoutes(state) {
        routes.forEach(({ line, endpoint, label }) => { line.visible = false; endpoint.visible = false; label.visible = false; });
        if (state.mode !== 'passing') return;
        const showRoutes = state.ball?.phase === 'held' && state.ball?.holderKey === 'qb' && state.status !== 'finished';
        (state.receivers || []).forEach((actor, index) => {
            const entry = routeFor(index), points = arcadeRoutePoints(actor);
            const signature = points.map(point => `${point.x},${point.z}`).join(';');
            if (entry.signature !== signature) { updatePath(entry.line, points); entry.signature = signature; }
            entry.line.visible = showRoutes && points.length > 1;
            entry.endpoint.visible = entry.line.visible;
            if (points.length) {
                const end = points.at(-1); entry.endpoint.position.set(end.x, .15, end.z);
            }
            const text = `R${index + 1} ${(actor.route?.name || actor.route?.label || state.routeSet || '').toUpperCase()}`.trim();
            if (entry.text !== text) {
                const ctx = entry.labelCanvas.getContext('2d');
                ctx.clearRect(0, 0, 256, 64);
                ctx.fillStyle = '#091827e8'; ctx.fillRect(0, 0, 256, 64);
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.font = 'bold 32px sans-serif';
                ctx.fillStyle = `#${new THREE.Color(ROUTE_COLORS[index % ROUTE_COLORS.length]).getHexString()}`;
                ctx.fillText(text, 128, 34, 244);
                entry.label.material.map.needsUpdate = true; entry.text = text;
            }
            entry.label.position.set(actor.x, 3.25, actor.z);
            entry.label.scale.set(96 * labelUnits, 24 * labelUnits, 1);
            entry.label.visible = state.status !== 'finished';
        });
    }

    function updateAim(state) {
        const points = aim && state.mode === 'passing' ? getPassTrajectory(state, aim, { samples: 32 }) : [];
        updatePath(trajectory, points);
        landingRing.visible = trajectory.visible;
        if (points.length) { const end = points.at(-1); landingRing.position.set(end.x, .17, end.z); }
    }

    function resize() {
        if (disposed) return;
        const width = Math.max(1, host.clientWidth), height = Math.max(1, host.clientHeight);
        renderer.setSize(width, height, false);
        const frame = arcadeCameraFrame(width, height, direction);
        Object.assign(camera, { left: frame.left, right: frame.right, top: frame.top, bottom: frame.bottom });
        camera.position.set(...frame.position); camera.lookAt(...frame.target); camera.updateProjectionMatrix(); camera.updateMatrixWorld();
        labelUnits = (frame.top - frame.bottom) / height;
        routes.forEach(({ label }) => label.scale.set(96 * labelUnits, 24 * labelUnits, 1));
        if (lastState) renderer.render(scene, camera);
    }

    function render(state, options = {}) {
        if (disposed) return;
        if (Object.hasOwn(options, 'aim')) aim = options.aim;
        const nextDirection = state.direction ?? state.config?.direction ?? 1;
        if (direction !== nextDirection) { direction = nextDirection; resize(); }
        startLine.position.x = state.startX ?? (direction < 0 ? 40 : 80);
        goalLine.position.x = state.goalX ?? (direction < 0 ? 10 : 110);
        const delta = lastState && state.tick >= lastTick ? Math.min(.1, Math.max(0, state.elapsed - lastElapsed)) : 0;
        if (state.tick < lastTick || state.status === 'ready') {
            models.forEach(entry => { entry.phase = 0; });
            throwStartedAt = null; lastBallPhase = null;
        }
        if (Number.isFinite(state.ball?.flight?.startedAt)) throwStartedAt = state.ball.flight.startedAt;
        else if (state.ball?.phase === 'flight' && lastBallPhase !== 'flight') throwStartedAt = state.elapsed;
        const entries = arcadeActorEntries(state), activeKeys = new Set(entries.map(([key]) => key));
        entries.forEach(([key, actor]) => addPlayer(actor, key));
        models.forEach(({ model, ring }, key) => { model.visible = activeKeys.has(key); ring.visible = model.visible; });
        const holderKey = attachArcadeBall(ball, scene, models, state);
        const controlledKey = state.mode === 'passing' ? state.controlledKey : 'runner';
        entries.forEach(([key, actor], index) => {
            const entry = models.get(key), { model, ring } = entry, pose = arcadePlayerPose(actor, state);
            model.position.set(actor.x, 0, actor.z);
            model.rotation.set(0, pose.heading, 0);
            model.userData.waist.rotation.set(0, 0, 0);
            // The shared carry/throw poses touch these axes; reset before the next gait.
            model.userData.arms?.forEach(arm => { arm.rotation.y = 0; });
            model.userData.elbows?.forEach(elbow => { elbow.rotation.y = 0; elbow.rotation.z = 0; });
            if (state.status === 'running') entry.phase += delta * pose.speed / 7;
            const throwAge = throwStartedAt === null ? Infinity : state.elapsed - throwStartedAt;
            const throwing = key === 'qb' && throwAge >= 0 && throwAge < .3 ? 2.2 + throwAge / .3 * .7 : null;
            animateFootballPlayer(model, pose.moving, entry.phase, index, throwing);
            // Apply a lateral waist cut AFTER gait: a juke never triggers shared tackle poses.
            model.userData.waist.rotation.z = pose.jukeLean;
            if (pose.tackled && key === controlledKey) {
                model.rotation.z = -1.15; model.position.y = .5;
                animateFootballPlayer(model, false, entry.phase, index);
            }
            const receiverIndex = key.startsWith('receiver:') ? Number(key.split(':')[1]) : -1;
            ring.material.color.setHex(key === controlledKey ? 0xffda6a : receiverIndex >= 0 ? ROUTE_COLORS[receiverIndex % ROUTE_COLORS.length] : key === 'qb' ? 0x6ee7ff : 0xff867d);
            ring.scale.setScalar(key === controlledKey ? 1 : .85);
            ring.position.set(actor.x, .11, actor.z);
        });
        ballShadow.visible = !holderKey && ball.visible;
        if (ballShadow.visible) ballShadow.position.set(ball.position.x, .12, ball.position.z);
        updateRoutes(state); updateAim(state);
        lastTick = state.tick; lastElapsed = state.elapsed; lastState = state; lastBallPhase = state.ball?.phase;
        renderer.render(scene, camera);
    }
    resize();
    return { render, resize,
        groundPoint(clientX, clientY) { return disposed ? null : arcadeGroundPoint(camera, canvas.getBoundingClientRect(), clientX, clientY); },
        setAim(target, state = lastState) {
            if (disposed) return;
            aim = target && Number.isFinite(target.x) && Number.isFinite(target.z) ? { x: target.x, z: target.z } : null;
            if (state) render(state);
        },
        metrics() { return { geometries: renderer.info?.memory.geometries ?? 0, textures: renderer.info?.memory.textures ?? 0,
            drawCalls: renderer.info?.render.calls ?? 0, actors: models.size,
            visibleActors: [...models.values()].filter(({ model }) => model.visible).length, routes: routes.size, disposed }; },
        dispose() {
            if (disposed) return;
            disposed = true;
            disposeArcadeScene(scene, textures.values());
            sun.shadow.dispose(); renderer.dispose(); renderer.forceContextLoss(); canvas.remove();
            scene.clear(); textures.clear(); models.clear(); routes.clear(); lastState = null; aim = null;
        },
    };
}
