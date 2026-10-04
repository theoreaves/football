import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { DURATION, samplePlay } from './timeline.js';
import { sampleEnginePlay } from './engine-timeline.js';
import { captureCamera, restoreCamera } from './camera-state.js';
import { sampleHuddle, sampleBreakHuddle } from './huddle.js';

export function mountPractice(root) {
    if (root.dataset.mounted) return;
    root.dataset.mounted = 'true';
    const host = root.querySelector('[data-field]');
    const status = root.querySelector('[data-status]');
    const slider = root.querySelector('[data-timeline]');
    const playButton = root.querySelector('[data-play]');
    let renderer;
    try {
        renderer = new THREE.WebGLRenderer({ antialias: true });
    } catch {
        status.textContent = 'The 3D field needs WebGL. Try hardware acceleration or another browser.';
        root.querySelectorAll('button, input, select').forEach(element => element.disabled = true);
        return;
    }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setClearColor(0x101a2b);
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    renderer.domElement.style.width = '100%';
    renderer.domElement.style.height = '100%';
    renderer.domElement.style.display = 'block';
    host.appendChild(renderer.domElement);
    renderer.domElement.setAttribute('aria-label', 'Three-dimensional practice football field');
    const appearance = JSON.parse(root.dataset.appearance || '{}');
    const home = appearance.home || {}, away = appearance.away || {};
    const animation = root.dataset.animation ? JSON.parse(root.dataset.animation) : null;
    const sample = (type, time) => animation ? sampleEnginePlay(animation, time) : samplePlay(type, time);
    const duration = animation?.duration || DURATION;
    const offenseSide = animation?.possession || 'home';
    const scene = new THREE.Scene();
    scene.fog = new THREE.Fog(0x101a2b, 160, 280);
    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 350);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.maxPolarAngle = Math.PI / 2.1;
    controls.minDistance = 16;
    controls.maxDistance = 180;
    const focus = [animation?.line ?? 60, 0, 26.7];
    const cameraKey = root.dataset.cameraKey || 'football-practice-camera';
    const cameraSelect = root.querySelector('[data-camera]');
    let cameraMode = 'broadcast';
    const saveCamera = () => {
        try { localStorage.setItem(cameraKey, JSON.stringify(captureCamera(cameraMode, camera.position.toArray(), controls.target.toArray(), focus))); } catch { /* Storage may be unavailable. */ }
    };
    const setCamera = mode => {
        cameraMode = mode;
        cameraSelect.value = mode;
        controls.target.set(...focus);
        camera.position.set(focus[0], mode === 'overhead' ? 85 : 48, focus[2] + (mode === 'overhead' ? .01 : 65));
        controls.update();
        saveCamera();
    };
    let savedCamera;
    try { savedCamera = restoreCamera(JSON.parse(localStorage.getItem(cameraKey)), focus); } catch { /* Use the default view. */ }
    if (savedCamera) {
        cameraMode = savedCamera.mode; cameraSelect.value = cameraMode;
        controls.target.set(...savedCamera.target); camera.position.set(...savedCamera.position); controls.update();
    } else setCamera('broadcast');
    controls.addEventListener('end', saveCamera);
    scene.add(new THREE.HemisphereLight(0xbad7ff, 0x314b27, 2.2));
    const light = new THREE.DirectionalLight(0xfff5dc, 3);
    light.position.set(45, 70, 15);
    light.castShadow = true;
    Object.assign(light.shadow.camera, { left: -90, right: 90, top: 80, bottom: -80 });
    light.shadow.mapSize.set(2048, 2048);
    scene.add(light);
    const material = color => new THREE.MeshStandardMaterial({ color, roughness: 0.8 });
    const addBox = (width, height, depth, color, x, y, z) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(width, height, depth), material(color));
        mesh.position.set(x, y, z);
        mesh.receiveShadow = true;
        scene.add(mesh);
        return mesh;
    };
    addBox(144, 0.3, 78, 0x18392a, 60, -0.4, 26.7);
    addBox(120, 0.15, 53.33, 0x285d38, 60, -0.1, 26.665);
    for (let x = 10; x < 110; x += 10) addBox(10, 0.02, 53.33, x % 20 ? 0x31723f : 0x296638, x + 5, 0, 26.665);
    addBox(10, 0.03, 53.33, home.endzone_background || 0x174880, 5, 0.02, 26.665);
    addBox(10, 0.03, 53.33, home.endzone_background || 0x7a252c, 115, 0.02, 26.665);
    for (const x of [5, 115]) {
        const canvas = document.createElement('canvas');
        canvas.width = 1024; canvas.height = 160;
        const context = canvas.getContext('2d');
        context.fillStyle = home.endzone_text_color || '#ffffff';
        context.textAlign = 'center'; context.textBaseline = 'middle';
        context.font = 'bold 100px sans-serif';
        context.fillText(home.endzone_text || home.name || 'FOOTBALL', 512, 80, 960);
        const sign = new THREE.Mesh(new THREE.PlaneGeometry(44, 7), new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(canvas), transparent: true, depthWrite: false }));
        sign.rotation.set(-Math.PI / 2, 0, x === 5 ? Math.PI / 2 : -Math.PI / 2);
        sign.position.set(x, 0.08, 26.665); scene.add(sign);
    }
    for (const z of [0, 53.33]) addBox(120, 0.03, 0.18, 0xf3f1d9, 60, 0.05, z);
    for (let x = 10; x <= 110; x += 5) {
        addBox(0.13, 0.03, 53.33, 0xf3f1d9, x, 0.05, 26.665);
        if (x % 10 === 0 && x > 10 && x < 110) {
            const canvas = document.createElement('canvas');
            canvas.width = 128; canvas.height = 128;
            const context = canvas.getContext('2d');
            context.fillStyle = '#ffffff'; context.textAlign = 'center'; context.font = 'bold 78px sans-serif';
            context.fillText(String(Math.min(x - 10, 110 - x)), 64, 92);
            const number = new THREE.Mesh(new THREE.PlaneGeometry(3, 3), new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(canvas), transparent: true, depthWrite: false }));
            number.rotation.x = -Math.PI / 2; number.position.set(x, 0.08, 6); scene.add(number);
            const opposite = number.clone(); opposite.position.z = 47; scene.add(opposite);
        }
    }
    for (let x = 11; x < 110; x++) for (const z of [0.6, 23.6, 29.7, 52.7]) addBox(0.1, 0.03, 0.55, 0xddddcb, x, 0.06, z);
    const scrimmageLine = addBox(0.2, 0.04, 53.33, 0x379aff, animation?.line || 40, 0.08, 26.665);
    const firstDownLine = addBox(0.2, 0.04, 53.33, 0xffc441, animation?.firstDown || 50, 0.08, 26.665);
    for (const z of [-10, 64]) {
        for (let tier = 0; tier < 4; tier++) addBox(132, 2, 3, 0x293649, 60, tier * 2, z + (z < 0 ? -tier * 3 : tier * 3));
    }
    for (const x of [0, 120]) {
        addBox(.18, 3.5, .18, 0xffcc33, x, 1.75, 26.7);
        addBox(.18, .18, 6.2, 0xffcc33, x, 3.5, 26.7);
        for (const z of [23.6, 29.8]) addBox(.18, 5, .18, 0xffcc33, x, 6, z);
    }
    const players = sample('pass', 0).players.map(player => {
        const group = new THREE.Group();
        const side = player.team === 'offense' ? offenseSide : (offenseSide === 'home' ? 'away' : 'home');
        const kit = (side === 'home' ? home : away).uniform || {};
        const body = new THREE.Mesh(new THREE.CapsuleGeometry(0.42, 0.65, 4, 8), material(kit.shirt || (player.team === 'offense' ? 0x3997ff : 0xea535b)));
        body.position.y = 1.2; body.castShadow = true; group.add(body);
        const helmet = new THREE.Mesh(new THREE.SphereGeometry(0.35, 12, 8), material(kit.helmet || (player.team === 'offense' ? 0xe9f2ff : 0xdddddd)));
        helmet.position.y = 2.05; helmet.castShadow = true; group.add(helmet);
        for (const offset of [-0.29, 0.29]) {
            const leg = new THREE.Mesh(new THREE.BoxGeometry(0.22, 0.6, 0.22), material(kit.pants || 0xe2e8f0));
            leg.position.set(offset, 0.5, 0); group.add(leg);
            const sock = new THREE.Mesh(new THREE.BoxGeometry(0.22, 0.25, 0.22), material(kit.socks || '#ffffff'));
            sock.position.y = -0.3; leg.add(sock);
        }
        if (player.number != null) {
            const canvas = document.createElement('canvas'); canvas.width = 64; canvas.height = 64;
            const ctx = canvas.getContext('2d'); ctx.textAlign = 'center'; ctx.font = 'bold 48px sans-serif';
            ctx.fillStyle = '#ffffff'; ctx.strokeStyle = '#111111'; ctx.lineWidth = 5;
            ctx.strokeText(String(player.number), 32, 50); ctx.fillText(String(player.number), 32, 50);
            const number = new THREE.Mesh(new THREE.PlaneGeometry(.5, .5), new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(canvas), transparent: true }));
            number.position.set(0, 1.25, .43); group.add(number);
        }
        scene.add(group);
        return group;
    });
    const ball = new THREE.Mesh(new THREE.SphereGeometry(0.25, 12, 8), material(0x9d5829));
    ball.scale.set(1.6, 0.85, 0.85); ball.castShadow = true; scene.add(ball);
    const resultPopup = root.querySelector('[data-result-popup]');
    const nextLine = Number(root.dataset.nextLine || animation?.line || 60);
    let phase = resultPopup && root.dataset.autoplay !== 'true' ? 'huddle' : (root.hasAttribute('data-exhibition') && root.dataset.autoplay === 'true' ? 'liningup' : 'play');
    const lineupDuration = 3;
    let lineupProgress = 0;
    const setDuration = 4;
    let setElapsed = 0;
    let postElapsed = 0, huddleProgress = phase === 'huddle' ? 1 : 0;
    let elapsed = phase === 'huddle' ? duration : 0, running = root.dataset.autoplay === 'true', type = 'pass', speed = 1, lastTime = null, frameId;
    const renderState = () => {
        const finalFrame = phase === 'huddle' ? sample(type, duration) : null;
        let frame = finalFrame ? sampleHuddle(finalFrame, nextLine, root.dataset.nextPossession, huddleProgress) : sample(type, elapsed);
        let future = finalFrame ? sampleHuddle(finalFrame, nextLine, root.dataset.nextPossession, Math.min(1, huddleProgress + .02)) : sample(type, Math.min(duration, elapsed + 0.03));
        if (phase === 'liningup') {
            const formation = sample(type, 0);
            frame = sampleBreakHuddle(formation, animation.line, animation.possession, lineupProgress);
            future = sampleBreakHuddle(formation, animation.line, animation.possession, Math.min(1, lineupProgress + .02));
        }
        if (phase === 'set') {
            frame = { ...sample(type, 0), event: `Set · Snap in ${Math.ceil(setDuration - setElapsed)}s` };
            future = frame;
        }
        const motionTime = phase === 'liningup' ? lineupProgress * lineupDuration : phase === 'huddle' ? duration + huddleProgress * 1.5 : elapsed;
        frame.players.forEach((player, i) => {
            const mesh = players[i];
            const next = future.players[i];
            const moving = Math.hypot(next.x - player.x, next.z - player.z) > 0.002;
            mesh.position.set(player.x, moving ? Math.sin(motionTime * 18 + i) * 0.06 : 0, player.z);
            if (frame.huddle) mesh.rotation.y = Math.atan2(player.facingX - player.x, player.facingZ - player.z);
            else if (moving) mesh.rotation.y = Math.atan2(next.x - player.x, next.z - player.z);
            mesh.children[2].rotation.x = moving ? Math.sin(motionTime * 16) * 0.5 : 0;
            mesh.children[3].rotation.x = -mesh.children[2].rotation.x;
        });
        ball.position.set(frame.ball.x, frame.ball.y, frame.ball.z);
        ball.rotation.z = elapsed * 6;
        const message = animation ? frame.event : `${type === 'pass' ? 'Slant pass' : 'Inside run'} · ${frame.event}`;
        if (status.textContent !== message) status.textContent = message;
        slider.value = elapsed;
        root.querySelector('[data-time]').textContent = phase === 'liningup' ? `Forming up · ${(lineupProgress * lineupDuration).toFixed(1)} / ${lineupDuration.toFixed(1)}s` : phase === 'set' ? `Ready · ${(setDuration - setElapsed).toFixed(1)}s` : `${elapsed.toFixed(1)} / ${duration.toFixed(1)}s`;
    };
    const resize = () => {
        const width = host.clientWidth, height = Math.max(400, host.clientHeight);
        renderer.setSize(width, height, false);
        camera.aspect = width / height; camera.updateProjectionMatrix();
    };
    const observer = new ResizeObserver(resize); observer.observe(host); resize();
    const moveFocus = line => {
        const delta = line - focus[0]; focus[0] = line;
        camera.position.x += delta; controls.target.x += delta; controls.update(); saveCamera();
    };
    const replayView = () => {
        phase = 'play'; lineupProgress = 0; setElapsed = 0; postElapsed = 0; huddleProgress = 0;
        if (resultPopup) resultPopup.hidden = true;
        moveFocus(animation?.line ?? 60);
        scrimmageLine.position.x = animation?.line ?? 40;
        firstDownLine.position.x = animation?.firstDown ?? 50;
    };
    const huddleView = () => {
        moveFocus(nextLine);
        scrimmageLine.position.x = nextLine;
        firstDownLine.position.x = Math.max(10, Math.min(110, nextLine + (root.dataset.nextPossession === 'home' ? 1 : -1) * Number(root.dataset.nextDistance || 10)));
    };
    if (phase === 'huddle') huddleView();
    playButton.addEventListener('click', () => {
        if (phase !== 'play' && phase !== 'liningup' && phase !== 'set') replayView();
        if (elapsed >= duration) elapsed = 0;
        running = !running; playButton.textContent = running ? 'Pause' : 'Play';
    });
    root.querySelector('[data-reset]').addEventListener('click', () => { replayView(); elapsed = 0; running = false; playButton.textContent = 'Play'; });
    root.querySelector('[data-play-type]')?.addEventListener('change', event => { type = event.target.value; elapsed = 0; running = false; playButton.textContent = 'Play'; });
    root.querySelector('[data-speed]').addEventListener('change', event => speed = Number(event.target.value));
    root.querySelector('[data-reset-camera]').addEventListener('click', () => setCamera(root.querySelector('[data-camera]').value));
    root.querySelector('[data-camera]').addEventListener('change', event => setCamera(event.target.value));
    slider.addEventListener('input', () => { replayView(); elapsed = Number(slider.value); running = false; playButton.textContent = 'Play'; });
    if (running) playButton.textContent = 'Pause';
    const callForm = root.querySelector('[data-call-form]');
    callForm?.addEventListener('submit', () => {
        saveCamera();
        const button = callForm.querySelector('[data-snap]'); button.disabled = true; button.textContent = 'Simulating…';
    });
    const quarterDialog = root.querySelector('[data-quarter-dialog]');
    const quarterKey = `${cameraKey}:quarter:${root.dataset.playNumber}`;
    let quarterShown = false;
    try { quarterShown = sessionStorage.getItem(quarterKey) === 'shown'; } catch { /* Show it once per page. */ }
    const showQuarter = () => {
        if (!quarterDialog || quarterShown) return;
        quarterShown = true;
        quarterDialog.showModal();
        quarterDialog.querySelector('button')?.focus();
        try { sessionStorage.setItem(quarterKey, 'shown'); } catch { /* Optional persistence. */ }
    };
    const snapButton = callForm?.querySelector('[data-snap]');
    if (quarterDialog && !quarterShown && snapButton) snapButton.disabled = true;
    quarterDialog?.addEventListener('close', () => { playButton.textContent = 'Replay'; if (snapButton) snapButton.disabled = false; });
    if (quarterDialog && root.dataset.autoplay !== 'true') showQuarter();
    const animate = now => {
        const delta = lastTime === null || document.hidden ? 0 : (now - lastTime) / 1000;
        if (running && phase === 'liningup') {
            lineupProgress = Math.min(1, lineupProgress + Math.min(delta, .1) * speed / lineupDuration);
            if (lineupProgress === 1) { phase = 'set'; setElapsed = 0; elapsed = 0; }
        } else if (running && phase === 'set') {
            setElapsed = Math.min(setDuration, setElapsed + delta);
            if (setElapsed === setDuration) { phase = 'play'; elapsed = 0; }
        } else if (running) elapsed = Math.min(duration, elapsed + Math.min(delta, .1) * speed);
        lastTime = now;
        if (elapsed >= duration && phase === 'play') {
            running = false; playButton.textContent = 'Replay';
            if (resultPopup) { phase = 'result'; postElapsed = 0; resultPopup.hidden = false; }
            else showQuarter();
        } else if (phase === 'result') {
            postElapsed += delta;
            if (postElapsed >= 3) {
                resultPopup.hidden = true; phase = 'huddle'; postElapsed = 0; huddleView();
            }
        } else if (phase === 'huddle' && huddleProgress < 1) {
            postElapsed += delta; huddleProgress = Math.min(1, postElapsed / 1.5);
            if (huddleProgress === 1) showQuarter();
        }
        renderState(); controls.update(); renderer.render(scene, camera);
        frameId = requestAnimationFrame(animate);
    };
    frameId = requestAnimationFrame(animate);
    let disposed = false;
    const cleanup = () => {
        if (disposed) return;
        disposed = true;
        saveCamera();
        controls.removeEventListener('end', saveCamera);
        document.removeEventListener('livewire:navigating', cleanup);
        window.removeEventListener('pagehide', cleanup);
        cancelAnimationFrame(frameId); observer.disconnect(); controls.dispose();
        scene.traverse(object => {
            if (object.geometry) object.geometry.dispose();
            if (object.material) { object.material.map?.dispose(); object.material.dispose(); }
        });
        renderer.dispose(); renderer.domElement.remove(); delete root.dataset.mounted;
    };
    document.addEventListener('livewire:navigating', cleanup, { once: true });
    window.addEventListener('pagehide', cleanup, { once: true });
}
