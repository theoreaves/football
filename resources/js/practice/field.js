import { showSimProgress } from '../sim-progress.js';
import { gameNavigation } from './game-navigation.js';
import { turnoverMoment, crossedTurnover } from './live-announcement.js';
import { buildStadium } from './stadium.js';
import { samplePreSnapMotion } from './motion.js';
import { mountPlayWizard } from './play-wizard.js';
import { mobileControls } from './mobile-controls.js';
import { stadiumAudio } from './stadium-audio.js';
import { soundCues, crossedCues } from './sound-cues.js';
import { fitLogo } from './logo-fit.js';
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { DURATION, samplePlay } from './timeline.js';
import { sampleEnginePlay } from './engine-timeline.js';
import { captureCamera, restoreCamera, cameraPreset, translateCameraAnchor } from './camera-state.js';
import { canAdvanceCpu } from './cpu-flow.js';
import { ballCarrier, carrierLabel } from './ball-carrier.js';
import { buildFootballPlayer, animateFootballPlayer, applyPreSnapStance } from './player-model.js';
import { scoreboardText } from './scoreboard.js';
import { sampleHuddle, sampleBreakHuddle } from './huddle.js';

export function mountPractice(root, onReady = () => {}) {
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
    const textures = new Map(), textureLoader = new THREE.TextureLoader();
    const textureFor = url => {
        if (!textures.has(url)) { const texture = textureLoader.load(url); texture.colorSpace = THREE.SRGBColorSpace; textures.set(url, texture); }
        return textures.get(url);
    };
    const animation = root.dataset.animation ? JSON.parse(root.dataset.animation) : null;
    const sample = (type, time) => animation ? sampleEnginePlay(animation, time) : samplePlay(type, time);
    const duration = animation?.duration || DURATION;
    slider.max = duration;
    const offenseSide = animation?.possession || 'home';
    const scene = new THREE.Scene();
    scene.fog = new THREE.Fog(0x101a2b, 160, 280);
    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 350);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.maxPolarAngle = Math.PI / 2.1;
    controls.minDistance = 16;
    controls.maxDistance = 180;
    const focus = [animation?.line ?? 40, 0, 26.7];
    const moveAnchor = anchor => {
        const moved = translateCameraAnchor(camera.position.toArray(), controls.target.toArray(), focus, anchor);
        camera.position.set(...moved.position);
        controls.target.set(...moved.target);
        focus.splice(0, 3, ...anchor);
    };
    const cameraKey = root.dataset.cameraKey || 'football-practice-camera';
    const cameraSelect = root.querySelector('[data-camera]');
    let cameraMode = 'broadcast';
    const playDirection = animation?.direction ?? (offenseSide === 'home' ? 1 : -1);
    const nextDirection = Number(root.dataset.nextDirection) || (root.dataset.nextPossession === 'home' ? 1 : -1);
    const nextHomeDirection = nextDirection * (root.dataset.nextPossession === 'home' ? 1 : -1);
    let cameraDirection = playDirection;
    const saveCamera = () => {
        try { localStorage.setItem(cameraKey, JSON.stringify(captureCamera(cameraMode, camera.position.toArray(), controls.target.toArray(), focus, cameraDirection))); } catch { /* Storage may be unavailable. */ }
    };
    const setCamera = mode => {
        cameraMode = mode;
        cameraSelect.value = mode;
        const preset = cameraPreset(mode, focus, cameraDirection);
        controls.target.set(...preset.target);
        camera.position.set(...preset.position);
        controls.update();
        saveCamera();
    };
    let savedCamera;
    try { savedCamera = restoreCamera(JSON.parse(localStorage.getItem(cameraKey)), focus, cameraDirection); } catch { /* Use the default view. */ }
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
    if (!home.endzone_transparent) {
        addBox(10, 0.03, 53.33, home.endzone_background || 0x174880, 5, 0.02, 26.665);
        addBox(10, 0.03, 53.33, home.endzone_background || 0x174880, 115, 0.02, 26.665);
    }
    if (home.midfield_logo) {
        const logo = new THREE.Mesh(new THREE.PlaneGeometry(14, 14), new THREE.MeshBasicMaterial({map: textureFor(home.midfield_logo), transparent: true, depthWrite: false}));
        logo.rotation.x = -Math.PI / 2; logo.position.set(60, .045, 26.665); scene.add(logo);
    }
    for (const x of [5, 115]) {
        const url = x === 5 ? home.endzone_logo_left : home.endzone_logo_right;
        if (url) {
            const logo = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({transparent: true, depthWrite: false}));
            new THREE.TextureLoader().load(url, texture => {
                texture.colorSpace = THREE.SRGBColorSpace;
                const size = fitLogo(texture.image.width, texture.image.height, 44, 7);
                logo.scale.set(size.width, size.height, 1);
                logo.material.map = texture; logo.material.needsUpdate = true;
            });
            logo.rotation.set(-Math.PI / 2, 0, x === 5 ? Math.PI / 2 : -Math.PI / 2);
            logo.position.set(x, .08, 26.665); scene.add(logo); continue;
        }
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
    // Six-foot (two-yard) border sits outside the playable field.
    for (const z of [-1, 54.33]) addBox(124, 0.03, 2, 0xf3f1d9, 60, 0.05, z);
    for (const x of [-1, 121]) addBox(2, 0.03, 53.33, 0xf3f1d9, x, 0.05, 26.665);
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
    const stadium = buildStadium(home, document, away, JSON.parse(root.dataset.crowd || '{}'));
    scene.add(stadium.group);
    for (const x of [0, 120]) {
        addBox(.18, 3.5, .18, 0xffcc33, x, 1.75, 26.7);
        addBox(.18, .18, 6.2, 0xffcc33, x, 3.5, 26.7);
        for (const z of [23.6, 29.8]) addBox(.18, 5, .18, 0xffcc33, x, 6, z);
    }
    if (animation?.firstDown === null) firstDownLine.visible = false;
    const players = sample('pass', 0).players.map(player => {
        const side = player.side || (player.team === 'offense' ? offenseSide : (offenseSide === 'home' ? 'away' : 'home'));
        const team = side === 'home' ? home : away;
        const kit = { ...team.uniform, helmet_logo_left: team.helmet_logo_left, helmet_logo_right: team.helmet_logo_right };
        const group = buildFootballPlayer(player, kit, document, textureFor);
        group.rotation.y = (player.team === 'offense' ? 1 : -1) * playDirection * Math.PI / 2;
        // Temporary QB handedness diagnostic. By default, do not override
        // saved player appearance. Compare ?qb_hand=right and ?qb_hand=left
        // on the SAME quarterback to confirm model-side orientation.
        if (player.team === 'offense' && player.role === 'QB') {
            const qbHandOverride = new URLSearchParams(window.location.search).get('qb_hand');
            if (qbHandOverride === 'left' || qbHandOverride === 'right') {
                group.userData.throwingHand = qbHandOverride;
            }
            if (new URLSearchParams(window.location.search).has('qb_hand')) {
                const requested = player.appearance?.throwing_hand ?? '(default right)';
                console.info('[WebSports QB handedness]', {
                    quarterback: player.name || player.role,
                    appearance: requested,
                    effectiveHand: group.userData.throwingHand,
                    armIndex: group.userData.throwingHand === 'left' ? 1 : 0,
                });
            }
        }
        scene.add(group);
        return group;
    });
    const ball = new THREE.Mesh(new THREE.SphereGeometry(0.25, 12, 8), material(0x9d5829));
    ball.scale.set(1.6, 0.85, 0.85); ball.castShadow = true; scene.add(ball);
    const carrierArrow = new THREE.Mesh(new THREE.ConeGeometry(.5, .9, 4), new THREE.MeshBasicMaterial({ color: 0xffdf00 }));
    carrierArrow.rotation.z = Math.PI;
    scene.add(carrierArrow);
    const carrierRing = new THREE.Mesh(new THREE.RingGeometry(.7, .9, 32), new THREE.MeshBasicMaterial({ color: 0xffdf00, side: THREE.DoubleSide }));
    carrierRing.rotation.x = -Math.PI / 2;
    scene.add(carrierRing);
    const ballGlow = new THREE.Mesh(new THREE.SphereGeometry(.5, 12, 8), new THREE.MeshBasicMaterial({ color: 0xffdf00, transparent: true, opacity: .28, depthWrite: false }));
    scene.add(ballGlow);
    const resultPopup = root.querySelector('[data-result-popup]');
    const beforeState = root.dataset.beforeState ? JSON.parse(root.dataset.beforeState) : null;
    const afterState = root.dataset.afterState ? JSON.parse(root.dataset.afterState) : null;
    const teamNames = root.dataset.teamNames ? JSON.parse(root.dataset.teamNames) : null;
    let revealed = root.dataset.autoplay !== 'true';
    stadium.updateScoreboard(revealed ? afterState : beforeState, teamNames);
    const showState = committed => {
        if (!beforeState) return;
        revealed = committed;
        const state = committed ? afterState : beforeState, labels = scoreboardText(state, teamNames);
        root.querySelector('[data-scoreboard]').textContent = labels.score;
        root.querySelector('[data-home-score]').textContent = state.home_score;
        root.querySelector('[data-away-score]').textContent = state.away_score;
        root.querySelectorAll('[data-possession]').forEach(node => node.textContent = node.dataset.possession === state.possession ? '●' : '');
        root.querySelector('[data-clock]').textContent = labels.clock;
        root.querySelector('[data-clock-status]').textContent = labels.clockStatus;
        root.querySelector('[data-situation]').textContent = labels.compact;
        root.querySelectorAll('[data-timeout-marks]').forEach(node => {
            const count = state.timeouts?.[node.dataset.timeoutMarks] ?? 3;
            node.setAttribute('aria-label', `${count} timeouts remaining`);
            [...node.children].forEach((mark, index) => mark.classList.toggle('timeout-used', index >= count));
        });
        stadium.updateScoreboard(state, teamNames);
        const management = root.querySelector('[data-clock-management]');
        if (management) management.textContent = labels.management;
        root.querySelectorAll('[data-hidden-result]').forEach(el => el.hidden = !committed);
        root.querySelectorAll('[data-stats]').forEach(el => {
            const side = el.dataset.stats, stats = state.stats[side];
            el.textContent = `${teamNames[side]}: ${stats.plays} plays · ${stats.yards} yards · ${stats.turnovers} turnovers · ${stats.penalties ?? 0} penalties / ${stats.penalty_yards ?? 0} yards`;
        });
        const count = root.querySelector('[data-log-count]');
        if (count) count.textContent = committed ? afterState.version : beforeState.version;
        const form = root.querySelector('[data-call-form]'); if (form) form.hidden = !committed;
    };
    if (new URLSearchParams(window.location.search).get('debug_replay') === '1') root.classList.add('game-debug-replay');

    const audio = stadiumAudio(root);
    const cues = soundCues(animation, beforeState, afterState);
    let audioTime = -2;
    const turnover = turnoverMoment(animation);
    const liveBanner = document.createElement('div');
    liveBanner.className = 'game-live-turnover';
    liveBanner.setAttribute('role', 'status');
    liveBanner.setAttribute('aria-live', 'polite');
    liveBanner.hidden = true;
    root.append(liveBanner);
    let turnoverTime = -1, bannerRemaining = 0;
    const clearTurnoverBanner = () => { liveBanner.hidden = true; bannerRemaining = 0; };
    const nextLine = Number(root.dataset.nextLine || animation?.line || 60);
    let phase = resultPopup && root.dataset.autoplay !== 'true' ? 'huddle' : (root.hasAttribute('data-exhibition') && root.dataset.autoplay === 'true' ? (animation?.no_snap ? 'play' : 'liningup') : 'play');
    const lineupDuration = 3;
    let lineupProgress = 0;
    const setDuration = 4;
    let setElapsed = 0;
    let postElapsed = 0, huddleProgress = phase === 'huddle' ? 1 : 0;
    let elapsed = phase === 'huddle' ? duration : 0, running = root.dataset.autoplay === 'true', type = 'pass', speed = 1, lastTime = null, frameId;
    const renderState = () => {
        const finalFrame = phase === 'huddle' ? sample(type, duration) : null;
        let frame = finalFrame ? sampleHuddle(finalFrame, nextLine, root.dataset.nextPossession, huddleProgress, nextHomeDirection) : sample(type, elapsed);
        let future = finalFrame ? sampleHuddle(finalFrame, nextLine, root.dataset.nextPossession, Math.min(1, huddleProgress + .02), nextHomeDirection) : sample(type, Math.min(duration, elapsed + 0.03));
        if (phase === 'liningup') {
            const formation = samplePreSnapMotion(sample(type, 0), animation, 0);
            frame = sampleBreakHuddle(formation, animation.line, animation.possession, lineupProgress, playDirection * (offenseSide === 'home' ? 1 : -1));
            future = sampleBreakHuddle(formation, animation.line, animation.possession, Math.min(1, lineupProgress + .02), playDirection * (offenseSide === 'home' ? 1 : -1));
        }
        if (phase === 'set') {
            const formation = { ...sample(type, 0), event: `Set · Snap in ${Math.ceil(setDuration - setElapsed)}s` };
            frame = samplePreSnapMotion(formation, animation, setElapsed / 3);
            future = samplePreSnapMotion(formation, animation, (setElapsed + .03) / 3);
        }
        if (beforeState && !revealed && !['liningup', 'set'].includes(phase) && elapsed >= (animation?.reveal_at ?? Math.min(5.3, duration))) showState(true);
        const motionTime = phase === 'liningup' ? lineupProgress * lineupDuration : phase === 'huddle' ? duration + huddleProgress * 1.5 : phase === 'set' ? setElapsed : elapsed;
        frame.players.forEach((player, i) => {
            const mesh = players[i];
            // Reset the articulated stance every frame. Running, throws,
            // tackles, and the QB kneel must never inherit a prior crouch.
            if (mesh.userData.waist) mesh.userData.waist.rotation.x = 0;
            mesh.userData.knees?.forEach(knee => { knee.rotation.x = 0; });

            const next = future.players[i];
            const moving = Math.hypot(next.x - player.x, next.z - player.z) > 0.002;
            mesh.rotation.x = 0; // Clear any previous pre-snap lean before each render.
            mesh.rotation.z = 0;
            mesh.position.set(player.x, moving ? Math.sin(motionTime * 18 + i) * 0.06 : 0, player.z);
            if (frame.huddle) mesh.rotation.y = Math.atan2(player.facingX - player.x, player.facingZ - player.z);
            else if ((animation?.dropback || animation?.passing || (!animation && type === 'pass')) && player.role === 'QB' && player.team === 'offense' && phase === 'play' && (animation?.carrier !== 'QB' || elapsed < 2)) mesh.rotation.y = playDirection * Math.PI / 2;
            else if (moving) mesh.rotation.y = Math.atan2(next.x - player.x, next.z - player.z);
            else if (phase === 'set' || elapsed === 0) mesh.rotation.y = (player.team === 'offense' ? 1 : -1) * playDirection * Math.PI / 2;
            // Coordinate contact around one shared point and fall direction. Individual
            // paths are unchanged until contact; never mutate saved play animation data.
            if (phase === 'play' && animation?.contact_at != null && elapsed >= animation.contact_at
                && animation.tackle_style && animation.tackler_role
                && ((player.team === 'offense' && player.role === animation.carrier)
                    || (player.team === 'defense' && player.role === animation.tackler_role))) {
                const carrier = frame.players.find(p => p.team === 'offense' && p.role === animation.carrier);
                const tackler = frame.players.find(p => p.team === 'defense' && p.role === animation.tackler_role);
                if (carrier && tackler) {
                    const defender = player.team === 'defense';
                    const style = animation.tackle_style;
                    const duration = style === 'wrap' ? .65 : style === 'lunge' ? .38 : .55;
                    const timeSinceContact = elapsed - animation.contact_at;
                    const contact = Math.min(1, timeSinceContact / .12);
                    // Delay the carrier's fall slightly behind the tackler's initial hit.
                    const fall = Math.min(1, Math.max(0, (timeSinceContact - (defender ? 0 : .08)) / duration));
                    const midX = (carrier.x + tackler.x) / 2;
                    const midZ = (carrier.z + tackler.z) / 2;
                    const approachX = carrier.x - tackler.x;
                    const approachZ = carrier.z - tackler.z;
                    const magnitude = Math.hypot(approachX, approachZ);
                    // If positions coincide, use offense direction for a stable fall axis.
                    const dirX = magnitude > .01 ? approachX / magnitude : playDirection;
                    const dirZ = magnitude > .01 ? approachZ / magnitude : 0;
                    const offset = defender ? -.33 : .33;
                    const targetX = midX + dirX * offset;
                    const targetZ = midZ + dirZ * offset;
                    mesh.position.x += (targetX - mesh.position.x) * contact;
                    mesh.position.z += (targetZ - mesh.position.z) * contact;
                    // Both players share a facing/lean axis instead of independent
                    // local rotations that send them in opposite directions.
                    mesh.rotation.y = Math.atan2(dirX, dirZ);
                    const lean = style === 'wrap' ? Math.PI * .35 : style === 'lunge' ? Math.PI * .53 : Math.PI * .46;
                    mesh.rotation.z = fall * lean;
                    mesh.position.y = fall * (defender ? .08 : .12);
                }
            }
            const kneelingHolder = animation?.players?.[i]?.pose === 'holder-kneel' && ['set', 'play', 'result'].includes(phase);
            mesh.userData.holderKneel = kneelingHolder;
            if (kneelingHolder) {
                mesh.position.y = -.45;
                mesh.rotation.y = playDirection * Math.PI / 2;
            }
            // Catch/reach at ball arrival, then tuck the ball while turning upfield.
            // No pose is applied to sacks, throwaways, or other receivers.
            const receiving = animation?.receiver_role && player.team === 'offense'
                && player.role === animation.receiver_role && phase === 'play';
            const reception = receiving && elapsed >= 3.35 && elapsed < 4.12
                ? (['incomplete', 'interception'].includes(animation.outcome) ? 'reach' : 'catch')
                : receiving && elapsed >= 4.12 && elapsed <= 5.3
                    && !['incomplete', 'interception'].includes(animation.outcome) ? 'tuck' : null;
            // The arm pose and the football use the same saved possession timeline.
            // Don't cradle a ball during the catch itself or after a fumble.
            const heldOnOffense = phase === 'play' && animation && !animation.no_snap
                && frame.ballHolder?.team === 'offense'
                && frame.ballHolder.role === player.role && player.team === 'offense';
            const eligibleCarrier = player.role === 'RB'
                || (animation?.receiver_role && player.role === animation.receiver_role);
            const possessionAt = player.role === 'RB' ? 1 : 3.8;
            mesh.userData.carryingArm = player.role === 'RB' ? 0 : 1;
            mesh.userData.cradlingBall = Boolean(heldOnOffense && eligibleCarrier
                && elapsed >= possessionAt + .16
                && !(animation?.outcome === 'fumble' && elapsed >= 5.3));
            animateFootballPlayer(mesh, moving, motionTime, i, (animation?.passing || (!animation && type === 'pass')) && player.role === 'QB' && player.team === 'offense' && phase === 'play' ? elapsed : null, reception);

            // Pre-snap realism: offense huddles around a kneeling QB, defenses
            // communicate in a looser cluster facing the offense, and both lines
            // use more believable stances before the snap.
            const role = player.role ?? '';
            const isOneOf = (...roles) => roles.includes(role);
            const isDefFront = /^(DE\d?|DT\d?|NT|EDGE\d?|DL\d?)$/.test(role);
            const isLinebacker = /^(LB\d?|MLB|LOLB|ROLB)$/.test(role);
            const isSecondary = /^(CB\d?|FS|SS|S\d?|NB)$/.test(role);
            const teamGroup = frame.players.filter(p => p.team === player.team);
            const offenseGroup = frame.players.filter(p => p.team === 'offense');
            const averagePoint = list => {
                const total = list.reduce((sum, p) => {
                    sum.x += p.x ?? 0;
                    sum.y += p.y ?? 0;
                    sum.z += p.z ?? 0;
                    return sum;
                }, { x: 0, y: 0, z: 0 });
                const count = Math.max(1, list.length);
                return new THREE.Vector3(total.x / count, total.y / count, total.z / count);
            };
            const teamCenter = averagePoint(teamGroup);
            const offenseCenter = averagePoint(offenseGroup);
            const earlySnap = phase === 'play' && elapsed < .28;
            // Crouch only after the break-huddle jog, not while travelling.
            const presnap = phase === 'set' || earlySnap || (phase === 'liningup' && lineupProgress >= .85);
            const huddlePhase = phase === 'huddle';
            const setFacing = target => {
                const dx = (target.x ?? 0) - mesh.position.x;
                const dz = (target.z ?? 0) - mesh.position.z;
                if (Math.abs(dx) + Math.abs(dz) > .001) mesh.rotation.y = Math.atan2(dx, dz);
            };
            const lowerArms = (leftX, rightX, elbowX = -.95) => {
                if (mesh.userData.arms?.[0]) mesh.userData.arms[0].rotation.x = leftX;
                if (mesh.userData.arms?.[1]) mesh.userData.arms[1].rotation.x = rightX;
                if (mesh.userData.elbows?.[0]) mesh.userData.elbows[0].rotation.x = elbowX;
                if (mesh.userData.elbows?.[1]) mesh.userData.elbows[1].rotation.x = elbowX;
            };

            if (huddlePhase) {
                if (player.team === 'offense') {
                    if (role === 'QB') {
                        // After the regular pose is rendered, fold one knee and
                        // bring the forearms toward the raised knee.
                        // Initialize the blend before using it: otherwise a huddle frame throws.
                        const kneel = Math.max(0, Math.min(1, (huddleProgress - .78) / .22));
                        mesh.userData.legs?.forEach((leg, j) => { leg.rotation.x = kneel * (j === 0 ? -1.48 : .95); });
                        mesh.userData.arms?.forEach((arm, j) => { arm.rotation.x = kneel * (j === 0 ? -.90 : -.70); arm.rotation.z = kneel * (j === 0 ? -.10 : .10); });
                        mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.20 - kneel * .62; });
                        // Keep the QB upright while walking into the huddle;
                        // kneel only after the players have arrived.
                        mesh.position.y -= .34 * kneel;
                        mesh.rotation.x = -.12 * kneel;
                    } else {
                        setFacing(teamCenter);
                        mesh.rotation.x = -.04;
                    }
                } else if (player.team === 'defense') {
                    // sampleHuddle now owns the defensive spacing/transition.
                    setFacing(offenseCenter);
                    if (isDefFront) {
                        mesh.rotation.x = -.12;
                    } else if (isLinebacker) {
                        mesh.rotation.x = -.06;
                    }
                }
            }

            // Keep the whole player upright; only the articulated waist, hips,
            // knees and arms determine pre-snap posture. One pose system owns it.
            const centerStance = player.team === 'offense' && role === 'C';
            const offensiveLine = player.team === 'offense' && ['LG', 'RG', 'LT', 'RT'].includes(role);
            const defensiveLine = player.team === 'defense' && isDefFront;
            const tightEnd = player.team === 'offense' && ['TE', 'TE1', 'TE2'].includes(role);
            const linebacker = player.team === 'defense' && isLinebacker;
            const stanceDepth = centerStance ? .22 : offensiveLine ? .17 : defensiveLine ? .19 : .05;
            if (presnap && (centerStance || offensiveLine || defensiveLine || tightEnd || linebacker)) {
                mesh.position.y -= stanceDepth;
            }

            // The center bends over the ball, and the QB/RB extend their hands
            // briefly for the transfer. All three poses reset each render.
            // Do not reset rotation.x here: that erased the linemen's stances
            // that were just applied above. Only override the center during
            // the actual snap, when the line must leave its stance.
            if (['liningup', 'set', 'play'].includes(phase) && animation && !animation.no_snap) {
                if (player.team === 'offense' && player.role === 'C'
                    && (phase === 'set' || (phase === 'liningup' && lineupProgress >= .85)
                        || (phase === 'play' && elapsed < .38))) {
                    mesh.userData.arms?.forEach(arm => { arm.rotation.x = -.90; });
                    mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.75; });
                }
                if (player.team === 'offense' && player.role === 'QB' && animation.carrier === 'RB'
                    && elapsed >= .56 && elapsed < 1.02) {
                    mesh.userData.arms?.forEach(arm => { arm.rotation.x = -1.05; });
                    mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.70; });
                }
                if (player.team === 'offense' && player.role === 'RB' && animation.carrier === 'RB'
                    && elapsed >= .74 && elapsed < 1.07) {
                    mesh.userData.arms?.forEach(arm => { arm.rotation.x = -.95; });
                    mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.95; });
                }
            }
            // Drive the dedicated joints after all legacy arm/snap poses, so
            // no older pose assignment can silently cancel the stance.
            const lineStance = presnap
                ? (player.team === 'offense'
                    ? role === 'C' ? 'center'
                        : ['LG', 'RG', 'LT', 'RT'].includes(role) ? 'three'
                            : ['TE', 'TE1', 'TE2'].includes(role) ? 'ready' : null
                    : isDefFront ? 'def-front' : isLinebacker ? 'ready' : null)
                : null;
            applyPreSnapStance(mesh, lineStance);
            // Pre-snap-only visual breathing room between opposing front lines.
            // Keep the center fixed on the ball and leave recorded paths intact.
            // Ease offsets away at the snap to avoid popping into the play track.
            const frontGapBlend = phase === 'set' ? 1
                : phase === 'liningup' ? Math.max(0, Math.min(1, (lineupProgress - .78) / .22))
                : phase === 'play' ? Math.max(0, 1 - elapsed / .24) : 0;
            if (frontGapBlend > 0) {
                if (player.team === 'offense' && ['LG', 'RG', 'LT', 'RT'].includes(role)) {
                    mesh.position.x -= playDirection * .18 * frontGapBlend;
                } else if (player.team === 'defense' && isDefFront) {
                    mesh.position.x += playDirection * .48 * frontGapBlend;
                    // Don't stack defensive helmets directly across the center.
                    if (/^DT/.test(role)) mesh.position.z += (role === 'DT1' ? -.20 : .20) * frontGapBlend;
                }
            }

        });
        ball.position.set(frame.ball.x, frame.ball.y, frame.ball.z);
        // During a real throw, keep the football in the QB's right hand until
        // release. Blend back to the saved flight path so the handoff is smooth.
        // This is presentation-only: do not edit frame.ball or animation paths.
        if (animation?.passing && animation?.dropback && animation?.carrier !== 'QB'
            && phase === 'play' && elapsed >= .6 && elapsed < (animation.throw_at ?? 2.2)) {
            const quarterbackIndex = frame.players.findIndex(player => player.team === 'offense' && player.role === 'QB');
            const quarterback = players[quarterbackIndex];
            const handIndex = quarterback?.userData.throwingHand === 'left' ? 1 : 0;
            const throwingHand = quarterback?.userData.elbows?.[handIndex];
            if (throwingHand) {
                quarterback.updateMatrixWorld(true);
                const handPosition = throwingHand.localToWorld(new THREE.Vector3(0, -.35, .04));
                const release = animation.throw_at ?? 2.2;
                const blend = Math.max(0, Math.min(1, (elapsed - (release - .20)) / .20));
                const eased = blend * blend * (3 - 2 * blend);
                ball.position.lerpVectors(handPosition, ball.position, eased);
            }
        }
        // Player-relative snap and handoff: animate the visible ball without
        // modifying recorded movement paths, holder events or game outcomes.
        // During the snap, the center presents the ball low between his legs.
        // The ball then moves directly to the QB; on runs he gives it to the RB.
        if (['set', 'play'].includes(phase) && animation && !animation.no_snap
            && (phase !== 'play' || elapsed < 1.02)) {
            const getOffense = role => {
                const index = frame.players.findIndex(p => p.team === 'offense' && p.role === role);
                return index < 0 ? null : players[index];
            };
            const center = getOffense('C');
            const quarterback = getOffense('QB');
            const runningBack = animation.carrier === 'RB' && !animation.passing ? getOffense('RB') : null;
            const at = (mesh, x, y, z) => {
                if (!mesh) return null;
                mesh.updateMatrixWorld(true);
                return mesh.localToWorld(new THREE.Vector3(x, y, z));
            };
            const centerSnap = at(center, 0, .48, -.30);
            const qbHands = at(quarterback, 0, 1.24, .38);
            const rbHands = at(runningBack, -.18, 1.18, .37);
            const smooth = t => { const x = Math.max(0, Math.min(1, t)); return x * x * (3 - 2 * x); };
            if ((phase !== 'play' || elapsed < .32) && centerSnap) {
                ball.position.copy(centerSnap);
            } else if (elapsed < .6 && centerSnap && qbHands) {
                ball.position.copy(centerSnap).lerp(qbHands, smooth((elapsed - .32) / .28));
            } else if (runningBack && elapsed < 1 && qbHands && rbHands) {
                ball.position.copy(qbHands).lerp(rbHands, smooth((elapsed - .6) / .4));
            }
        }
        if (['play', 'result'].includes(phase)) moveAnchor(ball.position.toArray());
        const holder = ballCarrier(frame, phase);
        // While held, the ball should not spin independently like a loose ball.
        // Hand/torso attachment controls its position; flight still spins.
        ball.rotation.z = holder ? 0 : elapsed * 6;
        // The ball's saved track remains authoritative until tackle contact.
        // After contact, visually follow the offensive ball carrier down.
        // Do not alter loose balls, turnovers, special teams or saved tracks.
        if (phase === 'play' && animation?.contact_at != null
            && elapsed >= animation.contact_at && holder?.team === 'offense'
            && holder.role === animation.carrier && animation?.tackle_style) {
            const carrierIndex = frame.players.findIndex(player => player.team === 'offense' && player.role === animation.carrier);
            if (carrierIndex !== -1) {
                const carrierMesh = players[carrierIndex];
                const style = animation.tackle_style;
                const fallDuration = style === 'wrap' ? .65 : style === 'lunge' ? .38 : .55;
                const delay = .09;
                const progress = Math.min(1, Math.max(0, (elapsed - animation.contact_at - delay) / fallDuration));
                // Ball is held against the falling player, not hovering at its
                // standing-height timeline coordinate. This is visual only.
                ball.position.x = carrierMesh.position.x;
                ball.position.z = carrierMesh.position.z;
                ball.position.y = Math.max(.27, 1 - .73 * progress);
            }
        }
        // Visual attachment for a carried football. The saved timeline remains
        // authoritative for throws, handoffs, loose balls and interceptions.
        // Read the timeline's current holder instead of inferring possession.
        if (phase === 'play' && animation && !animation.no_snap && holder?.team === 'offense'
            && (holder.role === 'RB' || (animation.receiver_role && holder.role === animation.receiver_role))
            && !(animation.outcome === 'fumble' && elapsed >= 5.3)) {
            const carrierIndex = frame.players.findIndex(player =>
                player.team === holder.team && player.role === holder.role);
            const carrierMesh = carrierIndex >= 0 ? players[carrierIndex] : null;
            if (carrierMesh) {
                // This point is on the torso in model-local coordinates, so it
                // follows the player as the whole model turns or falls.
                carrierMesh.updateMatrixWorld(true);
                const tuckSide = holder.role === 'RB' ? -1 : 1;
                const tuckPosition = carrierMesh.localToWorld(new THREE.Vector3(tuckSide * .40, 1.28, .29));
                // Blend briefly after the handoff/catch so the football never
                // teleports between its recorded track and the carried position.
                const pickupAt = holder.role === 'RB' ? 1 : 3.8;
                const blend = Math.max(0, Math.min(1, (elapsed - pickupAt) / .22));
                const eased = blend * blend * (3 - 2 * blend);
                ball.position.lerp(tuckPosition, eased);
            }
        }
        carrierArrow.visible = carrierRing.visible = Boolean(holder);
        if (holder) {
            carrierArrow.position.set(holder.x, 3.2, holder.z);
            carrierRing.position.set(holder.x, .12, holder.z);
        }
        ballGlow.visible = !holder && ['play', 'result'].includes(phase);
        ballGlow.position.copy(ball.position);
        const eventMessage = animation ? frame.event : `${type === 'pass' ? 'Slant pass' : 'Inside run'} · ${frame.event}`;
        const message = holder ? `${eventMessage} · ${carrierLabel(holder)}` : eventMessage;
        if (status.textContent !== message) status.textContent = message;
        if (animation?.no_snap) { carrierArrow.visible = carrierRing.visible = ballGlow.visible = false; }
        slider.value = elapsed;
        root.querySelector('[data-time]').textContent = phase === 'liningup' ? `Forming up · ${(lineupProgress * lineupDuration).toFixed(1)} / ${lineupDuration.toFixed(1)}s` : phase === 'set' ? `Ready · ${(setDuration - setElapsed).toFixed(1)}s` : `${elapsed.toFixed(1)} / ${duration.toFixed(1)}s`;
    };
    const resize = () => {
        const width = host.clientWidth, height = Math.max(400, host.clientHeight);
        renderer.setSize(width, height, false);
        camera.aspect = width / height; camera.updateProjectionMatrix();
    };
    const observer = new ResizeObserver(resize); observer.observe(host); resize();
    const moveFocus = (line, direction = cameraDirection) => {
        if (cameraMode === 'quarterback' && direction !== cameraDirection) {
            camera.position.x = 2 * focus[0] - camera.position.x;
            camera.position.z = 2 * focus[2] - camera.position.z;
            controls.target.x = 2 * focus[0] - controls.target.x;
            controls.target.z = 2 * focus[2] - controls.target.z;
        }
        cameraDirection = direction;
        moveAnchor([line, 0, 26.7]); controls.update(); saveCamera();
    };
    const replayView = () => {
        clearTurnoverBanner(); turnoverTime = -1;
        audioTime = -2;
        showState(false);
        phase = 'play'; lineupProgress = 0; setElapsed = 0; postElapsed = 0; huddleProgress = 0;
        if (resultPopup) resultPopup.hidden = true;
        moveFocus(animation?.line ?? 40, playDirection);
        scrimmageLine.position.x = animation?.line ?? 40;
        firstDownLine.position.x = animation?.firstDown ?? 50;
    };
    const huddleView = () => {
        moveFocus(nextLine, nextDirection);
        scrimmageLine.position.x = nextLine;
        firstDownLine.position.x = Math.max(10, Math.min(110, nextLine + nextDirection * Number(root.dataset.nextDistance || 10)));
    };
    if (phase === 'huddle') huddleView();
    playButton.addEventListener('click', () => {
        root.classList.add('game-replay-controls');
        if (phase !== 'play' && phase !== 'liningup' && phase !== 'set') replayView();
        if (elapsed >= duration) { elapsed = 0; audioTime = -2; }
        setCpuAuto(false);
        running = !running; playButton.textContent = running ? 'Pause' : 'Play';
    });
    root.querySelector('[data-reset]').addEventListener('click', () => { setCpuAuto(false); replayView(); elapsed = 0; running = false; playButton.textContent = 'Play'; });
    root.querySelector('[data-play-type]')?.addEventListener('change', event => { type = event.target.value; elapsed = 0; audioTime = -2; running = false; playButton.textContent = 'Play'; });
    root.querySelector('[data-speed]').addEventListener('change', event => speed = Number(event.target.value));
    root.querySelector('[data-reset-camera]').addEventListener('click', () => setCamera(root.querySelector('[data-camera]').value));
    root.querySelector('[data-camera]').addEventListener('change', event => setCamera(event.target.value));
    slider.addEventListener('input', () => { setCpuAuto(false); replayView(); elapsed = Number(slider.value); turnoverTime = elapsed; audioTime = elapsed; running = false; playButton.textContent = 'Play'; });
    if (running) playButton.textContent = 'Pause';
    const callForm = root.querySelector('[data-call-form]');
    if (callForm && root.dataset.defenseOptions) {
        const options = JSON.parse(root.dataset.defenseOptions), call = callForm.querySelector('[name="call"]'), defense = callForm.querySelector('[name="defense"]');
        const refreshCalls = () => {
            if (!call && defense) {
                [...defense.options].filter(option => ['blitz', 'run_stop'].includes(option.value)).forEach(option => option.remove());
            }
            if (call && defense) {
                const selected = defense.value;
                defense.replaceChildren(...options[call.value].filter(value => !['blitz', 'run_stop'].includes(value)).map(value => { const option = document.createElement('option'); option.value = value; option.textContent = value.replaceAll('_', ' '); return option; }));
                if ([...defense.options].some(option => option.value === selected)) defense.value = selected;
            }
            const special = call ? ['punt','field_goal','kickoff','extra_point'].includes(call.value) : root.dataset.cpuSpecial === 'true';
            callForm.querySelectorAll('[data-formation]').forEach(select => select.parentElement.hidden = special);
        };
        call?.addEventListener('change', refreshCalls); refreshCalls();
        const formation = callForm.querySelector('[name="offense_formation"]');
        const allCalls = call ? [...call.options].map(option => ({value: option.value, text: option.textContent})) : [];
        const phaseCalls = allCalls.length > 0 && allCalls.every(option => ['kickoff','extra_point','two_point_run','two_point_pass'].includes(option.value));
        const formationCalls = { singleback: ['inside_run','outside_run','slant','short_pass','medium_pass'], i_form: ['inside_run','outside_run','short_pass','medium_pass'], pistol: ['inside_run','outside_run','draw','short_pass','medium_pass','deep_pass'], shotgun: ['inside_run','draw','screen','slant','short_pass','medium_pass','deep_pass'], spread: ['outside_run','draw','screen','short_pass','medium_pass','deep_pass'], trips: ['outside_run','screen','slant','short_pass','medium_pass','deep_pass'], punt: ['punt'], field_goal: ['field_goal'] };
        const refreshFormation = () => {
            if (!call || !formation || phaseCalls || !formationCalls[formation.value]) return;
            const selected = call.value;
            const choices = allCalls.filter(option => formationCalls[formation.value].includes(option.value) || (!['punt','field_goal'].includes(formation.value) && ['kickoff','extra_point','two_point_run','two_point_pass','spike','kneel'].includes(option.value)));
            call.replaceChildren(...choices.map(({value,text}) => new Option(text,value)));
            if (choices.some(option => option.value === selected)) call.value = selected;
            refreshCalls();
        };
        formation?.addEventListener('change', refreshFormation); refreshFormation();
        callForm.querySelector('[data-coach-offense]')?.addEventListener('click', () => {
            const plan = JSON.parse(root.dataset.coachOffense); formation.value = ['punt','field_goal'].includes(plan.call) ? plan.call : plan.formation; refreshFormation(); call.value = plan.call; callForm.querySelector('[name="motion"]').value = plan.motion || 'none'; refreshCalls();
        });
        callForm.querySelector('[data-coach-defense]')?.addEventListener('click', () => {
            const plan = JSON.parse(root.dataset.coachDefense);
            callForm.querySelector('[name="defense_formation"]').value = plan.formation;
            const recommendation = plan.call === 'blitz' ? 'man_to_man' : plan.call === 'run_stop' ? 'zone' : plan.call;
            defense.value = [...defense.options].some(option => option.value === recommendation) ? recommendation : defense.options[0].value;
            callForm.querySelector('[name="blitz"]').checked = plan.call === 'blitz';
            callForm.querySelector('[name="expect"]').value = plan.call === 'run_stop' ? 'run' : plan.call === 'zone' ? 'pass' : 'balanced';
        });
    }
    const tickPlayWizard = mountPlayWizard(root);
    const disposeMobileControls = mobileControls(root);
    root.querySelectorAll('[data-timeout-form]').forEach(form => form.addEventListener('submit', () => { saveCamera(); setCpuAuto(false); form.querySelector('button').disabled = true; }));
    callForm?.addEventListener('submit', () => {
        root.classList.remove('game-replay-controls');
        saveCamera();
        const button = callForm.querySelector('[data-snap]'); button.disabled = true; button.textContent = 'Simulating…';
    });
    const finishResult = () => {
        if (phase !== 'result') return;
        resultPopup.hidden = true; phase = 'huddle'; postElapsed = 0; huddleView();
    };
    root.querySelector('[data-result-ok]')?.addEventListener('click', finishResult);
    const highlightsDialog = root.querySelector('[data-highlights-dialog]');
    root.querySelector('[data-open-highlights]')?.addEventListener('click', () => highlightsDialog.showModal());
    root.querySelector('[data-finish-sim-form]')?.addEventListener('submit', event => {
        if (!window.confirm('Finish this game with Quick Sim? The CPU will play the remainder of the game.')) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        showSimProgress();
        saveCamera(); setCpuAuto(false); root.querySelector('[data-finish-sim-form] button').disabled = true;
    });
    root.querySelector('[data-save-highlight-form]')?.addEventListener('submit', () => { saveCamera(); setCpuAuto(false); });
    const logDialog = root.querySelector('[data-log-dialog]'), boxDialog = root.querySelector('[data-box-dialog]');
    root.querySelector('[data-open-log]')?.addEventListener('click', () => logDialog.showModal());
    root.querySelector('[data-open-box]')?.addEventListener('click', () => boxDialog.showModal());
    if (root.dataset.showHighlights === 'true') highlightsDialog?.showModal();
    else if (root.dataset.summary === 'true') boxDialog?.showModal();
    root.querySelector('[data-fullscreen]')?.addEventListener('click', async () => {
        try { if (document.fullscreenElement) await document.exitFullscreen(); else await root.requestFullscreen(); } catch { /* The full-window field remains available. */ }
    });
    const personnelDialog = root.querySelector('[data-personnel-dialog]');
    root.querySelector('[data-open-personnel]')?.addEventListener('click', () => personnelDialog?.showModal());
    const injuryDialog = root.querySelector('[data-injury-dialog]');
    const injuryKey = `${cameraKey}:injury:${root.dataset.playNumber}`;
    let injuryShown = false;
    try { injuryShown = sessionStorage.getItem(injuryKey) === 'shown'; } catch { /* Optional persistence. */ }
    const coinDialog = root.querySelector('[data-coin-dialog]');
    const pregameDialog = root.querySelector('[data-pregame-dialog]');
    const pregameKey = `${root.dataset.cameraKey}:pregame-shown`;
    let showPregame = Boolean(pregameDialog && root.dataset.playNumber === '0');
    try { if (sessionStorage.getItem(pregameKey) === 'yes') showPregame = false; } catch { /* Storage optional */ }
    let openingPregame = showPregame;
    if (showPregame) pregameDialog.showModal();
    else coinDialog?.showModal();
    const pregameCloseButtons = pregameDialog?.querySelectorAll('[data-pregame-close]');
    root.querySelector('[data-open-lineups]')?.addEventListener('click', () => {
        if (!pregameDialog || pregameDialog.open) return;
        openingPregame = false;
        pregameCloseButtons?.forEach((button, index) => {
            button.textContent = index === 0 ? 'Close Lineups' : 'Return to Game';
        });
        pregameDialog.showModal();
    });
    pregameCloseButtons?.forEach(button => button.addEventListener('click', () => pregameDialog.close()));
    pregameDialog?.addEventListener('close', () => {
        if (!openingPregame) return;
        openingPregame = false;
        try { sessionStorage.setItem(pregameKey, 'yes'); } catch { /* Storage optional */ }
        coinDialog?.showModal();
    });
    pregameDialog?.querySelectorAll('[data-lineup-tab]').forEach(button => button.addEventListener('click', () => {
        pregameDialog.querySelectorAll('[data-lineup-tab]').forEach(tab => {
            const active = tab === button;
            tab.setAttribute('aria-selected', String(active));
            tab.classList.toggle('bg-blue-700', active);
            tab.classList.toggle('bg-gray-700', !active);
        });
        pregameDialog.querySelectorAll('[data-lineup-group]').forEach(panel => { panel.style.display = panel.dataset.lineupGroup === button.dataset.lineupTab ? 'grid' : 'none'; });
    }));
    coinDialog?.addEventListener('cancel', event => { if (coinDialog.dataset.pending === 'true') event.preventDefault(); });
    const otDialog = root.querySelector('[data-ot-dialog]');
    otDialog?.addEventListener('cancel', event => event.preventDefault());
    const quarterDialog = root.querySelector('[data-quarter-dialog]');
    const penaltyDialog = root.querySelector('[data-penalty-dialog]');
    let penaltyShown = false;
    penaltyDialog?.addEventListener('cancel', event => event.preventDefault());
    root.querySelectorAll('[data-penalty-form]').forEach(form => form.addEventListener('submit', () => { saveCamera(); setCpuAuto(false); }));
    const quarterKey = `${cameraKey}:quarter:${root.dataset.playNumber}`;
    let quarterShown = false;
    try { quarterShown = sessionStorage.getItem(quarterKey) === 'shown'; } catch { /* Show it once per page. */ }
    const showQuarter = () => {
        if (penaltyDialog && !penaltyShown) {
            penaltyShown = true;
            penaltyDialog.showModal();
            penaltyDialog.querySelector('button')?.focus();
            return;
        }
        if (penaltyDialog?.open) return;
        if (injuryDialog && !injuryShown) {
            injuryShown = true; injuryDialog.showModal();
            try { sessionStorage.setItem(injuryKey, 'shown'); } catch { /* Optional persistence. */ }
            return;
        }
        if (injuryDialog?.open) return;
        if (otDialog) { if (!otDialog.open) otDialog.showModal(); return; }
        if (!quarterDialog || quarterShown) return;
        quarterShown = true;
        quarterDialog.showModal();
        quarterDialog.querySelector('button')?.focus();
        try { sessionStorage.setItem(quarterKey, 'shown'); } catch { /* Optional persistence. */ }
    };
    const snapButton = callForm?.querySelector('[data-snap]');
    const cpuToggle = root.querySelector('[data-cpu-toggle]'), cpuStatus = root.querySelector('[data-cpu-status]');
    const cpuKey = `${cameraKey}:cpu-autoplay`;
    let cpuAuto = false;
    try { cpuAuto = root.dataset.cpuOnly === 'true' && sessionStorage.getItem(cpuKey) === 'true'; } catch { /* Autoplay starts paused if storage is unavailable. */ }
    const setCpuAuto = enabled => {
        cpuAuto = enabled;
        if (cpuToggle) cpuToggle.textContent = enabled ? 'Pause CPU game' : 'Start CPU game';
        if (cpuStatus) cpuStatus.textContent = enabled ? 'CPU game running · pauses at quarter breaks' : 'Autoplay off · current play finishes';
        try { sessionStorage.setItem(cpuKey, String(enabled)); } catch { /* Optional persistence. */ }
    };
    if (cpuAuto) setCpuAuto(true);
    cpuToggle?.addEventListener('click', () => setCpuAuto(!cpuAuto));

    coinDialog?.addEventListener('close', () => { if (snapButton) snapButton.disabled = false; });
    if (coinDialog?.open && snapButton) snapButton.disabled = true;
    if (quarterDialog && !quarterShown && snapButton) snapButton.disabled = true;
    quarterDialog?.addEventListener('close', () => { playButton.textContent = 'Replay'; if (snapButton) snapButton.disabled = false; if (afterState?.status === 'final' && !afterState?.penalty_pending) boxDialog?.showModal(); });
    penaltyDialog?.addEventListener('close', showQuarter);
    injuryDialog?.addEventListener('close', showQuarter);
    if ((otDialog || quarterDialog || penaltyDialog || injuryDialog) && root.dataset.autoplay !== 'true') showQuarter();
    const silenceHidden = () => { if (document.hidden) audio.setActive(false); };
    document.addEventListener('visibilitychange', silenceHidden);
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
        if (bannerRemaining > 0) {
            bannerRemaining = Math.max(0, bannerRemaining - delta);
            if (bannerRemaining === 0) liveBanner.hidden = true;
        }
        if (phase === 'play' && running) {
            if (elapsed < turnoverTime) clearTurnoverBanner();
            if (crossedTurnover(turnover, turnoverTime, elapsed)) {
                liveBanner.textContent = turnover.title;
                liveBanner.hidden = false;
                bannerRemaining = 3;
            }
            turnoverTime = elapsed;
        }
        if (elapsed >= duration && phase === 'play') {
            running = false; playButton.textContent = 'Replay';
            if (resultPopup) { phase = 'result'; postElapsed = 0; resultPopup.hidden = false; }
            else showQuarter();
        } else if (phase === 'result') {
            postElapsed += delta;
            if (postElapsed >= 3) {
                finishResult();
            }
        } else if (phase === 'huddle' && huddleProgress < 1) {
            postElapsed += delta; huddleProgress = Math.min(1, postElapsed / 1.5);
            if (huddleProgress === 1) showQuarter();
        }
        const audible = !document.hidden && !quarterDialog?.open && !penaltyDialog?.open && !logDialog?.open && !highlightsDialog?.open && !boxDialog?.open && (running || phase === 'result' || (phase === 'huddle' && huddleProgress < 1));
        audio.setActive(audible);
        const soundTime = phase === 'liningup' || phase === 'set' ? -1 : phase === 'result' ? duration + postElapsed : elapsed;
        if (audible) {
            if (running || phase === 'result') crossedCues(cues, audioTime, soundTime).forEach(cue => audio.play(cue));
            audioTime = soundTime;
        } else if (running || phase === 'result') audioTime = soundTime;
        tickPlayWizard?.({delta, ready: !callForm.hidden && ((phase === 'huddle' && huddleProgress === 1) || (root.dataset.playNumber === '0' && !running))});
        renderState(); controls.update(); renderer.render(scene, camera);
        onReady(); onReady = () => {};
        if (cpuToggle && callForm && canAdvanceCpu({ enabled: cpuAuto, visible: !document.hidden,
            ready: !callForm.hidden && ((phase === 'huddle' && huddleProgress === 1) || (root.dataset.playNumber === '0' && !running)),
            submitting: snapButton.disabled, dialogOpen: Boolean(pregameDialog?.open || root.querySelector('[data-play-wizard]')?.open || coinDialog?.open || otDialog?.open || quarterDialog?.open || penaltyDialog?.open || injuryDialog?.open || personnelDialog?.open || logDialog?.open || highlightsDialog?.open || boxDialog?.open), final: afterState?.status === 'final' })) {
            callForm.requestSubmit();
        }
        frameId = requestAnimationFrame(animate);
    };
    frameId = requestAnimationFrame(animate);
    let disposed = false;
    const cleanup = () => {
        if (disposed) return;
        disposed = true;
        disposeNavigation();
        document.removeEventListener('visibilitychange', silenceHidden);
        disposeMobileControls();
        audio.dispose();
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
    const disposeNavigation = gameNavigation(root, {
        dispose: cleanup,
        mount: mountPractice,
        freeze: () => {
            renderer.render(scene, camera);
            const cover = document.createElement('canvas');
            cover.width = renderer.domElement.width; cover.height = renderer.domElement.height;
            cover.getContext('2d').drawImage(renderer.domElement, 0, 0);
            const bounds = host.getBoundingClientRect();
            cover.style.cssText = `position:fixed;pointer-events:none;z-index:9999;left:${bounds.left}px;top:${bounds.top}px;width:${bounds.width}px;height:${bounds.height}px`;
            (document.fullscreenElement || document.body).append(cover);
            return cover;
        },
    });
}
