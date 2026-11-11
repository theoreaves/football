import {createDrill, startDrill, pauseDrill, stepDrill, STEP_SECONDS} from './simulation.js';
import {createPassingDrill, stepPassingDrill} from './passing.js';
import {createArcadeView} from './view.js';
import {createArcadeInput} from './input.js';
import {createFixedStepper} from './loop.js';
import {createDrillRecording} from './recording.js';
import {createReplayPanel} from './replay-panel.js';
import {automaticHighlight} from './highlights.js';
import './replay-panel.css';
import './style.css';

let activeDrill = null;
const RESULTS = {touchdown: 'TOUCHDOWN!', tackle: 'TACKLED', out_of_bounds: 'OUT OF BOUNDS', timeout: 'TIME’S UP', incomplete: 'INCOMPLETE', interception: 'INTERCEPTED', sack: 'SACKED'};

export function mountArcade(root) {
    if (!root || root.dataset.arcadeMounted === 'true') return;
    activeDrill?.();
    root.dataset.arcadeMounted = 'true';
    const host = root.querySelector('[data-arcade-field]');
    if (!host) return;
    const payload = JSON.parse(root.dataset.drill), passing = payload.mode === 'passing';
    const scenario = root.querySelector('[data-arcade-defenders]');
    const routes = root.querySelector('[data-arcade-routes]');
    const receiverCount = root.querySelector('[data-arcade-receivers]');
    const tempo = root.querySelector('[data-arcade-speed]');
    const startButton = root.querySelector('[data-arcade-start]');
    const pauseButton = root.querySelector('[data-arcade-pause]');
    const retryButton = root.querySelector('[data-arcade-retry]');
    const recordingButton = root.querySelector('[data-arcade-recording]');
    const replayButton = root.querySelector('[data-arcade-replay]');
    const importButton = root.querySelector('[data-arcade-import]');
    const importFile = root.querySelector('[data-arcade-import-file]');
    const highlights = root.querySelector('[data-arcade-highlights]');
    const status = root.querySelector('[data-arcade-status]');
    const overlay = root.querySelector('[data-arcade-overlay]');
    const heading = root.querySelector('[data-arcade-heading]');
    const detail = root.querySelector('[data-arcade-detail]');
    const carrier = root.querySelector('[data-arcade-carrier]');
    const carrierInfo = root.querySelector('[data-arcade-carrier-info]');
    const yards = root.querySelector('[data-arcade-yards]');
    const time = root.querySelector('[data-arcade-time]');
    const juke = root.querySelector('[data-arcade-juke]');
    const phase = root.querySelector('[data-arcade-phase]');
    const diagnostics = root.querySelector('[data-arcade-diagnostics]');
    const abort = new AbortController();
    let state, recording, view, input, replay, frameId, lastTime = null, disposed = false, lastAnnouncement = '', best = 0, observer, aim = null, diagnosticAt = null, diagnosticFrames = 0, highlightChecked = false, importGeneration = 0;
    const freshState = () => {
        highlightChecked = false; importGeneration++;
        const options = {...payload, defenderCount: Number(scenario.value), speedScale: Number(tempo.value), seed: 20261110};
        if (passing) { options.routeSet = routes.value; options.receivers = payload.receivers.slice(0, Number(receiverCount.value)); }
        const next = passing ? createPassingDrill(options) : createDrill(options);
        recording = createDrillRecording(next, {appearance: payload.appearance});
        return next;
    };
    state = freshState();
    const stepper = createFixedStepper(() => {
        if (replay?.active || state.status !== 'running') return;
        (passing ? stepPassingDrill : stepDrill)(state, input.sample());
        recording.capture(state);
        if (state.status === 'finished') input.clear();
    }, STEP_SECONDS);
    const focusField = () => host.focus({preventScroll: true});
    const start = () => {
        if (replay?.active || state.status === 'running') return;
        if (state.status === 'finished') { input.clear(); state = freshState(); }
        startDrill(state);
        stepper.reset(); lastTime = null; focusField(); updateHud();
    };
    const pause = () => {
        if (state.status !== 'running') return;
        pauseDrill(state); input?.clear(); stepper.reset(); lastTime = null; updateHud();
    };
    const retry = () => { if (replay?.active) return; input.clear(); state = freshState(); start(); };
    function updateHud() {
        const player = state.runner;
        const currentYards = state.result?.yards ?? (player.x - state.startX) * state.direction;
        const cooldown = Math.max(0, player.jukeCooldown || 0);
        carrier.textContent = `#${player.number ?? ''} ${player.name || 'Ball carrier'}`;
        carrierInfo.textContent = `${player.position || (passing ? 'QB' : 'RB')} · SPD ${player.ratings.speed} / ACC ${player.ratings.acceleration}${passing ? ` / THR ${player.ratings.throwing} / CTH ${player.ratings.catching}` : ''}`;
        yards.textContent = `${currentYards >= 0 ? '+' : ''}${Math.round(currentYards)}`;
        time.textContent = `${(state.elapsed || 0).toFixed(1)}s`;
        juke.textContent = cooldown > 0 ? `${cooldown.toFixed(1)}s` : 'READY';
        juke.dataset.ready = String(cooldown === 0);
        pauseButton.disabled = state.status !== 'running';
        recordingButton.disabled = state.status !== 'finished';
        replayButton.disabled = state.status !== 'finished';
        importButton.disabled = state.status === 'running';
        highlights.disabled = state.status === 'running';
        const noun = passing ? 'play' : 'run';
        startButton.textContent = state.status === 'paused' ? `Resume ${noun}` : state.status === 'finished' ? 'Play again' : `Start ${noun}`;
        startButton.hidden = state.status === 'running';
        overlay.hidden = state.status === 'running';
        for (const control of [scenario, routes, receiverCount, tempo]) if (control) control.disabled = state.status === 'running';
        const football = state.ball;
        const liveHint = passing ? football.phase === 'flight' ? 'Ball in flight. Find the window.'
            : football.holderKey === 'qb' ? (state.quarterback.x - state.startX) * state.direction > 0 ? 'Return behind the cyan line to throw, or keep running.' : 'Stay behind the cyan line. Drag toward your target, then release to pass.'
                : 'Caught! You control the receiver. Run right toward the end zone.' : 'Find the gap. Run right toward the end zone.';
        if (phase) phase.textContent = passing ? state.passOutcome === 'complete' ? 'COMPLETE · RUN AFTER CATCH' : football.phase === 'flight' ? 'BALL IN FLIGHT' : 'QB CONTROL · DRAG TO AIM' : 'RUNNING DRILL';
        const resultName = state.result ? RESULTS[state.result.reason] || state.result.reason.toUpperCase() : '';
        const message = state.status === 'finished' ? `${resultName} · ${Math.round(state.result.yards)} yards in ${state.result.time.toFixed(1)} seconds.`
            : state.status === 'paused' ? 'Paused. Resume when you are ready.'
                : state.status === 'running' ? liveHint : `Ready. Start your ${noun}, then ${passing ? 'drag toward a receiver’s route to throw.' : 'use WASD or the arrow keys.'}`;
        if (message !== lastAnnouncement) { status.textContent = message; lastAnnouncement = message; }
        if (state.status === 'finished') {
            best = Math.max(best, state.result.yards);
            heading.textContent = resultName;
            detail.textContent = `${state.passOutcome === 'complete' ? 'Pass complete · ' : ''}${Math.round(state.result.yards)} yards · ${state.result.time.toFixed(1)}s · Best this visit: ${Math.round(best)} yards`;
        } else if (state.status === 'paused') {
            heading.textContent = 'TAKE A BREATHER'; detail.textContent = 'Your play is paused. Nothing moves until you resume.';
        } else {
            heading.textContent = passing ? 'PICK YOUR WINDOW' : 'FIND YOUR GAP';
            detail.textContent = passing ? 'Follow a route. Lead your receiver. Drag toward the target and release.' : `${scenario.value === '2' ? 'Two defenders. ' : 'One defender. '}Thirty yards to the end zone. Make your move.`;
        }
        root.dataset.arcadeState = state.status;
        root.dataset.arcadeOutcome = state.result?.reason || '';
    }
    try {
        view = createArcadeView(host, payload);
        input = createArcadeInput(root, host, {start, pause, retry, enabled: () => !replay?.active,
            canPass: () => passing && state.status === 'running' && state.ball.phase === 'held' && state.ball.holderKey === 'qb',
            aimAt: (x, y) => view.groundPoint(x, y), onAim: point => { aim = point; },
        });
        replay = createReplayPanel(root, {
            onOpen() {
                input.clear(); stepper.reset(); lastTime = null;
                root.dataset.arcadeReplay = 'true';
            },
            onClose() {
                input.clear(); stepper.reset(); lastTime = null;
                delete root.dataset.arcadeReplay;
                diagnosticAt = null; diagnosticFrames = 0; lastAnnouncement = '';
                view.render(state, {aim: null}); updateHud();
            },
        });
        observer = new ResizeObserver(() => view.resize()); observer.observe(host);
    } catch (error) {
        observer?.disconnect(); replay?.dispose(); input?.dispose(); view?.dispose();
        status.textContent = 'The drill needs WebGL. Try a browser with hardware acceleration enabled.';
        heading.textContent = 'FIELD UNAVAILABLE'; detail.textContent = 'Your games and saved data are unchanged.';
        root.querySelectorAll('button').forEach(button => button.disabled = true);
        console.error('[WebSports Arcade] Field initialization failed:', error);
        delete root.dataset.arcadeMounted; return;
    }
    const options = {signal: abort.signal};
    startButton.addEventListener('click', start, options);
    pauseButton.addEventListener('click', pause, options);
    retryButton.addEventListener('click', retry, options);
    for (const control of [scenario, routes, receiverCount, tempo]) control?.addEventListener('change', () => {
        input.clear(); state = freshState(); stepper.reset(); lastTime = null; updateHud();
    }, options);
    recordingButton.addEventListener('click', () => {
        if (state.status !== 'finished') return;
        const blob = new Blob([JSON.stringify(recording.export())], {type: 'application/json'});
        const url = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = url; link.download = `websports-arcade-${passing ? 'passing' : 'running'}-${state.seed}.json`;
        link.click(); setTimeout(() => URL.revokeObjectURL(url), 0);
    }, options);
    const openReplay = (data, automatic = false) => {
        try { replay.open(data, {automatic}); }
        catch (error) { status.textContent = `Replay unavailable: ${error.message}. Your drill is unchanged.`; }
    };
    replayButton.addEventListener('click', () => {
        if (state.status === 'finished') openReplay(recording.export());
    }, options);
    importButton.addEventListener('click', () => {
        if (state.status !== 'running' && !replay.active) importFile.click();
    }, options);
    importFile.addEventListener('change', async () => {
        const file = importFile.files?.[0], request = ++importGeneration, originalState = state;
        importFile.value = '';
        if (!file) return;
        if (file.size > 1024 * 1024) { status.textContent = 'Replay files must be no larger than 1 MiB. Your drill is unchanged.'; return; }
        try {
            const data = await file.text();
            if (disposed || request !== importGeneration || state !== originalState) return;
            if (state.status === 'running') { status.textContent = 'Pause or finish the drill before opening a recording.'; return; }
            openReplay(data);
        } catch { if (!disposed && request === importGeneration) status.textContent = 'Could not read this replay file. Your drill is unchanged.'; }
    }, options);
    root.querySelector('form')?.addEventListener('submit', pause, options);
    const frame = timestamp => {
        if (disposed) return;
        if (!root.isConnected) { dispose(); return; }
        const seconds = lastTime === null ? 0 : (timestamp - lastTime) / 1000;
        lastTime = timestamp;
        if (replay.active) {
            stepper.reset(); replay.advance(seconds);
        } else {
            if (state.status === 'running') stepper.advance(seconds); else stepper.reset();
            view.render(state, {aim}); updateHud();
            if (state.status === 'finished' && !highlightChecked) {
                highlightChecked = true;
                if (highlights.value !== 'off') {
                    const data = recording.export();
                    if (automaticHighlight(data, highlights.value)) openReplay(data, true);
                }
            }
        }
        if (diagnosticAt === null) diagnosticAt = timestamp;
        diagnosticFrames++;
        if (diagnostics && !replay.active && timestamp - diagnosticAt >= 1000) {
            const metrics = view.metrics();
            const fps = Math.round(diagnosticFrames * 1000 / (timestamp - diagnosticAt));
            diagnostics.textContent = `${fps} recent FPS · ${metrics.drawCalls} draw calls · ${metrics.geometries} geometries · ${metrics.textures} textures · ${host.querySelectorAll('canvas').length} canvas · ${metrics.visibleActors} active players${passing ? ` · ${input.aimStatus()}` : ''}`;
            diagnosticAt = timestamp; diagnosticFrames = 0;
        }
        frameId = requestAnimationFrame(frame);
    };
    function dispose() {
        if (disposed) return;
        disposed = true; importGeneration++; cancelAnimationFrame(frameId); replay.dispose(); input.dispose(); observer.disconnect(); abort.abort(); view.dispose();
        delete root.dataset.arcadeMounted;
        if (activeDrill === dispose) activeDrill = null;
    }
    document.addEventListener('livewire:navigating', dispose, options);
    window.addEventListener('pagehide', dispose, options);
    activeDrill = dispose; updateHud(); frameId = requestAnimationFrame(frame);
    return dispose;
}

window.addEventListener('pageshow', event => {
    if (event.persisted) mountArcade(document.querySelector('[data-arcade]'));
});
