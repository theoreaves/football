import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { DURATION, samplePlay } from './timeline.js';

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
    const scene = new THREE.Scene();
    scene.fog = new THREE.Fog(0x101a2b, 160, 280);
    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 350);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.maxPolarAngle = Math.PI / 2.1;
    controls.minDistance = 16;
    controls.maxDistance = 180;
    const setCamera = mode => {
        camera.position.set(...(mode === 'overhead' ? [60, 112, 26.7] : [60, 65, 112]));
        controls.target.set(60, 0, 26.7);
        controls.update();
    };
    setCamera('broadcast');
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
    addBox(0.2, 0.04, 53.33, 0x379aff, 40, 0.08, 26.665);
    addBox(0.2, 0.04, 53.33, 0xffc441, 50, 0.08, 26.665);
    for (const z of [-10, 64]) {
        for (let tier = 0; tier < 4; tier++) addBox(132, 2, 3, 0x293649, 60, tier * 2, z + (z < 0 ? -tier * 3 : tier * 3));
    }
    const players = samplePlay('pass', 0).players.map(player => {
        const group = new THREE.Group();
        const kit = player.team === 'offense' ? home.uniform || {} : away.uniform || {};
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
        scene.add(group);
        return group;
    });
    const ball = new THREE.Mesh(new THREE.SphereGeometry(0.25, 12, 8), material(0x9d5829));
    ball.scale.set(1.6, 0.85, 0.85); ball.castShadow = true; scene.add(ball);
    let elapsed = 0, running = false, type = 'pass', speed = 1, lastTime = null, frameId;
    const renderState = () => {
        const frame = samplePlay(type, elapsed);
        const future = samplePlay(type, Math.min(DURATION, elapsed + 0.03));
        frame.players.forEach((player, i) => {
            const mesh = players[i];
            const next = future.players[i];
            const moving = Math.hypot(next.x - player.x, next.z - player.z) > 0.002;
            mesh.position.set(player.x, moving ? Math.sin(elapsed * 18 + i) * 0.06 : 0, player.z);
            if (moving) mesh.rotation.y = Math.atan2(next.x - player.x, next.z - player.z);
            mesh.children[2].rotation.x = moving ? Math.sin(elapsed * 16) * 0.5 : 0;
            mesh.children[3].rotation.x = -mesh.children[2].rotation.x;
        });
        ball.position.set(frame.ball.x, frame.ball.y, frame.ball.z);
        ball.rotation.z = elapsed * 6;
        const message = `${type === 'pass' ? 'Slant pass' : 'Inside run'} · ${frame.event}`;
        if (status.textContent !== message) status.textContent = message;
        slider.value = elapsed;
        root.querySelector('[data-time]').textContent = `${elapsed.toFixed(1)} / ${DURATION.toFixed(1)}s`;
    };
    const resize = () => {
        const width = host.clientWidth, height = Math.max(400, host.clientHeight);
        renderer.setSize(width, height, false);
        camera.aspect = width / height; camera.updateProjectionMatrix();
    };
    const observer = new ResizeObserver(resize); observer.observe(host); resize();
    playButton.addEventListener('click', () => {
        if (elapsed >= DURATION) elapsed = 0;
        running = !running; playButton.textContent = running ? 'Pause' : 'Play';
    });
    root.querySelector('[data-reset]').addEventListener('click', () => { elapsed = 0; running = false; playButton.textContent = 'Play'; });
    root.querySelector('[data-play-type]').addEventListener('change', event => { type = event.target.value; elapsed = 0; running = false; playButton.textContent = 'Play'; });
    root.querySelector('[data-speed]').addEventListener('change', event => speed = Number(event.target.value));
    root.querySelector('[data-reset-camera]').addEventListener('click', () => setCamera(root.querySelector('[data-camera]').value));
    root.querySelector('[data-camera]').addEventListener('change', event => setCamera(event.target.value));
    slider.addEventListener('input', () => { elapsed = Number(slider.value); running = false; playButton.textContent = 'Play'; });
    const animate = now => {
        if (lastTime !== null && running && !document.hidden) elapsed = Math.min(DURATION, elapsed + Math.min((now - lastTime) / 1000, 0.1) * speed);
        lastTime = now;
        if (elapsed >= DURATION) { running = false; playButton.textContent = 'Replay'; }
        renderState(); controls.update(); renderer.render(scene, camera);
        frameId = requestAnimationFrame(animate);
    };
    frameId = requestAnimationFrame(animate);
    let disposed = false;
    const cleanup = () => {
        if (disposed) return;
        disposed = true;
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
