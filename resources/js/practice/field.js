import {POSTGAME_END,exitPose,handshakePose,mascotFarewell,postgameStage} from './postgame.js';
import {queuedPlayCall} from './queued-play-call.js';
import {entranceComplete,hideOpeningUnits} from './opening-presentation.js';
import {isSpecialTeamCall,kickFormation} from './special-teams-options.js';
import {manualGameScreens,screenTimerExpired} from './screen-timers.js';
import {buildResultReferee} from './result-referee.js';
import {idlePlayerPose} from './idle-player.js';
import {mountGameStats} from './game-stats.js';
import {entranceMusic} from './entrance-music.js';
import {entranceSchedule} from './tunnel-entrance.js';
import {buildChampionshipAwayEntourage} from './championship-entourage.js';
import {turnoverCelebrants} from './turnover-group.js';
import {presentationAmbience,playCallVisible} from './presentation-ambience.js';
import {unitExchange,playerKey,exchangeCallAvailability} from './unit-exchange.js';
import {buildSidelineCheerleaders} from './sideline-cheerleaders.js';
import {buildSidelineMascot, mascotBigPlayCue} from './sideline-mascot.js';
import {buildSidelineCoaches} from './coach-model.js';
import {buildSidelinePlayers} from './sideline-players.js';
import {autoNotices} from './auto-notices.js';
import {mountBreakDialogs} from './break-dialogs.js';
import {mountPregameAnthem} from './pregame-anthem.js';
import { showSimProgress } from '../sim-progress.js';
import { gameNavigation } from './game-navigation.js';
import { turnoverMoment, crossedTurnover } from './live-announcement.js';
import { buildStadium } from './stadium.js';
import { samplePreSnapMotion } from './motion.js';
import { mountPlayWizard } from './play-wizard.js';
import { mobileControls } from './mobile-controls.js';
import {gameViewport} from './game-viewport.js';
import { stadiumAudio } from './stadium-audio.js';
import { soundCues, crossedCues, cadenceCues, presentationSoundTime } from './sound-cues.js';
import { fitLogo } from './logo-fit.js';
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { DURATION, samplePlay } from './timeline.js';
import { sampleEnginePlay } from './engine-timeline.js';
import { captureCamera, restoreCamera, cameraPreset, translateCameraAnchor, cameraLimits } from './camera-state.js';
import { canAdvanceCpu } from './cpu-flow.js';
import { ballCarrier, carrierLabel } from './ball-carrier.js';
import { tackleAssistPose } from './tackle-assist.js';
import { buildFootballPlayer, animateFootballPlayer, applyPreSnapStance } from './player-model.js';
import { scoreboardText } from './scoreboard.js';
import { sampleHuddle as regularHuddle, sampleBreakHuddle, sampleNoHuddle } from './huddle.js';
import { penaltyMoment, foulMovement, penaltyAlignment, offsideLateralAlignment, buildPenaltyPresentation } from './penalty-presentation.js';
import { buildPenaltyContact, applyPenaltyContact, resetPenaltyContactPose } from './penalty-contact.js';
import { buildChainGang } from './chain-gang.js';
import { buildReferees, refereeSignal, buildRefereePaths, refereeFormation } from './referees.js';

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
    const sidelineRoster = JSON.parse(root.dataset.sidelineRoster || '{}');
    const nextAnimation = JSON.parse(root.dataset.nextAnimation || 'null');
    const duration = animation?.duration || DURATION;
    slider.max = duration;
    const offenseSide = animation?.possession || 'home';
    const scene = new THREE.Scene();
    scene.fog = new THREE.Fog(0x101a2b, 160, 280);
    const camera = new THREE.PerspectiveCamera(42, 1, 0.1, 350);
    const controls = new OrbitControls(camera, renderer.domElement);
    // Opt-in championship mesh inspector. Shift-click the black line in the
    // browser, then copy the named hit list from the DevTools console.
    // This only attaches for the explicit diagnostic URL parameter.
    if (home.championship_field === true && new URLSearchParams(window.location.search).get('championship_mesh_debug') === '1') {
        const inspectorRay = new THREE.Raycaster();
        const inspectorPoint = new THREE.Vector2();
        renderer.domElement.addEventListener('pointerdown', event => {
            if (!event.shiftKey) return;
            const bounds = renderer.domElement.getBoundingClientRect();
            inspectorPoint.set(((event.clientX-bounds.left)/bounds.width)*2-1,-((event.clientY-bounds.top)/bounds.height)*2+1);
            scene.updateMatrixWorld(true);
            inspectorRay.setFromCamera(inspectorPoint,camera);
            const hits=inspectorRay.intersectObjects(scene.children,true).slice(0,15).map(hit => ({
                name:hit.object.name || '(unnamed)',parent:hit.object.parent?.name || '(unnamed)',
                distance:Math.round(hit.distance*100)/100,
                point:hit.point.toArray().map(v=>Math.round(v*100)/100),
                size:hit.object.geometry?.type || null
            }));
            console.info('[Championship mesh debug] Shift-click hits:',hits);
        });
        console.info('[Championship mesh debug] Enabled: Shift-click the black artifact to inspect its meshes.');
    }
    controls.enableDamping = true;
    controls.maxPolarAngle = Math.PI / 2.1;
    controls.minDistance = 16;
    controls.maxDistance = 180;
    const focus = [animation?.line ?? 40, 0, 26.7];
    const moveAnchor = anchor => {
        const moved = translateCameraAnchor(camera.position.toArray(), controls.target.toArray(), focus, anchor, cameraMode);
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
        Object.assign(controls, cameraLimits(mode));
        if (mode !== 'free') {
            const preset = cameraPreset(mode, focus, cameraDirection);
            controls.target.set(...preset.target);
            camera.position.set(...preset.position);
        }
        controls.update();
        saveCamera();
    };
    let savedCamera;
    try { savedCamera = restoreCamera(JSON.parse(localStorage.getItem(cameraKey)), focus, cameraDirection); } catch { /* Use the default view. */ }
    // Free inspection is only available where the developer camera option is rendered.
    if (savedCamera?.mode === 'free' && !cameraSelect.querySelector('[value=free]')) savedCamera = null;
    if (savedCamera) {
        cameraMode = savedCamera.mode; cameraSelect.value = cameraMode;
        Object.assign(controls, cameraLimits(cameraMode));
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
    const championshipField = home.championship_field === true;
    const endzoneAt = x => championshipField && x === 115 ? away : home;
    for (const x of [5, 115]) {
        const design = endzoneAt(x);
        if (!design.endzone_transparent) addBox(10, 0.03, 53.33, design.endzone_background || 0x174880, x, 0.02, 26.665);
    }
    const field25Position = home.field_25_position || 'both';
    if(home.field_25_logo && field25Position !== 'none') {
        const placements = [
            ['left', 35, 39.8],
            ['right', 85, 13.5],
        ];
        new THREE.TextureLoader().load(home.field_25_logo, texture=>{
            texture.colorSpace=THREE.SRGBColorSpace;
            const size=fitLogo(texture.image.width,texture.image.height,2.35,2.35);
            for(const [side,x,z] of placements) {
                if(field25Position !== 'both' && field25Position !== side)continue;
                const mesh=new THREE.Mesh(
                    new THREE.PlaneGeometry(size.width,size.height),
                    new THREE.MeshBasicMaterial({map:texture,transparent:true,depthWrite:false})
                );
                mesh.name=`field-25-logo-${side}`;
                mesh.rotation.x=-Math.PI/2;
                mesh.position.set(x,.09,z);
                scene.add(mesh);
            }
        });
    }
    if (home.midfield_logo) {
        const logo = new THREE.Mesh(new THREE.PlaneGeometry(14, 14), new THREE.MeshBasicMaterial({map: textureFor(home.midfield_logo), transparent: true, depthWrite: false}));
        logo.rotation.x = -Math.PI / 2; logo.position.set(60, .095, 26.665); scene.add(logo);
    }
    for (const x of [5, 115]) {
        const design = endzoneAt(x);
        const url = championshipField ? design.championship_endzone_art : (x === 5 ? home.endzone_logo_left : home.endzone_logo_right);
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
        context.fillStyle = design.endzone_text_color || '#ffffff';
        context.textAlign = 'center'; context.textBaseline = 'middle';
        context.font = 'bold 100px sans-serif';
        context.fillText(design.endzone_text || design.name || 'FOOTBALL', 512, 80, 960);
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
    const chainGang = buildChainGang(scene, document);
    chainGang.update(animation?.line ?? 40, animation?.firstDown ?? null, JSON.parse(root.dataset.beforeState || '{}').down ?? 1, animation?.direction ?? 1, root.dataset.chainGang !== 'false');
    const referees = buildReferees(scene);
    const refereePaths = buildRefereePaths(animation);
    const stadium = buildStadium(home, document, away, JSON.parse(root.dataset.crowd || '{}'),textureFor);
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
        if (new URLSearchParams(window.location.search).get('jersey_name_debug') === '1') {
            const label = group.getObjectByName('jersey-name');
            if (['WORTHY','THORNTON'].some(last =>
                String(player.lastname || player.name || '').toUpperCase().includes(last))
                || ['1','2'].includes(String(player.number))) {
                console.info('[Jersey name debug]', {
                    name: player.name, lastname: player.lastname, number: player.number,
                    side, nameEnabled: kit.name_enabled, nameColor: kit.name_color,
                    meshPresent: Boolean(label), meshText: label?.userData.text,
                    meshPosition: label?.position.toArray(),
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
    const showCarrierArrow = root.dataset.showCarrierArrow === 'true';
    const showCarrierCircle = root.dataset.showCarrierCircle !== 'false';
    const showBallGlow = root.dataset.showBallGlow === 'true';
    const resultPopup = root.querySelector('[data-result-popup]');
    const beforeState = root.dataset.beforeState ? JSON.parse(root.dataset.beforeState) : null;
    const afterState = root.dataset.afterState ? JSON.parse(root.dataset.afterState) : null;
    // Only a NEW, game-ending injury triggers the sideline medical sequence.
    // Already-injured season players and brief return-snap injuries do not.
    const seriousInjury = (() => {
        if (!animation?.players || !afterState) return null;
        for (const side of ['home', 'away']) {
            const previous = beforeState?.injuries?.[side] ?? {};
            const current = afterState.injuries?.[side] ?? {};
            for (const [id, injury] of Object.entries(current)) {
                if (previous[id] || injury?.return_snap != null || injury?.season_injury_id) continue;
                const track = animation.players.find(p => p.side === side && Number(p.id) === Number(id));
                if (track) return { team: track.team, role: track.role, side, id, name: injury.name || track.name || 'Player' };
            }
        }
        return null;
    })();
    // Low-poly trainer figures, independent from the roster and simulation.
    const medicalCrew = new THREE.Group();
    medicalCrew.visible = false;
    scene.add(medicalCrew);
    // Medical staff wear their team's HOME shirt color, even on the road.
    // The server supplies this from uniform_home_shirt, independent of the
    // current game's home/away uniform assignment.
    const injuredTeamHomeColor = seriousInjury
        ? (appearance?.[seriousInjury.side]?.medical_home_shirt || '#e9e9e7')
        : '#e9e9e7';
    const trainerMaterial = new THREE.MeshStandardMaterial({ color: injuredTeamHomeColor, roughness: .9 });
    const trainerPants = new THREE.MeshStandardMaterial({ color: 0x263649, roughness: .9 });
    const trainerSkin = new THREE.MeshStandardMaterial({ color: 0xb98863, roughness: .95 });
    const makeTrainer = () => {
        const figure = new THREE.Group();
        const part = (geometry, material, x, y, z) => {
            const mesh = new THREE.Mesh(geometry, material);
            mesh.position.set(x, y, z); figure.add(mesh);
        };
        part(new THREE.BoxGeometry(.62, .70, .34), trainerMaterial, 0, 1.26, 0);
        part(new THREE.SphereGeometry(.21, 9, 7), trainerSkin, 0, 1.77, 0);
        for (const sign of [-1, 1]) {
            part(new THREE.BoxGeometry(.20, .62, .23), trainerPants, sign * .14, .57, 0);
            part(new THREE.BoxGeometry(.19, .58, .22), trainerSkin, sign * .32, 1.21, 0);
        }
        figure.scale.setScalar(1.18); // Adult-sized, close to player height.
        medicalCrew.add(figure);
        return figure;
    };
    const trainers = [makeTrainer(), makeTrainer()];
    const treatmentDuration = 7;

    const teamNames = root.dataset.teamNames ? JSON.parse(root.dataset.teamNames) : null;
    const manualScreens = manualGameScreens({exhibition:root.hasAttribute('data-exhibition'),cpuOnly:root.dataset.cpuOnly});
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
    const cues = soundCues(animation, beforeState, afterState, JSON.parse(root.dataset.penalty || 'null'));
    const quarterbackCues = cadenceCues(animation);
    let cadenceTime = -4;
    const ruling = refereeSignal(animation, beforeState, afterState);
    const resultReferee = buildResultReferee(root.querySelector('[data-result-popup]'),animation,beforeState,afterState);
    const penalty = JSON.parse(root.dataset.penalty || 'null');
    const foul = penaltyMoment(animation, penalty, refereePaths);
    const penaltyContact = buildPenaltyContact(animation, foul);
    const penaltyPresentation = buildPenaltyPresentation(root, scene, penalty, teamNames ?? {}, Boolean(afterState?.penalty_pending));
    let audioTime = -2, mascotCueTime = -2;
    const bigPlayMascotCue = mascotBigPlayCue(animation,beforeState,afterState,penalty);
    const mascotCues = bigPlayMascotCue ? [...cues,bigPlayMascotCue] : cues;
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
    const decidedPenaltyResult = root.dataset.penaltyResult === 'true';
    let phase = decidedPenaltyResult ? 'result' : (resultPopup || root.dataset.substitutionChange === 'true') && root.dataset.autoplay !== 'true' ? 'huddle' : (root.hasAttribute('data-exhibition') && root.dataset.autoplay === 'true' ? (animation?.no_snap ? 'play' : 'liningup') : 'play');
    const lineupDuration = 3;
    let lineupProgress = 0;
    const setDuration = 4;
    let setElapsed = 0;
    let refereePostTime = 0, refereeHuddleTime = 0, refereeResetPath = null;
    let postElapsed = 0, huddleProgress = phase === 'huddle' ? 1 : 0;
    let elapsed = ['huddle','result'].includes(phase) ? duration : 0, running = !decidedPenaltyResult && root.dataset.autoplay === 'true', type = 'pass', speed = 1, lastTime = null, frameId;
    const partialExchange = root.dataset.substitutionChange === 'true';
    let exchange = root.dataset.unitChange === 'true' || partialExchange
        ? unitExchange(sample(type,duration),nextAnimation ? sampleEnginePlay(nextAnimation,0) : null,
            nextLine,root.dataset.nextPossession,nextHomeDirection,sidelineRoster,{partial:partialExchange}) : null;
    let exchangeElapsed = 0, exchangeRate = 1;
    // Carry only presentation positions across a submitted call. Match the saved
    // version so refreshes/replays cannot apply a stale substitution to a snap.
    const personnelKey = cameraKey + '-personnel';
    let previousField = null;
    try {
        const saved = JSON.parse(sessionStorage.getItem(personnelKey));
        sessionStorage.removeItem(personnelKey);
        if (Array.isArray(saved?.players) && (saved.purpose === 'lineup' ? saved.version === afterState?.version : saved.version === beforeState?.version)) previousField = saved;
    } catch { /* Normal presentation remains available without browser storage. */ }
    if (phase === 'huddle' && previousField?.purpose === 'lineup' && nextAnimation) {
        exchange = unitExchange(previousField,sampleEnginePlay(nextAnimation,0),nextLine,root.dataset.nextPossession,nextHomeDirection,sidelineRoster,{partial:true});
        previousField.players.forEach((player,i) => {
            scene.remove(players[i]);
            const team = player.side === 'home' ? home : away;
            players[i] = buildFootballPlayer(player,{...team.uniform,helmet_logo_left:team.helmet_logo_left,helmet_logo_right:team.helmet_logo_right},document,textureFor);
            scene.add(players[i]);
        });
    }
    const nextSnap = animation ? sample(type,0) : null;
    const identities = frame => frame.players.map(playerKey).sort().join('|');
    const preExchange = phase === 'liningup' && previousField && nextSnap && identities(previousField) !== identities(nextSnap)
        ? unitExchange(previousField,nextSnap,animation.line,animation.possession,
            playDirection * (offenseSide === 'home' ? 1 : -1),sidelineRoster,{partial:true}) : null;
    let preExchangeElapsed = 0;
    if (preExchange) phase = 'substituting';
    const preOutgoingPlayers = (preExchange ? previousField.players : []).map(player => {
        const team = player.side === 'home' ? home : away;
        const mesh = buildFootballPlayer(player,{...team.uniform,helmet_logo_left:team.helmet_logo_left,helmet_logo_right:team.helmet_logo_right},document,textureFor);
        mesh.visible = false;scene.add(mesh);return mesh;
    });
    const skipPlayerChange = () => {
        if (phase === 'substituting' && preExchange) {
            exchangeRate = Math.max(1,(preExchange.duration-preExchangeElapsed)/.6);return;
        }
        if (phase !== 'huddle' || !exchange || exchangeElapsed >= exchange.duration) return;
        exchangeRate = Math.max(1,(exchange.duration-exchangeElapsed)/.6);
    };
    const skipExchangeButtons = [...new Set([
        root.querySelector('.game-actions'),root.querySelector('[data-drive-dialog]'),root.querySelector('[data-quarter-dialog]'),
    ].filter(Boolean))].map(host => {
        const button = document.createElement('button');button.type = 'button';
        button.dataset.skipExchange = '';button.textContent = 'Skip player change';
        button.className = 'mt-2 rounded border border-blue-400 px-3 py-2 text-sm text-blue-200';
        button.style.display = 'none';host.append(button);
        button.addEventListener('click', skipPlayerChange);
        return button;
    });
    const turnoverGroup = turnoverCelebrants(animation,sample(type,duration));
    const earlyExit = exchange && turnoverGroup.size
        ? unitExchange({...sample(type,duration),players:sample(type,duration).players.filter(p=>!turnoverGroup.has(playerKey(p)))},null,
            nextLine,root.dataset.nextPossession,nextHomeDirection,sidelineRoster,{partial:partialExchange}) : null;
    let earlyExitElapsed = 0, celebrationSpots = null;
    const incomingPlayers = (nextAnimation?.players || []).map(player => {
        const team = player.side === 'home' ? home : away;
        const mesh = buildFootballPlayer(player,{...team.uniform,helmet_logo_left:team.helmet_logo_left,helmet_logo_right:team.helmet_logo_right},document,textureFor);
        mesh.visible = false;scene.add(mesh);return mesh;
    });
    const sidelineCheerleaders = buildSidelineCheerleaders(scene,appearance,textureFor);
    const sidelineMascot = buildSidelineMascot(scene,appearance,textureFor,`${cameraKey}:mascot-time`);
    const championshipAwayEntourage = buildChampionshipAwayEntourage(scene,appearance,textureFor);
    const sidelineCoaches = buildSidelineCoaches(scene,appearance,textureFor);
    const sideline = buildSidelinePlayers(scene,sidelineRoster,appearance,document,textureFor);
    // Fast no-huddle transition applies only with a running game clock.
    // A stopped clock still permits play calling but uses the usual lineup path.
    const wantsHurry=()=>Boolean(afterState?.on_field && afterState.clock_running === true
        && (callForm?.elements.namedItem('tempo')?.value==='hurry' || (!callForm?.elements.namedItem('tempo') && root.dataset.cpuSpecial!=='true' && (afterState.no_huddle || root.dataset.cpuHurry==='true'))));
    let hurryWasActive=null,hurryStart=null,hurryProgress=1,hurryDuration=1.5;
    const sampleHuddle=(frame,line,possession,progress,homeDirection)=>wantsHurry() && nextAnimation
        ? sampleNoHuddle(hurryStart || frame,sampleEnginePlay(nextAnimation,0),hurryStart?hurryProgress:progress)
        : regularHuddle(frame,line,possession,progress,homeDirection);
    const renderState = () => {
        const finalFrame = phase === 'huddle' ? sample(type, duration) : null;
        let frame = finalFrame ? sampleHuddle(finalFrame, nextLine, root.dataset.nextPossession, huddleProgress, nextHomeDirection) : sample(type, elapsed);
        let future = finalFrame ? sampleHuddle(finalFrame, nextLine, root.dataset.nextPossession, Math.min(1, huddleProgress + .02), nextHomeDirection) : sample(type, Math.min(duration, elapsed + 0.03));
        if (earlyExit && ['celebration','result'].includes(phase)) {
            const departing = new Map(earlyExit.sample(earlyExitElapsed).outgoing.map(p=>[playerKey(p),p]));
            const nextDeparting = new Map(earlyExit.sample(earlyExitElapsed+.03).outgoing.map(p=>[playerKey(p),p]));
            frame = {...frame,players:frame.players.map(p=>departing.get(playerKey(p)) ||
                (phase === 'result' && celebrationSpots?.get(playerKey(p)) ? {...p,...celebrationSpots.get(playerKey(p))} : p))};
            future = {...future,players:future.players.map(p=>nextDeparting.get(playerKey(p)) ||
                (phase === 'result' && celebrationSpots?.get(playerKey(p)) ? {...p,...celebrationSpots.get(playerKey(p))} : p))};
        }
        const exchangeFrame = phase === 'huddle' && exchange ? exchange.sample(exchangeElapsed) : null;
        const exchangeFuture = exchangeFrame ? exchange.sample(exchangeElapsed + .03) : null;
        if (exchangeFrame) {
            frame = {...frame,players:exchangeFrame.outgoing,huddle:false,event:exchangeFrame.complete ? (nextAnimation ? 'Incoming units ready · Choose the next play' : 'Game complete · Teams on the sidelines') : (partialExchange ? 'Substitutions · Players jogging between field and sidelines' : 'Changing units · Players jogging between field and sidelines')};
            future = {...future,players:exchangeFuture.outgoing,huddle:false};
        }
        const preFrame = phase === 'substituting' ? preExchange.sample(preExchangeElapsed) : null;
        if (preFrame) {
            frame = {...frame,players:preFrame.incoming,event:'Substitutions · Incoming players joining the huddle'};
            future = {...future,players:preExchange.sample(preExchangeElapsed+.03).incoming};
        }
        const activeSidelinePlayers = new Set();
        preOutgoingPlayers.forEach((mesh,i) => {
            const player = preFrame?.outgoing[i];mesh.visible = Boolean(player?.visible);
            if (!mesh.visible) return;
            const next = preExchange.sample(preExchangeElapsed+.03).outgoing[i];
            activeSidelinePlayers.add(playerKey(player));
            mesh.position.set(player.x,Math.sin(preExchangeElapsed*18+i)*.04,player.z);
            mesh.rotation.y = Math.atan2(next.x-player.x,next.z-player.z);
            animateFootballPlayer(mesh,true,preExchangeElapsed,i);
        });
        frame.players.filter(player => player.visible !== false).forEach(player => activeSidelinePlayers.add(playerKey(player)));
        incomingPlayers.forEach((mesh,i) => {
            const player = exchangeFrame?.incoming[i], next = exchangeFuture?.incoming[i];
            mesh.visible = Boolean(player?.visible);
            if (!mesh.visible) return;
            activeSidelinePlayers.add(playerKey(player));
            const moving = Math.hypot(next.x-player.x,next.z-player.z) > .002;
            mesh.position.set(player.x,moving ? Math.sin(exchangeElapsed*18+i)*.04 : 0,player.z);
            mesh.rotation.y = moving ? Math.atan2(next.x-player.x,next.z-player.z) : Math.atan2(player.facingX-player.x,player.facingZ-player.z);
            animateFootballPlayer(mesh,moving,exchangeElapsed,i);
        });
        sideline.update(activeSidelinePlayers,null,!running && ['result','huddle','set'].includes(phase)?idleTime:null);
        if (phase === 'liningup') {
            const formation = samplePreSnapMotion(sample(type, 0), animation, 0);
            frame = animation?.no_huddle ? sampleNoHuddle(previousField || formation,formation,lineupProgress) : sampleBreakHuddle(formation, animation.line, animation.possession, lineupProgress, playDirection * (offenseSide === 'home' ? 1 : -1));
            future = animation?.no_huddle ? sampleNoHuddle(previousField || formation,formation,Math.min(1,lineupProgress+.02)) : sampleBreakHuddle(formation, animation.line, animation.possession, Math.min(1, lineupProgress + .02), playDirection * (offenseSide === 'home' ? 1 : -1));
        }
        if (phase === 'set') {
            const formation = { ...sample(type, 0), event: `Set · Snap in ${Math.ceil(setDuration - setElapsed)}s` };
            frame = samplePreSnapMotion(formation, animation, setElapsed / 3);
            future = samplePreSnapMotion(formation, animation, (setElapsed + .03) / 3);
        }
        if (beforeState && !revealed && !afterState?.penalty_pending && !['liningup', 'set'].includes(phase) && elapsed >= (animation?.reveal_at ?? Math.min(5.3, duration))) showState(true);
        const motionTime = phase === 'substituting' ? preExchangeElapsed : phase === 'liningup' ? lineupProgress * lineupDuration : earlyExit && ['celebration','result'].includes(phase) ? duration + earlyExitElapsed : exchangeFrame ? duration + exchangeElapsed : phase === 'huddle' ? duration + huddleProgress * 1.5 : phase === 'set' ? setElapsed : elapsed;
        frame.players.forEach((player, i) => {
            const mesh = players[i];
            mesh.visible = player.visible !== false;
            resetPenaltyContactPose(mesh);
            // Reset the articulated stance every frame. Running, throws,
            // tackles, and the QB kneel must never inherit a prior crouch.
            if (mesh.userData.waist) mesh.userData.waist.rotation.x = 0;
            mesh.userData.knees?.forEach(knee => { knee.rotation.x = 0; });

            const next = future.players[i];
            const stepDistance = Math.hypot(next.x - player.x, next.z - player.z);
            // Tiny timeline corrections are pocket shuffles/defensive reads,
            // not full strides. Keep the locomotion animation planted until
            // meaningful travel occurs; actual position paths are untouched.
            const pocketQB = phase === 'play' && animation?.dropback
                && player.team === 'offense' && player.role === 'QB'
                && elapsed < (animation.throw_at ?? 2.2);
            const moving = stepDistance > (pocketQB ? .065 : phase === 'play' && player.team === 'defense' ? .035 : .012);
            mesh.rotation.x = 0; // Clear any previous pre-snap lean before each render.
            mesh.rotation.z = 0;
            mesh.position.set(player.x, moving ? Math.sin(motionTime * 18 + i) * 0.06 : 0, player.z);
            if (phase === 'medical' && seriousInjury) {
                if (player.team === seriousInjury.team && player.role === seriousInjury.role) {
                    // Injured player remains at the dead-ball position.
                    mesh.rotation.z = 1.30;
                    mesh.position.y = .17;
                } else {
                    // Clear the treatment area. Each team gathers loosely on
                    // its own side of the injured player, leaving a central
                    // corridor for medical staff. Purely visual: tracks stay intact.
                    const clearProgress = Math.max(0, Math.min(1, postElapsed / 2.4));
                    const clearEase = clearProgress * clearProgress * (3 - 2 * clearProgress);
                    const teammateSide = player.team === seriousInjury.team ? -1 : 1;
                    const lane = (i % 5) - 2;
                    const rank = Math.floor(i / 5) % 3;
                    const destinationX = frame.ball.x + teammateSide * (8 + rank * 1.4);
                    const destinationZ = Math.max(3, Math.min(50.3, 26.7 + lane * 2.3 + teammateSide * 5));
                    mesh.position.x += (destinationX - mesh.position.x) * clearEase;
                    mesh.position.z += (destinationZ - mesh.position.z) * clearEase;
                    mesh.position.y = 0;
                    mesh.rotation.y = Math.atan2(frame.ball.x - mesh.position.x, frame.ball.z - mesh.position.z);
                }
            }
            if (frame.huddle) mesh.rotation.y = Math.atan2(player.facingX - player.x, player.facingZ - player.z);
            else if ((animation?.dropback || animation?.passing || (!animation && type === 'pass')) && player.role === 'QB' && player.team === 'offense' && phase === 'play' && (animation?.carrier !== 'QB' || elapsed < 2)) mesh.rotation.y = playDirection * Math.PI / 2;
            else if (phase === 'play' && animation?.dropback && !animation.no_snap
                && player.team === 'offense' && ['C', 'LG', 'RG', 'LT', 'RT'].includes(player.role)) {
                // The tiny, sometimes reversing timeline movements are NOT
                // changes of facing for an engaged pass blocker. Keep the
                // shoulders squared upfield while the pocket settles.
                mesh.rotation.y = playDirection * Math.PI / 2;
            }
            else if (phase === 'play' && animation && !animation.no_snap
                && !animation.dropback && ['inside_run', 'outside_run', 'draw', 'two_point_run'].includes(animation.call)
                && player.team === 'defense'
                && /^(DE\d?|DT\d?|NT|EDGE\d?|DL\d?)$/.test(player.role)) {
                // Hold the defensive front square to the offensive line.
                // Turn toward pursuit only after the runner clears the defender.
                const runner = frame.players.find(p => p.team === 'offense' && p.role === animation.carrier);
                const past = runner ? (runner.x - player.x) * playDirection : -1;
                const frontAngle = -playDirection * Math.PI / 2;
                const pursuitAngle = runner
                    ? Math.atan2(runner.x - player.x, runner.z - player.z)
                    : frontAngle;
                const blend = Math.max(0, Math.min(1, (past - .45) / 1.8));
                const eased = blend * blend * (3 - 2 * blend);
                const turn = Math.atan2(Math.sin(pursuitAngle - frontAngle), Math.cos(pursuitAngle - frontAngle));
                mesh.rotation.y = frontAngle + turn * eased;
            }
            else if (moving) mesh.rotation.y = Math.atan2(next.x - player.x, next.z - player.z);
            else if (phase === 'set' || elapsed === 0) mesh.rotation.y = (player.team === 'offense' ? 1 : -1) * playDirection * Math.PI / 2;
            // M15C.3: turn the intended receiver toward the quarterback before
            // the ball arrives, then restore his route-facing direction for YAC.
            // Rotation is visual only; route coordinates and outcome never change.
            if (phase === 'play' && animation?.passing && !animation.no_snap
                && player.team === 'offense' && player.role === animation.receiver_role) {
                const qb = frame.players.find(p => p.team === 'offense' && p.role === 'QB');
                if (qb) {
                    const catchTime = animation.catch_at ?? 3.8;
                    const design = String(animation.design || animation.call || '').toLowerCase();
                    const screen = design.includes('screen');
                    const depth = (player.x - qb.x) * playDirection;
                    const nearGoal = playDirection > 0 ? player.x >= 103 : player.x <= 17;
                    const settle = screen || /curl|hitch|comeback|stick|hook|spot|flat/.test(design)
                        || nearGoal || (player.role.startsWith('TE') && depth < 18);
                    const short = depth < 14 || ['short_pass','slant','two_point_pass'].includes(animation.call);
                    const strength = settle ? 1 : short ? .62 : .26;
                    const smooth = x => { const t=Math.max(0,Math.min(1,x));return t*t*(3-2*t); };
                    const turnIn = smooth((elapsed-(catchTime-1.10))/.70);
                    const turnOut = 1-smooth((elapsed-(catchTime+.20))/.70);
                    const weight = strength*turnIn*turnOut;
                    if (weight > 0) {
                        const qbFacing = Math.atan2(qb.x-player.x,qb.z-player.z);
                        const base = mesh.rotation.y;
                        const delta = Math.atan2(Math.sin(qbFacing-base),Math.cos(qbFacing-base));
                        mesh.rotation.y = base+delta*weight;
                    }
                }
            }
            // The QB must receive the snap before turning for a rushing handoff.
            // Only change his presentation rotation; the saved motion path stays intact.
            if (player.team === 'offense' && player.role === 'QB' && animation
                && animation.carrier === 'RB' && !animation.passing && !animation.no_snap
                && ['liningup', 'set', 'play'].includes(phase)) {
                const facingCenter = playDirection * Math.PI / 2;
                mesh.rotation.y = facingCenter;
                if (phase === 'play' && elapsed > .60 && elapsed < 1.35) {
                    const back = frame.players.find(p => p.team === 'offense' && p.role === 'RB');
                    if (back) {
                        const dx = back.x - player.x;
                        const dz = back.z - player.z;
                        if (Math.hypot(dx, dz) > .1) {
                            const towardBack = Math.atan2(dx, dz);
                            const blend = Math.max(0, Math.min(1, (elapsed - .60) / .24));
                            const ease = blend * blend * (3 - 2 * blend);
                            const shortest = Math.atan2(Math.sin(towardBack - facingCenter), Math.cos(towardBack - facingCenter));
                            mesh.rotation.y = facingCenter + shortest * ease;
                        }
                    }
                } else if (phase === 'play' && elapsed >= 1.35) {
                    // Let the recorded QB path determine his orientation after exchange.
                    mesh.rotation.y = moving ? Math.atan2(next.x - player.x, next.z - player.z) : facingCenter;
                }
            }
            // Coordinate contact around one shared point and fall direction. Individual
            // paths are unchanged until contact; never mutate saved play animation data.
            if (phase === 'play' && animation?.contact_at != null && elapsed >= animation.contact_at
                && animation.tackle_style && animation.tackler_role
                && ((player.team === (animation.contact_carrier_team || 'offense') && player.role === animation.carrier)
                    || (player.team === (animation.contact_tackler_team || 'defense') && player.role === animation.tackler_role))) {
                const carrier = frame.players.find(p => p.team === (animation.contact_carrier_team || 'offense') && p.role === animation.carrier);
                const tackler = frame.players.find(p => p.team === (animation.contact_tackler_team || 'defense') && p.role === animation.tackler_role);
                if (carrier && tackler) {
                    const defender = player.team === (animation.contact_tackler_team || 'defense');
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
            // M15D.5: close support defenders assist in waves; the primary tackler
            // and saved ball/carrier positions remain authoritative.
            if (phase === 'play' && animation?.contact_at != null) {
                const assist = tackleAssistPose(frame, animation, elapsed, player);
                if (assist?.blend > 0) {
                    const distance = Math.hypot(assist.towardX, assist.towardZ);
                    if (distance > .001) {
                        mesh.rotation.y = Math.atan2(assist.towardX, assist.towardZ);
                        mesh.position.x += assist.towardX / distance * assist.displacement;
                        mesh.position.z += assist.towardZ / distance * assist.displacement;
                    }
                    mesh.rotation.z = (player.role.startsWith('LB') ? -.16 : -.12) * assist.blend;
                    mesh.position.y = 0; // No running bob once contact assistance begins.
                }
            }
            const fakeHolder = animation?.fake_kick && animation.design?.startsWith('fake_field_goal') && player.team === 'offense' && player.role === 'QB';
            const kneelingHolder = fakeHolder ? phase === 'set' || (phase === 'play' && elapsed < .5) : animation?.players?.[i]?.pose === 'holder-kneel' && ['set', 'play', 'result'].includes(phase);
            mesh.userData.holderKneel = kneelingHolder;
            if (kneelingHolder) {
                mesh.position.y = -.45;
                mesh.rotation.y = playDirection * Math.PI / 2;
            }
            // Catch/reach at ball arrival, then tuck the ball while turning upfield.
            // No pose is applied to sacks, throwaways, or other receivers.
            const receiving = animation?.receiver_role && player.team === 'offense'
                && player.role === animation.receiver_role && phase === 'play';
            const catchAt = animation?.catch_at ?? 3.8;
            // Presentation-only selection: normal throws use normal catches.
            // With legacy ball tracks (which do not encode throw height), reserve
            // leaping attempts for deeper, contested routes, not open receivers.
            const variant = animation?.animation_variant ?? 0;
            const nearbyDefender = receiving && frame.players.some(defender =>
                defender.team === 'defense' && Math.hypot(defender.x-player.x, defender.z-player.z) < 3.8);
            const airborne = Boolean(receiving && nearbyDefender
                && ['medium_pass','deep_pass'].includes(animation?.call)
                && variant % 3 === 2);
            const failed = ['incomplete', 'interception'].includes(animation?.outcome);
            const reception = receiving && elapsed >= catchAt - .45 && elapsed < catchAt + .32
                ? (airborne ? (failed ? 'jump-reach' : 'jump-catch')
                    : failed ? (variant % 3 === 0 ? 'drop' : 'reach')
                    : nearbyDefender && variant % 3 === 0 ? 'high-catch' : 'catch')
                : receiving && elapsed >= catchAt + .32 && elapsed <= (animation.result_at ?? 5.3)
                    && !failed ? 'tuck' : null;
            // The arm pose and the football use the same saved possession timeline.
            // Don't cradle a ball during the catch itself or after a fumble.
            const heldOnOffense = phase === 'play' && animation && !animation.no_snap
                && frame.ballHolder?.team === 'offense'
                && frame.ballHolder.role === player.role && player.team === 'offense';
            const eligibleCarrier = player.role === 'RB'
                || (animation?.receiver_role && player.role === animation.receiver_role);
            const possessionAt = animation?.receiver_role === player.role ? (animation.catch_at ?? 3.8) : 1;
            mesh.userData.carryingArm = player.role === 'RB' ? 0 : 1;
            mesh.userData.cradlingBall = Boolean(heldOnOffense && eligibleCarrier
                && elapsed >= possessionAt + .16
                && !(animation?.outcome === 'fumble' && elapsed >= (animation.result_at ?? 5.3)));
            // Small pocket positioning changes should not trigger the
            // full running gait. Run plays and every other player are unchanged.
            const plantedPassBlocker = phase === 'play' && animation?.dropback
                && !animation.no_snap && player.team === 'offense'
                && ['C', 'LG', 'RG', 'LT', 'RT'].includes(player.role);
            // Blend reception joints into and out of the catch rather than
            // snapping instantly between a sprint and an overhead reach.
            const ease = x => { const v=Math.max(0,Math.min(1,x)); return v*v*(3-2*v); };
            mesh.userData.receptionBlend = receiving
                ? ease((elapsed-(catchAt-.45))/.20) *
                  (1-ease((elapsed-(catchAt+.20))/.24))
                : 0;
            animateFootballPlayer(mesh, plantedPassBlocker ? false : moving, motionTime, i, (animation?.passing || (!animation && type === 'pass')) && player.role === 'QB' && player.team === 'offense' && phase === 'play' ? (elapsed < .6 ? elapsed : Math.max(.6, elapsed - (animation?.throw_at ?? 2.2) + 2.2)) : null, reception);
            // Smooth takeoff, peak and landing; path and ball remain unchanged.
            if (airborne && phase === 'play') {
                const progress = Math.max(0,Math.min(1,(elapsed-catchAt+.30)/.65));
                const arc = Math.sin(Math.PI*progress);
                mesh.position.y += .30 * arc;
                mesh.userData.legs?.forEach((leg,j) => {
                    leg.rotation.x = (j===0 ? -.38 : .29)*arc;
                });
                mesh.userData.knees?.forEach(knee => { knee.rotation.x = .28*arc; });
            }
            // Pass linemen do not cycle through a running gait while protecting.
            // Their pocket drop is rendered separately below.
            const passLineman = phase === 'play' && animation?.dropback && !animation.no_snap
                && player.team === 'offense' && ['C', 'LG', 'RG', 'LT', 'RT'].includes(player.role);
            if (passLineman) {
                const setting = Math.max(0, Math.min(1, (elapsed - .22) / .65));
                mesh.userData.legs?.forEach((leg, side) => {
                    leg.rotation.x = (side === 0 ? -.13 : .13) * (1 - setting) + .06 * setting;
                });
                mesh.userData.knees?.forEach(knee => { knee.rotation.x = .12; });
            }

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
            const huddlePhase = phase === 'huddle' && !exchangeFrame && !wantsHurry();
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
                // Snap & Handoff v2: hands meet the ball's presentation-only
                // path. Keep the QB's throw arm solver free after snap.
                const runExchange = animation.carrier === 'RB' && !animation.passing;
                const snapPhase = phase === 'play' && elapsed >= .25 && elapsed < .61;
                const exchangePhase = phase === 'play' && elapsed >= .61 && elapsed < 1.05;
                if (player.team === 'offense' && player.role === 'QB') {
                    if (snapPhase) {
                        // Receive with both hands below the chest.
                        mesh.userData.arms?.forEach((arm, j) => {
                            arm.rotation.x = -.83;
                            arm.rotation.z = j === 0 ? -.20 : .20;
                        });
                        mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -1.04; });
                    } else if (runExchange && exchangePhase) {
                        // Present the football at the RB's midsection. The QB
                        // remains facing the play until the exchange completes.
                        mesh.userData.arms?.forEach((arm, j) => {
                            arm.rotation.x = -1.09;
                            arm.rotation.z = j === 0 ? -.18 : .18;
                        });
                        mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.80; });
                    }
                }
                if (player.team === 'offense' && player.role === 'RB' && runExchange
                    && exchangePhase) {
                    // Form the receiving pocket, then leave the existing
                    // high-and-tight carrying animation in charge after 1.05s.
                    mesh.userData.arms?.forEach((arm, j) => {
                        arm.rotation.x = -.95;
                        arm.rotation.z = j === 0 ? -.15 : .15;
                    });
                    mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -1.00; });
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
            // Blocking v1: deterministic hand-fighting, shoulder drive and
            // short lateral steps. This affects meshes only, not play tracks,
            // the ball, outcomes or replays. Special teams keep their own poses.
            const blocking = phase === 'play' && animation && !animation.no_snap
                && !['kickoff', 'onside_kick', 'punt', 'field_goal', 'extra_point', 'spike', 'kneel'].includes(animation.call)
                && (offensiveLine || centerStance || defensiveLine);
            if (blocking) {
                const engage = Math.min(1, Math.max(0, (elapsed - .22) / .35));
                const release = Math.min(1, Math.max(0, ((animation.result_at ?? 5.3) - elapsed) / .5));
                const intensity = engage * release;
                const cadence = elapsed * 10.5 + i * 1.87;
                const stagger = Math.sin(cadence);
                // Extend both arms into opposing shoulder pads, alternating
                // quick punches and resets. Keep the elbows bent.
                mesh.userData.arms?.forEach((arm, index) => {
                    const alternate = index === 0 ? 1 : -1;
                    arm.rotation.x = intensity * (-.95 + .25 * stagger * alternate);
                    arm.rotation.z = (index === 0 ? -.10 : .10)
                        + intensity * (index === 0 ? -.18 : .18);
                });
                mesh.userData.elbows?.forEach((elbow, index) => {
                    elbow.rotation.x = intensity * (-.80 + .18 * Math.sin(cadence + index * Math.PI));
                });
                // Controlled drive and shuffling; the original simulated
                // blocking position and contact timeline remain authoritative.
                mesh.rotation.x += intensity * (-.08 + .035 * stagger);
                if (!passLineman) {
                    mesh.position.z += intensity * .12 * Math.sin(elapsed * 7.5 + i * 2.3);
                    mesh.position.x += playDirection * (player.team === 'offense' ? 1 : -1)
                        * intensity * (.055 + .025 * Math.sin(cadence * .7));
                    mesh.userData.knees?.forEach((knee, index) => {
                        knee.rotation.x += intensity * (.11 + .09 * Math.sin(cadence + index * Math.PI));
                    });
                }
                // Pass protection: the offensive five retreat together and
                // fan outward into a shallow U-shaped pocket around the QB.
                // Keep run blocking exactly as it was. Mesh-only offsets are
                // deterministic and do not affect engine paths or saved plays.
                const passSet = animation.dropback && player.team === 'offense'
                    && (offensiveLine || centerStance);
                if (passSet) {
                    const entry = Math.max(0, Math.min(1, (elapsed - .20) / 1.20));
                    const smoothEntry = entry * entry * (3 - 2 * entry);
                    // The tackles set deep and wide, forming the two visible
                    // corners of the pocket. Guards bridge those corners back
                    // toward the center, which keeps the shortest drop.
                    const depth = { C: .55, LG: 1.10, RG: 1.10, LT: 2.55, RT: 2.55 };
                    const spread = { C: 0, LG: -.58, RG: .58, LT: -1.85, RT: 1.85 };
                    mesh.position.x -= playDirection * (depth[role] ?? 0) * smoothEntry;
                    mesh.position.z += (spread[role] ?? 0) * smoothEntry;
                    // The two tackles turn their shoulders toward incoming
                    // edge rushers instead of remaining in a flat line.
                    const edgeTurn = { LT: -.23, RT: .23 };
                    mesh.rotation.y += (edgeTurn[role] ?? 0) * smoothEntry;
                    // Once the pass set finishes, hold the feet in a blocking
                    // stance instead of using the generic running leg cycle.
                    const settle = Math.max(0, Math.min(1, (elapsed - .35) / .85));
                    const settleEase = settle * settle * (3 - 2 * settle);
                    mesh.userData.legs?.forEach((leg, index) => {
                        leg.rotation.x *= 1 - settleEase;
                        leg.rotation.x += (index === 0 ? -.07 : .07) * settleEase;
                    });
                    mesh.userData.knees?.forEach(knee => {
                        knee.rotation.x = .09 * settleEase;
                    });
                    // Cancel the run-blocking side shuffle for pass protection.
                    // The drop/spread offsets above still form the pocket.
                    mesh.position.z -= intensity * .12 * Math.sin(elapsed * 7.5 + i * 2.3);
                    mesh.rotation.x -= .04 * smoothEntry;
                }
            }
            // Touchdown celebration v3: animate from the recorded final spots.
            // No simulation tracks, results, or field state are changed.
            if (animation?.outcome === 'touchdown' && phase === 'celebration' && !animation.no_snap) {
                const scorerRole = animation.receiver_role && animation.passing
                    ? animation.receiver_role : animation.carrier;
                const scorer = frame.players.find(p => p.team === 'offense' && p.role === scorerRole);
                const smooth = value => {
                    const v = Math.max(0, Math.min(1, value));
                    return v * v * (3 - 2 * v);
                };
                if (player.team === 'offense' && scorer) {
                    const isScorer = player.role === scorerRole;
                    // Pick four teammates by stable final-frame proximity; ties
                    // break by role, so seek/replay doesn't change the group.
                    const teammates = frame.players.filter(p => p.team === 'offense' && p.role !== scorerRole)
                        .sort((a, b) => Math.hypot(a.x - scorer.x, a.z - scorer.z)
                            - Math.hypot(b.x - scorer.x, b.z - scorer.z)
                            || a.role.localeCompare(b.role)).slice(0, 4);
                    const index = teammates.findIndex(p => p.role === player.role);
                    if (isScorer || index >= 0) {
                        const delay = isScorer ? 0 : .18 + index * .13;
                        const active = Math.max(0, postElapsed - delay);
                        const cheer = smooth(active / .45);
                        if (!isScorer) {
                            const dx = scorer.x - player.x;
                            const dz = scorer.z - player.z;
                            const distance = Math.hypot(dx, dz);
                            // Allow a realistic jog from distant final positions,
                            // rather than limiting every player to just 3 yards.
                            const angle = (index / 4) * Math.PI * 2;
                            const radius = 1.35;
                            const targetX = scorer.x + Math.cos(angle) * radius;
                            const targetZ = scorer.z + Math.sin(angle) * radius;
                            const toX = targetX - player.x;
                            const toZ = targetZ - player.z;
                            const length = Math.hypot(toX, toZ);
                            const moved = Math.min(length, active * 8.5);
                            const arrival = length < .15 ? 1 : smooth(Math.max(0, (active - length / 8.5) / .25));
                            if (length > .001) {
                                mesh.position.x += toX / length * moved;
                                mesh.position.z += toZ / length * moved;
                                if (moved < length - .1) {
                                    mesh.rotation.y = Math.atan2(toX, toZ);
                                    mesh.userData.legs?.forEach((leg, side) => {
                                        leg.rotation.x = (side === 0 ? 1 : -1) * .38 * Math.sin(active * 12 + index);
                                    });
                                } else {
                                    mesh.rotation.y = Math.atan2(scorer.x - mesh.position.x, scorer.z - mesh.position.z);
                                }
                            }
                            // Interact only after reaching the scorer. Stable
                            // role/clock-based variation avoids replay randomness.
                            const style = (index + (animation.visual_variant ?? 0)) % 4;
                            const raised = arrival * cheer;
                            const pulse = Math.sin(active * (style === 2 ? 6 : 8));
                            const reach = style === 0 ? -1.90 : style === 1 ? -1.15 : style === 2 ? -.85 : -1.60;
                            mesh.userData.arms?.forEach((arm, side) => {
                                // High-five, helmet tap, chest bump or two-hand cheer.
                                arm.rotation.x = (style === 0 && side === 0 ? -.25 : reach) * raised
                                    + .08 * pulse * raised;
                                arm.rotation.z = (side === 0 ? -.24 : .24) * raised;
                            });
                            mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.55 * raised; });
                            if (style === 1) mesh.rotation.x -= .06 * raised;
                            if (style === 2) {
                                mesh.position.y += Math.max(0, pulse) * .17 * raised;
                                mesh.rotation.x -= .08 * raised;
                            } else {
                                mesh.position.y += .05 * Math.max(0, pulse) * raised;
                            }
                        } else {
                            // Four scorer celebrations, selected deterministically
                            // from recorded play details, not Math.random().
                            const style = (animation.visual_variant ?? 0) % 4;
                            const wave = Math.sin(active * (style === 2 ? 6 : 8));
                            mesh.userData.arms?.forEach((arm, side) => {
                                const lifted = style === 0 ? -2.0
                                    : style === 1 ? (side === 0 ? -1.65 : -.65)
                                    : style === 2 ? -1.12 : (side === 0 ? -.70 : -1.85);
                                arm.rotation.x = (lifted + wave * .10) * cheer;
                                arm.rotation.z = (side === 0 ? -.30 : .30) * cheer;
                            });
                            mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.45 * cheer; });
                            if (style === 2) mesh.rotation.x -= .07 * cheer;
                            mesh.position.y += Math.max(0, wave) * (style === 2 ? .16 : .09) * cheer;
                        }
                    }
                } else if (player.team === 'defense') {
                    // Defenders disengage and jog toward the closest sideline.
                    // Each has a slightly different reaction, never teleporting.
                    const delay = .3 + (i % 6) * .11;
                    const active = Math.max(0, postElapsed - delay);
                    const departing = smooth(active / .5);
                    const sideline = player.z < 26.665 ? -3 : 56.33;
                    const dz = sideline - player.z;
                    const step = Math.sign(dz) * Math.min(Math.abs(dz), Math.max(0, active * 4.2));
                    mesh.position.z += step;
                    if (active > 0) {
                        mesh.rotation.y = Math.atan2(0, dz);
                        mesh.userData.legs?.forEach((leg, side) => {
                            leg.rotation.x = (side === 0 ? 1 : -1) * departing * .38 * Math.sin(active * 12 + i);
                        });
                        mesh.userData.arms?.forEach((arm, side) => {
                            arm.rotation.x = (side === 0 ? 1 : -1) * departing * .21 * Math.sin(active * 12 + i);
                        });
                    }
                }
            }
            // Defensive turnover celebration. Use the recovering player's
            // saved ball-holder role, not an assumed interception/fumble role.
            if (phase === 'celebration' && ['interception', 'fumble'].includes(animation?.outcome)
                && !animation.no_snap) {
                const recovery = [...(animation.ballHolders || [])].reverse()
                    .find(holder => holder[1] === 'defense' && holder[2]);
                const leadRole = recovery?.[2] || (animation.outcome === 'interception' ? 'CB1' : 'LB2');
                const lead = frame.players.find(p => p.team === 'defense' && p.role === leadRole);
                const smooth = x => { const v = Math.max(0, Math.min(1, x)); return v * v * (3 - 2 * v); };
                if (lead && player.team === 'defense') {
                    const nearby = frame.players.filter(p => p.team === 'defense' && p.role !== leadRole && turnoverGroup.has(playerKey(p)))
                        .sort((a, b) => Math.hypot(a.x - lead.x, a.z - lead.z)
                            - Math.hypot(b.x - lead.x, b.z - lead.z)
                            || a.role.localeCompare(b.role)).slice(0, 4);
                    const index = nearby.findIndex(p => p.role === player.role);
                    if (player.role === leadRole || index !== -1) {
                        const leader = player.role === leadRole;
                        const delay = leader ? 0 : .15 + index * .13;
                        const time = Math.max(0, postElapsed - delay);
                        let cheer = smooth(time / .45);
                        if (!leader) {
                            const angle = index * Math.PI / 2;
                            const targetX = lead.x + 1.35 * Math.cos(angle);
                            const targetZ = lead.z + 1.35 * Math.sin(angle);
                            const dx = targetX - player.x, dz = targetZ - player.z;
                            const distance = Math.hypot(dx, dz);
                            const travel = Math.min(distance, time * 8.5);
                            if (distance > .001) {
                                mesh.position.x += dx * travel / distance;
                                mesh.position.z += dz * travel / distance;
                                mesh.rotation.y = travel < distance - .1
                                    ? Math.atan2(dx, dz)
                                    : Math.atan2(lead.x - mesh.position.x, lead.z - mesh.position.z);
                            }
                            if (travel < distance - .1) {
                                mesh.userData.legs?.forEach((leg, side) => {
                                    leg.rotation.x = (side === 0 ? 1 : -1) * .38 * Math.sin(time * 12 + index);
                                });
                            }
                            cheer *= distance < .1 ? 1 : smooth((time - distance / 8.5) / .3);
                        }
                        const pulse = Math.sin(time * 8 + index);
                        mesh.userData.arms?.forEach((arm, side) => {
                            arm.rotation.x = (side === 0 ? -1.7 : -1.3) * cheer + pulse * .08 * cheer;
                            arm.rotation.z = (side === 0 ? -.3 : .3) * cheer;
                        });
                        mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.45 * cheer; });
                        mesh.position.y += .09 * Math.max(0, pulse) * cheer;
                    }
                }
            }
            // Individual reaction after a 20+ yard run or reception.
            // No group gathers, and no reaction on touchdowns or turnovers.
            const bigPlay = phase === 'big_play' && animation && !animation.no_snap
                && !['touchdown', 'interception', 'fumble', 'penalty', 'sack', 'incomplete'].includes(animation.outcome)
                && (animation.gain ?? 0) >= 20
                && player.team === 'offense'
                && player.role === (animation.receiver_role && animation.passing
                    ? animation.receiver_role : animation.carrier);
            // After a big gain, only the ballcarrier celebrates.
            // The other offensive players jog toward their upcoming huddle;
            // defenders begin heading toward their sideline.
            if (phase === 'big_play' && animation && !animation.no_snap && !bigPlay) {
                const t = Math.max(0, postElapsed - .18 - (i % 4) * .08);
                const huddleX = Math.max(8, Math.min(112, nextLine - nextDirection * 5));
                const targetX = player.team === 'offense' ? huddleX : player.x;
                const targetZ = player.team === 'offense' ? 26.7
                    : (player.z < 26.7 ? -3 : 56.33);
                const dx = targetX - player.x, dz = targetZ - player.z;
                const distance = Math.hypot(dx, dz);
                const travel = Math.min(distance, t * (player.team === 'offense' ? 5 : 4));
                if (distance > .001 && travel > 0) {
                    mesh.position.x += dx / distance * travel;
                    mesh.position.z += dz / distance * travel;
                    mesh.rotation.y = Math.atan2(dx, dz);
                    mesh.userData.legs?.forEach((leg, side) => {
                        leg.rotation.x = (side === 0 ? 1 : -1) * .32 * Math.sin(t * 11 + i);
                    });
                    mesh.userData.arms?.forEach((arm, side) => {
                        arm.rotation.x = (side === 0 ? 1 : -1) * .18 * Math.sin(t * 11 + i);
                    });
                }
            }
            if (bigPlay) {
                const t = Math.max(0, Math.min(1, postElapsed / .35));
                const ease = t * t * (3 - 2 * t);
                const style = (animation.animation_variant ?? 0) % 3;
                const wave = Math.sin(postElapsed * 8);
                mesh.userData.arms?.forEach((arm, side) => {
                    const reach = style === 0 ? (side === 0 ? -1.8 : -.45)
                        : style === 1 ? -1.5 : (side === 0 ? -.65 : -1.8);
                    arm.rotation.x = reach * ease + .08 * wave * ease;
                    arm.rotation.z = (side === 0 ? -.20 : .20) * ease;
                });
                mesh.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.5 * ease; });
                mesh.userData.legs?.forEach(leg => { leg.rotation.x = .05 * ease; });
                mesh.position.y += Math.max(0, wave) * .09 * ease;
            }
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
            if (['liningup','set','play'].includes(phase)) {
                const blend = phase === 'liningup' ? Math.max(0,Math.min(1,(lineupProgress-.75)/.25)) : 1;
                const alignmentTime = phase === 'play' ? elapsed : 0;
                mesh.position.x += penaltyAlignment(foul, player, alignmentTime, playDirection) * blend;
                mesh.position.z += offsideLateralAlignment(foul, player, alignmentTime) * blend;
            }
            if (['play','penalty','result','medical'].includes(phase) && foul?.dead && player.team === foul.team && player.role === foul.role) {
                const movement = foulMovement(foul, elapsed, playDirection);
                mesh.position.x += movement.x; mesh.position.y += movement.y;
                if (mesh.userData.waist) mesh.userData.waist.rotation.x += movement.lean;
                mesh.userData.arms?.forEach(arm => arm.rotation.x = -.5 * Math.sin(Math.PI * Math.min(1,elapsed/.48)));
            }

        });
        if (phase === 'play') applyPenaltyContact(penaltyContact, elapsed, frame.players, players);
        medicalCrew.visible = phase === 'medical' && Boolean(seriousInjury);
        if (medicalCrew.visible) {
            const injuredIndex = frame.players.findIndex(p => p.team === seriousInjury.team && p.role === seriousInjury.role);
            if (injuredIndex >= 0) {
                const fallen = players[injuredIndex].position;
                const sidelineZ = fallen.z < 26.7 ? -3 : 56.4;
                const progress = Math.min(1, postElapsed / 2.8);
                const ease = progress * progress * (3 - 2 * progress);
                trainers.forEach((trainer, index) => {
                    const targetX = fallen.x + (index === 0 ? -1.05 : 1.05);
                    const targetZ = fallen.z + (index === 0 ? -.70 : .70);
                    trainer.position.set(targetX + (index === 0 ? -1 : 1) * (1 - ease),
                        progress >= 1 ? -.38 : 0,
                        sidelineZ + (targetZ - sidelineZ) * ease);
                    trainer.rotation.y = Math.atan2(fallen.x - trainer.position.x, fallen.z - trainer.position.z);
                    trainer.rotation.z = progress >= 1 ? (index === 0 ? -.23 : .23) : 0;
                });
            }
        }
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
            && !['kickoff', 'onside_kick', 'punt', 'field_goal', 'extra_point'].includes(animation.call)
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
            // The center handles the ball only once the line is set. Once
            // snapped, follow one continuous hand-to-hand trajectory rather
            // than interpolating between unrelated saved track positions.
            const centerSnap = at(center, 0, .48, -.30);
            const qbHands = at(quarterback, 0, 1.19, .43);
            const rbHands = at(runningBack, -.12, 1.14, .42);
            const smooth = t => { const x = Math.max(0, Math.min(1, t)); return x * x * (3 - 2 * x); };
            if ((phase !== 'play' || elapsed < .32) && centerSnap) {
                ball.position.copy(centerSnap);
            } else if (elapsed < .6 && centerSnap && qbHands) {
                ball.position.copy(centerSnap).lerp(qbHands, smooth((elapsed - .32) / .28));
            } else if (runningBack && elapsed < 1.02 && qbHands && rbHands) {
                ball.position.copy(qbHands).lerp(rbHands, smooth((elapsed - .6) / .42));
            }
        }
        const deadBallAt = animation?.result_at ?? animation?.reveal_at ?? 5.3;
        const postPlay = ['big_play', 'celebration', 'result', 'medical', 'penalty'].includes(phase);
        const refereeTime = elapsed + refereePostTime;
        const signalTime = Math.max(0, refereeTime - deadBallAt);
        const poses = phase === 'huddle'
            ? refereeResetPath ? refereeResetPath(refereeHuddleTime) : refereeFormation(nextLine, nextDirection)
            : refereePaths.sample(['liningup', 'set'].includes(phase) ? 0 : refereeTime);
        referees.update({poses, direction: playDirection, signal: ruling, time: signalTime,
            active: phase === 'penalty' || Boolean(ruling) && (postPlay || (phase === 'play' && elapsed >= deadBallAt)),
            visible: Boolean(animation), penalty: phase === 'penalty' ? penalty?.type : null,
            penaltyTime: postElapsed, flagThrow: foul && !['huddle','liningup','set'].includes(phase)
                ? {official: foul.official, time: refereeTime - foul.at} : null});
        penaltyPresentation.update({moment: foul, time: ['huddle','liningup','set'].includes(phase) ? -1 : refereeTime,
            announcing: phase === 'penalty', announcementTime: postElapsed});
        if (['play', 'big_play', 'celebration', 'result'].includes(phase)) moveAnchor(ball.position.toArray());
        const holder = ballCarrier(frame, phase);
        // The mesh's long axis is local X (ball.scale.x = 1.6).
        // Spin a forward pass around that axis, never around Z (end-over-end).
        // Only passes in flight spiral; keep snaps, handoffs and carries steady.
        const spiralFlight = phase === 'play' && animation?.passing
            && elapsed >= (animation.throw_at ?? 2.2) && elapsed <= (animation.catch_at ?? 3.8) + .4
            && !holder;
        ball.rotation.set(spiralFlight ? (elapsed - (animation.throw_at ?? 2.2)) * 28 : 0, 0, 0);
        // Kicks tumble end-over-end instead of spiraling like thrown passes.
        // Keep a kickoff upright on the tee until the foot reaches the ball.
        const kickingPlay = ['kickoff', 'onside_kick', 'punt', 'field_goal', 'extra_point'].includes(animation?.call);
        if (kickingPlay) {
            const launch = 1.2;
            if (['kickoff','onside_kick'].includes(animation.call) && elapsed < launch) {
                ball.rotation.z = Math.PI / 2;
            } else if (phase === 'play' && elapsed >= launch && elapsed <= 3.5) {
                ball.rotation.z = (['kickoff','onside_kick'].includes(animation.call) ? Math.PI / 2 : 0)
                    + (elapsed - launch) * 18;
            }
        }
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
            && !(animation.outcome === 'fumble' && elapsed >= (animation.result_at ?? 5.3))) {
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
                const pickupAt = animation.receiver_role === holder.role ? (animation.catch_at ?? 3.8) : 1;
                const blend = Math.max(0, Math.min(1, (elapsed - pickupAt) / .22));
                const eased = blend * blend * (3 - 2 * blend);
                ball.position.lerp(tuckPosition, eased);
            }
        }
        carrierArrow.visible = showCarrierArrow && Boolean(holder);
        carrierRing.visible = showCarrierCircle && Boolean(holder);
        if (holder) {
            carrierArrow.position.set(holder.x, 3.2, holder.z);
            carrierRing.position.set(holder.x, .12, holder.z);
        }
        ballGlow.visible = showBallGlow && !holder && ['play', 'result'].includes(phase);
        ballGlow.position.copy(ball.position);
        const eventMessage = animation ? frame.event : `${type === 'pass' ? 'Slant pass' : 'Inside run'} · ${frame.event}`;
        const message = holder ? `${eventMessage} · ${carrierLabel(holder)}` : eventMessage;
        if (status.textContent !== message) status.textContent = message;
        if (animation?.no_snap) { carrierArrow.visible = carrierRing.visible = ballGlow.visible = false; }
        slider.value = elapsed;
        root.querySelector('[data-time]').textContent = phase === 'liningup' ? `Forming up · ${(lineupProgress * lineupDuration).toFixed(1)} / ${lineupDuration.toFixed(1)}s` : phase === 'set' ? `Ready · ${(setDuration - setElapsed).toFixed(1)}s` : `${elapsed.toFixed(1)} / ${duration.toFixed(1)}s`;
    };
    const resize = () => {
        const width = Math.max(1, host.clientWidth), height = Math.max(1, host.clientHeight);
        renderer.setSize(width, height, false);
        camera.aspect = width / height; camera.updateProjectionMatrix();
    };
    const disposeViewport = gameViewport(root);
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
        ending.active=ending.done=false;endingPanel.hidden=true;ending.quarterbacks.forEach(e=>e.model.visible=false);stadium.departCrowd(0);
        cadenceTime = 0; audio.stopPlayerVoices();stadium.resetCrowd();sidelineMascot.reset();sidelineCheerleaders.reset();mascotCueTime=-2;
        earlyExitElapsed = 0;celebrationSpots = null;exchangeRate = 1;
        clearTurnoverBanner(); turnoverTime = -1;
        audioTime = -2;
        showState(false);
        refereePostTime = 0; refereeHuddleTime = 0; refereeResetPath = null;
        phase = 'play'; lineupProgress = 0; setElapsed = 0; postElapsed = 0; huddleProgress = 0;
        if (resultPopup) resultPopup.hidden = true;
        moveFocus(animation?.line ?? 40, playDirection);
        scrimmageLine.position.x = animation?.line ?? 40;
        firstDownLine.position.x = animation?.firstDown ?? 50;
        chainGang.update(animation?.line ?? 40, animation?.firstDown ?? null, beforeState?.down ?? 1, playDirection, root.dataset.chainGang !== 'false');
    };
    const huddleView = () => {
        moveFocus(nextLine, nextDirection);
        scrimmageLine.position.x = nextLine;
        firstDownLine.position.x = Math.max(10, Math.min(110, nextLine + nextDirection * Number(root.dataset.nextDistance || 10)));
        chainGang.update(nextLine, firstDownLine.position.x, afterState?.down ?? 1, nextDirection, root.dataset.chainGang !== 'false');
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
    root.querySelector('[data-play-type]')?.addEventListener('change', event => { type = event.target.value; elapsed = 0; audioTime = -2; cadenceTime = 0; audio.stopPlayerVoices(); running = false; playButton.textContent = 'Play'; });
    root.querySelector('[data-speed]').addEventListener('change', event => speed = Number(event.target.value));
    root.querySelector('[data-reset-camera]').addEventListener('click', () => setCamera(cameraMode === 'free' ? 'broadcast' : cameraMode));
    root.querySelector('[data-camera]').addEventListener('change', event => setCamera(event.target.value));
    slider.addEventListener('input', () => { setCpuAuto(false); replayView(); elapsed = Number(slider.value); turnoverTime = elapsed; audioTime = elapsed; mascotCueTime = elapsed; running = false; playButton.textContent = 'Play'; });
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
            const special = call ? isSpecialTeamCall(call.value) : root.dataset.cpuSpecial === 'true';
            callForm.querySelectorAll('[data-formation]').forEach(select => select.parentElement.hidden = special);
        };
        call?.addEventListener('change', refreshCalls); refreshCalls();
        const formation = callForm.querySelector('[name="offense_formation"]');
        const allCalls = call ? [...call.options].map(option => ({value: option.value, text: option.textContent})) : [];
        const phaseCalls = allCalls.length > 0 && allCalls.every(option => ['kickoff','onside_kick','extra_point','two_point_run','two_point_pass'].includes(option.value));
        const formationCalls = JSON.parse(root.dataset.offensiveOptions || '{}');
        const motionField = callForm.querySelector('[name="motion"]');
        const motionChoices = motionField ? [...motionField.options].map(option=>({value:option.value,text:option.textContent})) : [];
        const refreshMotion = () => {
            if (!motionField || !formation) return;
            const packages = {singleback:['RB','WR1','WR2','TE','TE2'],i_form:['RB','FB','WR1','WR2','TE'],goal_line:['RB','FB','WR1','TE','TE2'],empty:['WR1','WR2','WR3','WR4','TE']};
            const names = root.dataset.formationPersonnel ? JSON.parse(root.dataset.formationPersonnel)[formation.value] : null;
            const roles = names ? Object.keys(names) : packages[formation.value] || ['RB','WR1','WR2','WR3','TE'];
            const previous = motionField.value;
            motionField.replaceChildren(...motionChoices.filter(o=>o.value==='none'||roles.includes(o.value)).map(o=>new Option(o.value !== 'none' && names?.[o.value] ? `${o.value} · ${names[o.value]}` : o.text,o.value)));
            motionField.value = [...motionField.options].some(o=>o.value===previous) ? previous : 'none';
        };
        const refreshFormation = () => {
            if (!call || !formation || phaseCalls || !formationCalls[formation.value]) return;
            refreshMotion();
            const selected = call.value;
            const choices = allCalls.filter(option => formationCalls[formation.value].includes(option.value) || (!['punt','field_goal'].includes(formation.value) && ['kickoff','onside_kick','extra_point','two_point_run','two_point_pass','spike','kneel'].includes(option.value)));
            call.replaceChildren(...choices.map(({value,text}) => new Option(text,value)));
            if (choices.some(option => option.value === selected)) call.value = selected;
            refreshCalls();
        };
        formation?.addEventListener('change', refreshFormation); refreshFormation();
        callForm.querySelector('[data-coach-offense]')?.addEventListener('click', () => {
            const plan = JSON.parse(root.dataset.coachOffense); formation.value = kickFormation(plan.call) || plan.formation; refreshFormation(); call.value = plan.call; callForm.querySelector('[name="motion"]').value = plan.motion || 'none'; refreshCalls();
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
    const cpuTimeoutForm=root.querySelector('[data-cpu-timeout-form]');
    let cpuTimeoutSent=false;
    const tickPlayWizard = mountPlayWizard(root, {
        skipPlayerChange,
        exchangeStatus: () => ({
            active: (phase === 'huddle' && Boolean(exchange) && exchangeElapsed < exchange.duration) || phase === 'substituting',
            fastForwarding: exchangeRate > 1,
        }),
    });
    const disposeMobileControls = mobileControls(root);
    root.querySelectorAll('[data-timeout-form]').forEach(form => form.addEventListener('submit', () => { saveCamera(); setCpuAuto(false); form.querySelector('button').disabled = true; }));
    const rememberField = purpose => {
        try {
            const current = exchange ? exchange.sample(exchangeElapsed).incoming : sampleHuddle(sample(type,duration),nextLine,root.dataset.nextPossession,1,nextHomeDirection).players;
            sessionStorage.setItem(personnelKey,JSON.stringify({version:afterState?.version,players:current,purpose}));
        } catch { /* Presentation only; the saved game remains authoritative. */ }
    };
    const queuedNotice = document.createElement('p');
    queuedNotice.className = 'game-call-pending';queuedNotice.hidden = true;queuedNotice.setAttribute('role','status');root.append(queuedNotice);
    const queuedCall = callForm ? queuedPlayCall(callForm, root, {
        ready: () => !document.hidden && (!exchange || phase !== 'huddle' || exchangeElapsed >= exchange.duration),
        onQueued: () => {
            root.classList.remove('game-replay-controls');
            queuedNotice.textContent = 'Play selected · Finishing player change…';queuedNotice.hidden = false;
            playButton.disabled = slider.disabled = true;
            for(const control of root.querySelectorAll('[data-reset],[data-play-type]'))control.disabled=true;
            if(snapButton)snapButton.disabled=true;
        },
        onRelease: () => {rememberField('snap');saveCamera();queuedNotice.textContent='Calling play…';},
    }) : null;
    root.querySelectorAll('form').forEach(form => {
        if (form.querySelector('[name="action"]')?.value === 'lineup') form.addEventListener('submit',()=>rememberField('lineup'));
    });
    callForm?.addEventListener('submit', event => {
        root.classList.remove('game-replay-controls');
        rememberField('snap');
        saveCamera();
        const button = callForm.querySelector('[data-snap]'); button.disabled = true; button.textContent = 'Simulating…';
    });
    const finishResult = () => {
        if (phase !== 'result') return;
        refereeResetPath = refereePaths.reset(duration + refereePostTime, nextLine, nextDirection);
        refereeHuddleTime = 0;
        if (earlyExit) {
            const finalFrame = sample(type,duration);
            exchange = unitExchange({...finalFrame,players:finalFrame.players.map((p,i)=>({...p,x:players[i].position.x,z:players[i].position.z}))},
                nextAnimation ? sampleEnginePlay(nextAnimation,0) : null,nextLine,root.dataset.nextPossession,nextHomeDirection,sidelineRoster);
        }
        resultPopup.hidden = true; phase = 'huddle'; postElapsed = 0; exchangeElapsed = 0; exchangeRate = 1; huddleView();
    };
    root.querySelector('[data-result-ok]')?.addEventListener('click', finishResult);
    const highlightsDialog = root.querySelector('[data-highlights-dialog]');
    root.querySelector('[data-open-highlights]')?.addEventListener('click', () => highlightsDialog.showModal());
    root.querySelector('[data-finish-sim-form]')?.addEventListener('submit', event => {
        if (event.currentTarget.dataset.simPending === 'true') {event.preventDefault();return;}
        const scope = event.submitter?.value || 'game';
        const label = {drive:'current drive',quarter:'current quarter',half:'current half',game:'remainder of the game'}[scope] || 'remainder of the game';
        if (!window.confirm(`Quick Sim the ${label}? The CPU will call the plays.`)) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        event.currentTarget.dataset.simPending = 'true';
        showSimProgress();
        saveCamera(); setCpuAuto(false); root.querySelector('[data-quick-sim-menu]')?.removeAttribute('open');
    });
    root.querySelector('[data-save-highlight-form]')?.addEventListener('submit', () => { saveCamera(); setCpuAuto(false); });
    const gameStats=mountGameStats(root),logDialog=gameStats?.dialog,boxDialog=gameStats?.dialog;
    root.querySelector('[data-open-log]')?.addEventListener('click', () => logDialog.showModal());
    root.querySelector('[data-open-box]')?.addEventListener('click', () => boxDialog.showModal());
    if (root.dataset.showHighlights === 'true') highlightsDialog?.showModal();
    else if (root.dataset.summary === 'true') gameStats?.open('box');
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
    let anthemIntro;
    const entrancePanel=root.querySelector('[data-entrance-panel]');
    const entrance={active:Boolean(showPregame && entrancePanel && ![false,0,'0'].includes(home.stadium?.tunnels)),seconds:0};
    const entranceTiming=entranceSchedule(Boolean(sidelineCheerleaders.group),Boolean(sidelineMascot.model));
    const entranceSound=entranceMusic(audio,entranceTiming.cheerStart);
    const entranceClock=()=>entrance.active?{away:entrance.seconds,home:entrance.seconds-entranceTiming.homeStart,cheer:entrance.seconds-entranceTiming.cheerStart}:null;
    // Replay the opening from an existing saved game without resimulating any plays.
    // This preview is opt-in, so existing replay and navigation behavior is unchanged.
    const previewIntro = appearance.home?.championship_field === true && new URLSearchParams(window.location.search).get('championship_intro') === '1';
    if (previewIntro && entrancePanel && !entrance.active) {
        entrance.active = true;
        entrance.seconds = 0;
    }
    if (appearance.home?.championship_field === true && entrancePanel) {
        const skipAway=document.createElement('button');
        skipAway.type='button';skipAway.textContent='Skip visiting entrance';
        skipAway.className='rounded bg-blue-700 px-4 py-2 ml-2';
        skipAway.addEventListener('click',()=>{if(entrance.active)entrance.seconds=Math.max(entrance.seconds,entranceTiming.cheerStart);});
        entrancePanel.append(skipAway);
    }
    const entranceCamera={position:camera.position.clone(),target:controls.target.clone()};
    const finishEntrance=async()=>{
        if(!entrance.active || entrance.finishing)return;
        entrance.finishing=true;entrancePanel.querySelector('button').disabled=true;
        await entranceSound.finish();
        if(!root.isConnected)return;
        entrance.active=false;entrancePanel.hidden=true;
        camera.position.copy(entranceCamera.position);controls.target.copy(entranceCamera.target);
        if(callForm)callForm.inert=false;
        anthemIntro=mountPregameAnthem(root,pregameDialog,audio,showPregame);
        pregameDialog.showModal();
    };
    entrancePanel?.querySelector('button')?.addEventListener('click',finishEntrance);
    if(entrance.active){
        entrancePanel.hidden=false;if(callForm)callForm.inert=true;
        hideOpeningUnits(players,incomingPlayers,preOutgoingPlayers);
        camera.position.set(60,85,140);controls.target.set(60,0,26.665);
    }else{anthemIntro=mountPregameAnthem(root,pregameDialog,audio,showPregame);if(showPregame)pregameDialog.showModal();else coinDialog?.showModal();}
    const pregameCloseButtons = pregameDialog?.querySelectorAll('[data-pregame-close]');
    root.querySelector('[data-open-lineups]')?.addEventListener('click', () => {
        if (entrance.active || !pregameDialog || pregameDialog.open) return;
        openingPregame = false;
        pregameCloseButtons?.forEach((button, index) => {
            button.textContent = index === 0 ? 'Close Lineups' : 'Return to Game';
        });
        pregameDialog.showModal();
    });
    const closePregame = async () => {
        pregameCloseButtons?.forEach(button => { button.disabled = true; });
        await anthemIntro.finish();
        pregameDialog.close();
        pregameCloseButtons?.forEach(button => { button.disabled = false; });
    };
    pregameCloseButtons?.forEach(button => button.addEventListener('click', closePregame));
    pregameDialog?.addEventListener('cancel', event => { event.preventDefault(); closePregame(); });
    pregameDialog?.addEventListener('close', () => {
        anthemIntro.closed();
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
    const breakDialogs = mountBreakDialogs(root,audio);
    const driveDialog = breakDialogs.driveDialog;
    const driveKey = `${cameraKey}:drive:${root.dataset.playNumber}`;
    let driveShown=false,breakWaiting=false,breakReady=false;
    try {driveShown=sessionStorage.getItem(driveKey)==='shown';}catch{/* Optional persistence. */}
    const penaltyDialog = root.querySelector('[data-penalty-dialog]');
    const startPenaltyAnnouncement = () => {
        phase = 'penalty'; running = false; elapsed = duration; postElapsed = 0;
        if (afterState?.penalty_pending) showState(false);
        if (resultPopup) resultPopup.hidden = true;
        if (penaltyDialog && !penaltyDialog.open) {
            penaltyDialog.showModal(); penaltyDialog.querySelector('button')?.focus();
        }
    };
    const finishPenaltyAnnouncement = () => {
        if (afterState?.penalty_pending) return;
        const playStands = penalty?.accepted === false;
        phase = seriousInjury ? 'medical' : playStands && ruling === 'touchdown' ? 'celebration'
            : playStands && ['interception','fumble'].includes(animation?.outcome) ? 'celebration'
                : playStands && animation?.gain >= 20 && !['penalty','sack','incomplete'].includes(animation?.outcome) ? 'big_play' : 'result';
        postElapsed = 0;
        if (resultPopup) resultPopup.hidden = phase !== 'result';
        if (penaltyDialog?.open) penaltyDialog.close();
    };
    penaltyDialog?.addEventListener('cancel', event => event.preventDefault());
    root.querySelectorAll('[data-penalty-form]').forEach(form => form.addEventListener('submit', event => {
        if (penaltyDialog?.dataset.saving === 'true') {event.preventDefault();return;}
        if (penaltyDialog) penaltyDialog.dataset.saving = 'true';
        root.querySelector('[data-penalty-saving]')?.removeAttribute('hidden');
        root.querySelectorAll('[data-penalty-form] button').forEach(button => button.disabled = true);
        saveCamera(); setCpuAuto(false);
    }));
    const quarterKey = `${cameraKey}:quarter:${root.dataset.playNumber}`;
    let quarterShown = false;
    try { quarterShown = sessionStorage.getItem(quarterKey) === 'shown'; } catch { /* Show it once per page. */ }
    const showQuarter = () => {
        if (penaltyDialog?.open) return;
        if (injuryDialog && !injuryShown) {
            injuryShown = true; injuryDialog.showModal();
            try { sessionStorage.setItem(injuryKey, 'shown'); } catch { /* Optional persistence. */ }
            return;
        }
        if (injuryDialog?.open) return;
        if (driveDialog?.open) return;
        const pendingBreak = (driveDialog && !driveShown) || (quarterDialog && !quarterShown && quarterDialog.dataset.breakKind);
        if (pendingBreak && !breakReady) {
            if(!breakWaiting){breakWaiting=true;audio.whenReactionFinished().then(()=>{breakWaiting=false;breakReady=true;showQuarter();});}
            return;
        }
        if (driveDialog && !driveShown) {
            driveShown=true;driveDialog.showModal();breakDialogs.startDrive();
            driveDialog.querySelector('form button')?.focus();
            try{sessionStorage.setItem(driveKey,'shown');}catch{/* Optional persistence. */}
            return;
        }
        if (otDialog) { if (!otDialog.open) otDialog.showModal(); return; }
        if (!quarterDialog || quarterShown) return;
        quarterShown = true;
        quarterDialog.showModal();
        breakDialogs.startQuarter();
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
        if (cpuStatus) cpuStatus.textContent = enabled ? 'CPU game running · notices advance automatically' : 'Autoplay off · current play finishes';
        try { sessionStorage.setItem(cpuKey, String(enabled)); } catch { /* Optional persistence. */ }
    };
    const tickNotices = autoNotices([
        {dialog: injuryDialog, seconds: 7},
        {dialog: driveDialog, seconds: 10},
        {dialog: quarterDialog, seconds: quarterDialog?.dataset.breakKind === 'halftime' ? 18 : 12,
            allowed: () => afterState?.status !== 'final'},
    ]);
    if (cpuAuto) setCpuAuto(true);
    cpuToggle?.addEventListener('click', () => setCpuAuto(!cpuAuto));

    coinDialog?.addEventListener('close', () => { if (snapButton) snapButton.disabled = false; });
    if (coinDialog?.open && snapButton) snapButton.disabled = true;
    if (((quarterDialog && !quarterShown) || (driveDialog && !driveShown)) && snapButton) snapButton.disabled = true;
    const ending={active:false,done:false,seconds:0,refs:[],coaches:[],quarterbacks:[],field:new Map(),mascot:null};
    const endingPanel=document.createElement('div');endingPanel.className='game-postgame';endingPanel.hidden=true;
    const endingText=document.createElement('p'),endingSkip=document.createElement('button');
    endingSkip.type='button';endingSkip.textContent='Skip to final stats';endingPanel.append(endingText,endingSkip);root.append(endingPanel);
    const completeEnding=()=>{
        if(!ending.active)return;
        ending.seconds=ending.mascot?POSTGAME_END:55;ending.active=false;ending.done=true;endingPanel.hidden=true;
        playButton.disabled=slider.disabled=false;
        gameStats?.open('box');
    };
    endingSkip.addEventListener('click',completeEnding);
    const startEnding=()=>{
        if(ending.active || ending.done || root.dataset.replayOnly==='true' || root.dataset.summary==='true')return;
        ending.active=true;endingPanel.hidden=false;running=false;setCpuAuto(false);
        resultPopup && (resultPopup.hidden=true);playButton.disabled=slider.disabled=true;
        const finish=sample(type,duration);
        finish.players.forEach((p,i)=>ending.field.set(playerKey(p),{x:players[i].position.x,z:players[i].position.z}));
        chainGang.startDeparture();
        ending.refs=referees.group.children.map(body=>({x:body.position.x,z:body.position.z}));
        ending.coaches=(sidelineCoaches.coaches || []).map(entry=>({...entry,start:{x:entry.coach.position.x,z:entry.coach.position.z}}));
        ending.mascot=sidelineMascot.model?{x:sidelineMascot.model.position.x,z:sidelineMascot.model.position.z}:null;
        for(const side of ['home','away']){
            const track=animation?.players?.find(p=>p.side===side && p.role==='QB');
            const people=sidelineRoster[side] || [];
            const person=people.find(p=>track && p.id===track.id) || people.find(p=>p.depth==='QB1') || people.find(p=>p.position==='QB');
            if(!person)continue;
            const team=appearance[side] || {};
            const model=buildFootballPlayer(person,{...team.uniform,helmet_logo_left:team.helmet_logo_left,helmet_logo_right:team.helmet_logo_right},document,textureFor);
            scene.add(model);
            const index=people.indexOf(person),start=ending.field.get(playerKey({...person,side})) || {x:18+(index%36)*2.3,z:side==='home'?-6:59.33};
            ending.quarterbacks.push({model,side,person,start,index});
        }
        moveFocus(60,1);
        camera.position.set(60,47,84);controls.target.set(60,0,26.7);
    };
    const updateEnding=delta=>{
        if(!ending.active && !ending.done)return;
        if(ending.active && !document.hidden)ending.seconds=Math.min(ending.mascot?POSTGAME_END:55,ending.seconds+Math.min(.1,Math.max(0,delta)));
        const t=ending.seconds;endingText.textContent=!ending.mascot && t>=35?'Thank you for watching · The stadium empties':postgameStage(t);
        hideOpeningUnits(players,incomingPlayers,preOutgoingPlayers);
        ball.visible=carrierArrow.visible=carrierRing.visible=ballGlow.visible=false;
        const apply=(model,pose)=>{model.visible=pose.visible;model.position.set(pose.x,pose.bob || 0,pose.z);model.rotation.y=pose.yaw;};
        chainGang.depart(t);
        referees.crew.forEach((ref,i)=>{
            const pose=exitPose(i%2?'away':'home',i,ending.refs[i],t,0);apply(ref.body,pose);
            ref.legs.forEach((leg,j)=>leg.rotation.x=pose.moving?Math.sin(t*10+j*Math.PI)*.45:0);
            ref.arms.forEach((arm,j)=>arm.rotation.x=pose.moving?Math.sin(t*10+j*Math.PI)*.3:0);
        });
        for(const entry of ending.coaches){
            const pose=entry.coach.name==='coach-head'?handshakePose(entry.side,entry.index,entry.start,t):exitPose(entry.side,entry.index,entry.start,t,14);
            apply(entry.coach,pose);
            const arm=entry.coach.getObjectByName('coach-arm-1');
            if(arm)arm.rotation.x=pose.shake?-1.15+Math.sin(t*7)*.1:pose.moving?Math.sin(t*10)*.35:0;
        }
        const qbKeys=new Set();
        ending.quarterbacks.forEach(entry=>{
            qbKeys.add(playerKey({...entry.person,side:entry.side}));
            const pose=handshakePose(entry.side,entry.index,entry.start,t,'qb');apply(entry.model,pose);
            animateFootballPlayer(entry.model,pose.moving,t,entry.index);
            if(pose.shake && entry.model.userData.arms?.[1])entry.model.userData.arms[1].rotation.x=-1.15+Math.sin(t*7)*.1;
        });
        sideline.update(qbKeys,null,null,(side,i,slot)=>{
            const person=sidelineRoster[side][i],start=ending.field.get(playerKey({...person,side})) || slot;
            return exitPose(side,i,start,t,14+(i%8)*.25);
        });
        sidelineCheerleaders.update(0,null,(i,slot)=>exitPose('home',i,slot,t,20+i*.12));
        if(sidelineMascot.model && ending.mascot){
            const pose=mascotFarewell(ending.mascot,t);apply(sidelineMascot.model,pose);
            if(pose.wave){sidelineMascot.model.rotation.y=Math.PI;const arm=sidelineMascot.model.getObjectByName('mascot-arm-1');if(arm)arm.rotation.x=-2.4+Math.sin(t*5)*.3;}
        }
        stadium.departCrowd(Math.min(1,t/(ending.mascot?75:50)));
        if(t>=(ending.mascot?POSTGAME_END:55) && ending.active)completeEnding();
    };
    quarterDialog?.addEventListener('close', () => { playButton.textContent = 'Replay'; if (snapButton) snapButton.disabled = false; if (afterState?.status === 'final' && !afterState?.penalty_pending) startEnding(); });
    driveDialog?.addEventListener('close',()=>{if(snapButton && (!quarterDialog || quarterShown))snapButton.disabled=false;showQuarter();});
    penaltyDialog?.addEventListener('close', () => {if (phase === 'penalty') finishPenaltyAnnouncement();});
    injuryDialog?.addEventListener('close', showQuarter);
    if (decidedPenaltyResult && resultPopup) resultPopup.hidden = false;
    else if (penaltyDialog && root.dataset.autoplay !== 'true') startPenaltyAnnouncement();
    else if ((driveDialog || otDialog || quarterDialog || injuryDialog) && root.dataset.autoplay !== 'true') showQuarter();
    const silenceHidden = () => { if (document.hidden) { audio.setActive(false); audio.setAmbienceActive(false); } };
    document.addEventListener('visibilitychange', silenceHidden);
    let idleTime=0;
    const animate = now => {
        const delta = lastTime === null || document.hidden ? 0 : (now - lastTime) / 1000;
        if (running && phase === 'substituting') {
            preExchangeElapsed = Math.min(preExchange.duration,preExchangeElapsed+Math.min(delta,.1)*exchangeRate);
            if (preExchangeElapsed === preExchange.duration) {phase = 'liningup';lineupProgress = 0;exchangeRate = 1;}
        } else if (running && phase === 'liningup') {
            lineupProgress = Math.min(1, lineupProgress + Math.min(delta, .1) * speed / lineupDuration);
            if (lineupProgress === 1) { phase = 'set'; setElapsed = 0; elapsed = 0; }
        } else if (running && phase === 'set') {
            setElapsed = Math.min(setDuration, setElapsed + delta);
            if (setElapsed === setDuration) { phase = 'play'; elapsed = 0; }
        } else if (running) elapsed = Math.min(duration, elapsed + Math.min(delta, .1) * speed);
        if (['medical', 'big_play', 'celebration', 'result', 'penalty'].includes(phase)) refereePostTime += Math.min(delta, .1);
        if (earlyExit && ['celebration','result'].includes(phase)) earlyExitElapsed += Math.min(delta,.1);
        if (phase === 'huddle' && refereeResetPath) refereeHuddleTime += Math.min(delta, .1) * exchangeRate;
        if (!entrance.active && phase === 'huddle' && exchange) {
            exchangeElapsed = Math.min(exchange.duration,exchangeElapsed + Math.min(delta,.1) * exchangeRate);
        }
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
            if (foul && resultPopup) {
                startPenaltyAnnouncement();
            } else if (seriousInjury && resultPopup) {
                phase = 'medical'; postElapsed = 0; resultPopup.hidden = true;
            } else if (resultPopup && animation && !animation.no_snap
                && animation.gain >= 20
                && !['touchdown', 'interception', 'fumble', 'penalty', 'sack', 'incomplete'].includes(animation.outcome)) {
                phase = 'big_play'; postElapsed = 0; resultPopup.hidden = true;
            } else if (resultPopup && ['touchdown', 'interception', 'fumble'].includes(animation?.outcome)) {
                // Give the scorer and teammates an unobstructed celebration
                // before opening the result popup over the playing field.
                phase = 'celebration'; postElapsed = 0; resultPopup.hidden = true;
            } else if (resultPopup) { phase = 'result'; postElapsed = 0; resultPopup.hidden = false; }
            else showQuarter();
        } else if (phase === 'penalty') {
            postElapsed += Math.min(delta, .1);
            if (screenTimerExpired({manual:manualScreens,elapsed:postElapsed,seconds:4.5,pending:Boolean(afterState?.penalty_pending),visible:!document.hidden})) finishPenaltyAnnouncement();
        } else if (phase === 'medical') {
            postElapsed += delta;
            if (postElapsed >= treatmentDuration) {
                phase = 'result'; postElapsed = 0;
                if (resultPopup) resultPopup.hidden = false;
            }
        } else if (phase === 'big_play') {
            postElapsed += delta;
            if (postElapsed >= 2.1) {
                phase = 'result'; postElapsed = 0;
                if (resultPopup) resultPopup.hidden = false;
            }
        } else if (phase === 'celebration') {
            postElapsed += delta;
            // On long scores the closest teammates may still be far downfield.
            // Give them enough time to run in before revealing the popup.
            const finish = sample(type, duration);
            const turnover = ['interception', 'fumble'].includes(animation?.outcome);
            const recovery = turnover ? [...(animation.ballHolders || [])].reverse()
                .find(holder => holder[1] === 'defense' && holder[2]) : null;
            const scorerRole = turnover ? (recovery?.[2] || (animation.outcome === 'interception' ? 'CB1' : 'LB2'))
                : animation?.receiver_role && animation?.passing ? animation.receiver_role : animation?.carrier;
            const team = turnover ? 'defense' : 'offense';
            const scorer = finish.players.find(p => p.team === team && p.role === scorerRole);
            const distances = scorer ? finish.players
                .filter(p => p.team === team && p.role !== scorerRole)
                .map(p => ({role: p.role, distance: Math.hypot(p.x - scorer.x, p.z - scorer.z)}))
                .sort((a, b) => a.distance - b.distance || a.role.localeCompare(b.role))
                .slice(0, 4) : [];
            // Match the teammate offsets and 8.5-yard/sec run in the
            // celebration pose. Allow time to arrive and celebrate together.
            const gatheringSeconds = distances.reduce((max, item, index) =>
                Math.max(max, .18 + index * .13 + item.distance / 8.5 + .85), 0);
            const celebrationSeconds = Math.max(4.2, Math.min(16, gatheringSeconds));
            if (postElapsed >= celebrationSeconds) {
                if (earlyExit) celebrationSpots = new Map(sample(type,duration).players.map((p,i)=>[playerKey(p),
                    {x:players[i].position.x,z:players[i].position.z}]));
                phase = 'result'; postElapsed = 0;
                if (resultPopup) resultPopup.hidden = false;
            }
        } else if (phase === 'result') {
            postElapsed += delta;
            if (screenTimerExpired({manual:manualScreens,elapsed:postElapsed,seconds:10,visible:!document.hidden})) {
                finishResult();
            }
        } else if (!entrance.active && phase === 'huddle' && huddleProgress < 1) {
            postElapsed += delta * exchangeRate; huddleProgress = Math.min(1, postElapsed / 1.5);
            if (huddleProgress === 1) showQuarter();
        }
        const audible = !document.hidden && !driveDialog?.open && !quarterDialog?.open && !penaltyDialog?.open && !logDialog?.open && !highlightsDialog?.open && !boxDialog?.open && (breakWaiting || running || ['result','celebration','big_play'].includes(phase) || (phase === 'huddle' && huddleProgress < 1));
        // Keep the stadium bed under play calling, without enabling stale cues.
        const callingPlay = phase === 'huddle' || playCallVisible(callForm);
        const ambience = presentationAmbience({visible: !document.hidden, callingPlay, flagOpen: Boolean(penaltyDialog?.open),
            final: afterState?.status === 'final', recapOpen: Boolean(driveDialog?.open || quarterDialog?.open),
            inspectionOpen: Boolean(logDialog?.open || highlightsDialog?.open || boxDialog?.open)});
        audio.setPlayback(audible, ambience || (ending.active && !document.hidden));
        // A separate cursor keeps pre-snap voices out of result/third-down cues.
        // Replay and seeking begin at the snap and never backfill a cadence.
        const quarterbackTime = phase === 'liningup' ? -setDuration : phase === 'set' ? setElapsed - setDuration : 0;
        if (audible && running) {
            // After a stalled frame, skip old words; the snap still gets its hut.
            crossedCues(quarterbackCues, cadenceTime, quarterbackTime)
                .filter(cue => quarterbackTime - cue.time < .25)
                .forEach(cue => audio.play(cue));
        }
        cadenceTime = quarterbackTime;
        const soundTime = presentationSoundTime(phase,elapsed,duration,postElapsed,audioTime);
        // Mascot reactions are visual and keep working with sound muted.
        const mascotTime=presentationSoundTime(phase,elapsed,duration,postElapsed,mascotCueTime);
        if (!document.hidden && (running || ['result','celebration','big_play'].includes(phase))) {
            crossedCues(mascotCues,mascotCueTime,mascotTime).forEach(cue=>{sidelineMascot.cue(cue);sidelineCheerleaders.cue(cue);});
        }
        mascotCueTime=mascotTime;
        if (audible) {
            if (running || ['result','celebration','big_play'].includes(phase)) crossedCues(cues, audioTime, soundTime).forEach(cue => {stadium.crowdCue(cue);audio.play(cue);});
            audioTime = soundTime;
        } else if (running || ['result','celebration','big_play'].includes(phase)) audioTime = soundTime;
        for (const button of skipExchangeButtons) {
            const visible = (phase === 'huddle' && exchange && exchangeElapsed < exchange.duration) || phase === 'substituting';
            button.style.display = visible ? 'inline-block' : 'none';
            button.disabled = exchangeRate > 1;
            button.textContent = exchangeRate > 1 ? 'Fast-forwarding…' : 'Skip player change';
        }
        tickNotices({delta, enabled: cpuAuto && root.dataset.cpuOnly === 'true', visible: !document.hidden});
        if(cpuTimeoutForm && !cpuTimeoutSent && ((phase==='huddle' && huddleProgress===1) || (root.dataset.playNumber==='0' && !running)) && !document.hidden && !quarterDialog?.open && !driveDialog?.open && !injuryDialog?.open && !penaltyDialog?.open && !logDialog?.open){
            cpuTimeoutSent=true;cpuTimeoutForm.requestSubmit();
        }
        tickPlayWizard?.({delta,...exchangeCallAvailability({phase,exchange,elapsed:exchangeElapsed,entrance:entrance.active,calling:playCallVisible(callForm),huddleReady:huddleProgress===1,initial:root.dataset.playNumber==='0',running})});
        idleTime+=Math.max(0,Math.min(.1,delta));
        stadium.updateCrowd(delta);
        if(entrance.active){entranceSound.tick(entrance.seconds,!document.hidden && !entrance.finishing);entrance.seconds=Math.min(entranceTiming.end,entrance.seconds+Math.min(.1,Math.max(0,delta)));if(entranceComplete({active:entrance.active,finishing:entrance.finishing,seconds:entrance.seconds,end:entranceTiming.end,visible:!document.hidden}))finishEntrance();
            if(entrance.active){const stage=entrance.seconds<entranceTiming.cheerStart?'The visiting team takes the field':entrance.seconds<entranceTiming.leadStart?'The home cheer squad welcomes the team':entrance.seconds<entranceTiming.homeStart?'Here comes the home mascot!':'Here comes the home team!';entrancePanel.querySelector('p').textContent=entrance.seconds>=entranceTiming.end?'Both teams are ready — continue to lineups':stage;
                if(entrance.seconds>=entranceTiming.end)entrancePanel.querySelector('button').textContent='Continue → Lineups';}
}
        sidelineCoaches.update(delta,entranceClock());
        if(!document.hidden){sidelineMascot.update(delta,entrance.active?entrance.seconds-entranceTiming.leadStart:null);sidelineCheerleaders.update(delta,entranceClock());
            championshipAwayEntourage.update(delta,entrance.active?entrance.seconds:null);}
        const hurry=wantsHurry();
        if(hurry && hurryWasActive===false && phase==='huddle'){
            const previous=sample(type,duration);
            hurryStart={...previous,players:previous.players.map((p,i)=>({...p,x:players[i].position.x,z:players[i].position.z}))};
            // Estimate actual separation to the next formation, using stable IDs.
            const targetById=new Map(sampleEnginePlay(nextAnimation,0).players.map(p=>[`${p.side}:${p.id ?? p.role}`,p]));
            const farthest=Math.max(0,...hurryStart.players.map(p=>{
                const target=targetById.get(`${p.side}:${p.id ?? p.role}`);
                return target ? Math.hypot(target.x-p.x,target.z-p.z) : 0;
            }));
            hurryDuration=Math.max(2.5,farthest/6.0+0.7);
            hurryProgress=0;
        }
        hurryWasActive=hurry;
        if(hurryStart){hurryProgress=Math.min(1,hurryProgress+Math.min(.1,Math.max(0,delta))/hurryDuration);if(hurryProgress===1 || !hurry)hurryStart=null;}
        renderState();
        if(!entrance.active && !running && ['result','huddle','set'].includes(phase)){players.forEach((model,index)=>idlePlayerPose(model,idleTime,index));}
        ball.visible=!entrance.active;
        if(entrance.active){sideline.update(new Set(),entranceClock());hideOpeningUnits(players,incomingPlayers,preOutgoingPlayers);carrierArrow.visible=carrierRing.visible=ballGlow.visible=false;}
        else if(entrancePanel && entrance.seconds>0){entrance.seconds=0;}
        updateEnding(delta);
        queuedCall?.tick();
        resultReferee.update(document.hidden ? 0 : delta);
        controls.update(); renderer.render(scene, camera);
        onReady(); onReady = () => {};
        if (cpuToggle && callForm && canAdvanceCpu({ enabled: cpuAuto, visible: !document.hidden,
            ready: (!exchange || phase !== 'huddle' || exchangeElapsed >= exchange.duration) && playCallVisible(callForm) && ((phase === 'huddle' && huddleProgress === 1) || (root.dataset.playNumber === '0' && !running)),
            submitting: snapButton.disabled || Boolean(cpuTimeoutForm), dialogOpen: Boolean(entrance.active || breakWaiting || (driveDialog && !driveShown) || driveDialog?.open || pregameDialog?.open || root.querySelector('[data-play-wizard]')?.open || coinDialog?.open || otDialog?.open || quarterDialog?.open || penaltyDialog?.open || injuryDialog?.open || personnelDialog?.open || logDialog?.open || highlightsDialog?.open || boxDialog?.open), final: afterState?.status === 'final' })) {
            callForm.requestSubmit();
        }
        frameId = requestAnimationFrame(animate);
    };
    frameId = requestAnimationFrame(animate);
    let disposed = false;
    const cleanup = (options = {}) => {
        if (disposed) return;
        disposed = true;
        disposeNavigation();
        queuedCall?.dispose();
        document.removeEventListener('visibilitychange', silenceHidden);
        disposeMobileControls();
        disposeViewport();
        audio.dispose(options.handoff === true);
        penaltyPresentation.dispose();
        resultReferee.dispose();
        sidelineMascot.save();
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
