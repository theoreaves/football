import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { buildFootballPlayer, animateFootballPlayer } from '../practice/player-model.js';
import { buildFieldSurface } from '../practice/field-scene.js';
import { arcadeCameraFrame, exhibitionCameraX, disposeArcadeScene } from './view.js';

const CAMERAS = ['original', 'broadcast', 'follow', 'field', 'free'];
const clamp = (value, low, high) => Math.max(low, Math.min(high, value));

// This view consumes absolute recorded samples. It never advances a simulation or gait clock.
export function createReplayView(host, recording, {
    sharedHost = null,
    rendererFactory = options => new THREE.WebGLRenderer(options),
    controlsFactory = (camera, canvas) => new OrbitControls(camera, canvas),
} = {}) {
    const renderer = sharedHost?.renderer ?? rendererFactory({ antialias: true, alpha: false });
    const canvas = renderer.domElement, document = host.ownerDocument;
    const restore = sharedHost ? captureSharedPresentation(sharedHost) : null;
    const scene = sharedHost ? sharedHost.createLayer('arcade-replay') : new THREE.Scene(), sun = new THREE.DirectionalLight(0xffefd6, 3);
    const textures = new Map(), models = new Map();
    let disposed = false;
    try {
        if (!sharedHost) {
        renderer.setPixelRatio(Math.min(globalThis.devicePixelRatio || 1, 2));
        renderer.setClearColor(0x101f30);
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        canvas.style.cssText = 'display:block;width:100%;height:100%;touch-action:none';
        canvas.setAttribute('aria-label', 'Recorded play replay. Free camera supports rotating, panning and zooming.');
        host.appendChild(canvas);
        }
        const original = new THREE.OrthographicCamera(-24, 24, 19, -19, .1, 400);
        const perspective = new THREE.PerspectiveCamera(48, 1, .1, 500);
        let camera = original, cameraMode = 'original', lastSample = null, controls = null;
        const loader = new THREE.TextureLoader();
        const textureFor = sharedHost?.textureFor ?? ((url, callback) => {
            if (typeof url !== 'string' || !url || url.length > 2048) return null;
            const origin = document.location?.origin || globalThis.location?.origin;
            try {
                const resolved = new URL(url, origin || 'https://replay.invalid');
                if (!['http:', 'https:'].includes(resolved.protocol)
                    || resolved.origin !== (origin || 'https://replay.invalid') || resolved.username || resolved.password) return null;
            } catch { return null; }
            if (textures.has(url)) {
                const cached = textures.get(url);
                if (cached.image) callback?.(cached);
                else cached.userData.callbacks.push(callback);
                return cached;
            }
            const texture = loader.load(url, loaded => {
                if (disposed) { loaded.dispose(); return; }
                loaded.userData.callbacks.forEach(fn => fn?.(loaded));
                loaded.userData.callbacks = [];
                draw();
            });
            texture.colorSpace = THREE.SRGBColorSpace;
            texture.userData.callbacks = [callback];
            textures.set(url, texture);
            return texture;
        });
        const box = (width, height, depth, color, x, y, z) => {
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(width, height, depth),
                new THREE.MeshStandardMaterial({ color, roughness: .85 }));
            mesh.position.set(x, y, z); mesh.receiveShadow = true; scene.add(mesh); return mesh;
        };
        if (!sharedHost) scene.add(new THREE.HemisphereLight(0xd5e9ff, 0x305239, 2.5));
        sun.position.set(70, 75, 40); sun.target.position.set(60, 0, 26.665);
        sun.castShadow = true; sun.shadow.mapSize.set(2048, 2048);
        Object.assign(sun.shadow.camera, { left: -80, right: 80, top: 80, bottom: -80 });
        sun.shadow.normalBias = .03; if (!sharedHost) scene.add(sun, sun.target);
        const appearance = recording.appearance || {}, home = appearance.home || {}, away = appearance.away || {};
        if (!sharedHost) buildFieldSurface(scene, home, away, document, textureFor, box, { load: textureFor });
        const direction = recording.direction < 0 ? -1 : 1;
        box(.18, .035, 53.33, 0x4bd4f0, recording.field?.startX ?? (direction < 0 ? 40 : 80), .085, 26.665);
        box(.24, .04, 53.33, 0xffda6a, recording.field?.goalX ?? (direction < 0 ? 10 : 110), .09, 26.665);
        for (const actor of recording.actors) {
            const team = appearance[actor.side || (actor.key.startsWith('defender:') ? 'away' : 'home')] || {};
            const kit={...team.uniform,helmet_logo_left:team.helmet_logo_left,helmet_logo_right:team.helmet_logo_right};
            const model=sharedHost ? sharedHost.player(actor,kit) : buildFootballPlayer(actor,kit,document,textureFor);
            model.name = `replay-player-${actor.key}`;
            model.userData.carryingArm = 0;
            model.visible = false;
            (sharedHost?.scene ?? scene).add(model);
            models.set(actor.key, model);
        }
        const ball = sharedHost?.ball ?? new THREE.Mesh(new THREE.SphereGeometry(.25, 12, 8),
            new THREE.MeshStandardMaterial({ color: 0x9d5829, roughness: .9 }));
        ball.name = 'replay-football'; ball.scale.set(.85, 1.6, .85); ball.castShadow = true;
        ball.visible = false; (sharedHost?.scene ?? scene).add(ball);

        function draw() {
            if (disposed) return;
            camera.updateMatrixWorld();
            if (!sharedHost) renderer.render(scene, camera);
        }
        function focus() {
            const actors = lastSample?.actors || [];
            const holderKey = lastSample?.ball?.phase !== 'flight' ? lastSample?.ball?.holderKey : null;
            const actor = actors.find(actor => actor.key === holderKey)
                || actors.find(actor => actor.key === lastSample?.controlledKey) || actors[0];
            return new THREE.Vector3(actor?.x ?? (direction < 0 ? 24 : 96), 1.2, actor?.z ?? 26.665);
        }
        function frameCamera() {
            const width = Math.max(1, host.clientWidth), height = Math.max(1, host.clientHeight);
            const aspect = width / height;
            if (cameraMode === 'original') {
                const frame = arcadeCameraFrame(width, height, direction, [10,110].includes(recording.field?.rearX) ? exhibitionCameraX(focus().x, direction) : null);
                Object.assign(original, { left: frame.left, right: frame.right, top: frame.top, bottom: frame.bottom });
                original.position.set(...frame.position); original.lookAt(...frame.target);
            } else {
                perspective.aspect = aspect;
                if (cameraMode !== 'free') {
                    const target = focus();
                    // Narrow screens pull back rather than clipping the followed player.
                    const spread = Math.max(1, Math.min(2.8, 1 / aspect));
                    if (cameraMode === 'broadcast') {
                        target.y = 0;
                        perspective.position.set(target.x + 12 * direction * spread, 27 * spread, target.z + 40 * spread);
                    } else if (cameraMode === 'follow') {
                        perspective.position.set(target.x - 12 * direction * spread, 8 * spread, target.z + 5 * spread);
                    } else {
                        perspective.position.set(target.x - 8 * direction * spread, 2.5, target.z + 12 * spread);
                    }
                    perspective.lookAt(target);
                }
            }
            camera.updateProjectionMatrix();
        }
        function constrainFreeCamera() {
            if (disposed || !controls) return;
            const before = controls.target.clone();
            controls.target.set(clamp(before.x, -10, 130), clamp(before.y, 0, 12), clamp(before.z, -10, 63.33));
            camera.position.add(controls.target.clone().sub(before));
            camera.position.y = Math.max(.8, camera.position.y);
            draw();
        }
        function releaseControls() {
            if (!controls) return;
            controls.removeEventListener('change', constrainFreeCamera);
            controls.dispose(); controls = null;
        }
        function setCamera(mode) {
            if (disposed || !CAMERAS.includes(mode)) return false;
            if (cameraMode === mode) return true;
            releaseControls();
            cameraMode = mode; camera = mode === 'original' ? original : perspective;
            if (mode === 'free') {
                const target = focus();
                perspective.position.copy(target).add(new THREE.Vector3(-18 * direction, 22, 30));
                perspective.zoom = 1; perspective.lookAt(target);
                controls = controlsFactory(perspective, canvas);
                controls.target.copy(target);
                controls.enableDamping = false;
                controls.enablePan = true; controls.enableZoom = true; controls.enableRotate = true;
                controls.minDistance = 4; controls.maxDistance = 160;
                controls.minPolarAngle = .08; controls.maxPolarAngle = Math.PI / 2 - .015;
                controls.addEventListener('change', constrainFreeCamera);
                controls.update();
            }
            frameCamera(); if(sharedHost) sharedHost.cameraDirector.use(camera); draw(); return true;
        }
        function resetCamera() {
            if (disposed) return false;
            if (cameraMode === 'free') {
                releaseControls();
                cameraMode = 'original';
                return setCamera('free');
            }
            frameCamera(); draw(); return true;
        }
        function resize() {
            if (disposed) return;
            if (!sharedHost) renderer.setSize(Math.max(1, host.clientWidth), Math.max(1, host.clientHeight), false);
            frameCamera(); draw();
        }
        function render(sample) {
            if (disposed || !sample) return;
            lastSample = sample;
            sharedHost?.hidePlayers();
            models.forEach(model => { model.visible = false; });
            const held = sample.ball?.phase !== 'flight' && models.has(sample.ball?.holderKey);
            sample.actors.forEach((actor, index) => {
                const model = models.get(actor.key);
                if (!model) return;
                model.visible = true;
                model.position.set(actor.x, actor.pose === 'tackled' ? .5 : 0, actor.z);
                model.rotation.set(0, Math.PI / 2 - actor.facing, actor.pose === 'tackled' ? -1.15 : 0);
                model.userData.waist.rotation.set(0, 0, 0);
                for (const joint of [...(model.userData.arms || []), ...(model.userData.elbows || [])]) joint.rotation.set(0, 0, 0);
                model.userData.cradlingBall = Boolean(held && sample.ball.holderKey === actor.key);
                const throwEvent = sample.events?.findLast(event => event.type === 'throw');
                const throwAge = Math.max(0, sample.elapsed - (throwEvent?.time ?? sample.elapsed));
                animateFootballPlayer(model, actor.speed > .08 && actor.pose !== 'tackled', actor.gaitPhase, index,
                    actor.pose === 'throw' ? 2.2 + Math.min(1, throwAge / .3) * .7 : null);
                model.userData.waist.rotation.z = actor.pose === 'juke' ? actor.jukeLean : 0;
            });
            // Held positions in v1 are actor centers, not hand transforms. Reconstruct the
            // same single carried mesh; flight/dead positions remain the recorded world path.
            const holder = held ? models.get(sample.ball.holderKey) : null;
            if (holder) {
                holder.userData.waist.add(ball);
                ball.position.set(-.40, .45, .29); ball.rotation.set(0, 0, 0); ball.visible = true;
            } else {
                (sharedHost?.scene ?? scene).add(ball);
                ball.visible = Boolean(sample.ball);
                if (sample.ball) {
                    ball.position.set(sample.ball.x, sample.ball.y, sample.ball.z);
                    ball.rotation.set(0, sample.elapsed * 16, -Math.PI / 2);
                }
            }
            if(sharedHost)sharedHost.sideline?.update(new Set(recording.actors.filter(actor=>sample.actors.some(item=>item.key===actor.key)).map(actor=>`${actor.side}:${actor.id ?? actor.role}`)));
            frameCamera(); draw();
        }
        resize();
        if(sharedHost) sharedHost.cameraDirector.use(camera);
        const releaseResize=sharedHost?.subscribeResize(resize);
        return { render, setCamera, resetCamera, resize,
            metrics() { return { disposed, camera: cameraMode, actors: models.size,
                visibleActors: [...models.values()].filter(model => model.visible).length,
                geometries: renderer.info?.memory.geometries ?? 0, textures: renderer.info?.memory.textures ?? 0,
                drawCalls: renderer.info?.render.calls ?? 0 }; },
            dispose() {
                if (disposed) return;
                disposed = true; releaseControls(); releaseResize?.();
                if(sharedHost) { sharedHost.scene.add(ball); sharedHost.releaseLayer(scene); restore(); return; }
                disposeArcadeScene(scene, textures.values()); sun.shadow.dispose();
                renderer.dispose(); renderer.forceContextLoss(); canvas.remove();
                scene.clear(); models.clear(); textures.clear(); lastSample = null;
            },
        };
    } catch (error) {
        disposed = true;
        if(sharedHost) { sharedHost.scene.add(sharedHost.ball); sharedHost.releaseLayer(scene); restore(); throw error; }
        disposeArcadeScene(scene, textures.values()); sun.shadow.dispose();
        renderer.dispose(); renderer.forceContextLoss(); canvas.remove();
        scene.clear(); models.clear(); textures.clear();
        throw error;
    }
}

// Replay borrows models and the ball, restoring the exact presentation, not game state.
export function captureSharedPresentation(host) {
    const entries=[]; const camera=host.cameraDirector.camera,cameraSnapshot=camera.clone();
    host.scene.traverse(object=>entries.push({object,parent:object.parent,visible:object.visible,position:object.position.clone(),quaternion:object.quaternion.clone(),scale:object.scale.clone(),instanceMatrix:object.isInstancedMesh?object.instanceMatrix.array.slice():null}));
    for(const {object} of entries)if(['coach-play','arcade-live'].includes(object.name))object.visible=false;
    let restored=false;
    return ()=>{if(restored)return;restored=true;host.hidePlayers();for(const item of entries){const {object,parent}=item;if(parent && object.parent!==parent)parent.add(object);object.visible=item.visible;object.position.copy(item.position);object.quaternion.copy(item.quaternion);object.scale.copy(item.scale);if(item.instanceMatrix){object.instanceMatrix.array.set(item.instanceMatrix);object.instanceMatrix.needsUpdate=true;}}camera.copy(cameraSnapshot);camera.updateMatrixWorld();host.cameraDirector.use(camera);};
}
