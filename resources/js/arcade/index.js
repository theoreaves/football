import {createDrill, startDrill, pauseDrill, stepDrill, STEP_SECONDS} from './simulation.js';
import {createArcadeView} from './view.js';
import {createArcadeInput} from './input.js';
import {createFixedStepper} from './loop.js';
import './style.css';

let activeDrill = null;
const RESULTS = {touchdown: 'TOUCHDOWN!', tackle: 'TACKLED', out_of_bounds: 'OUT OF BOUNDS', timeout: 'TIME’S UP'};

export function mountArcade(root) {
    if (!root || root.dataset.arcadeMounted === 'true') return;
    activeDrill?.();
    root.dataset.arcadeMounted = 'true';
    const host = root.querySelector('[data-arcade-field]');
    if (!host) return;
    const payload = JSON.parse(root.dataset.drill);
    const scenario = root.querySelector('[data-arcade-defenders]');
    const startButton = root.querySelector('[data-arcade-start]');
    const pauseButton = root.querySelector('[data-arcade-pause]');
    const retryButton = root.querySelector('[data-arcade-retry]');
    const status = root.querySelector('[data-arcade-status]');
    const overlay = root.querySelector('[data-arcade-overlay]');
    const heading = root.querySelector('[data-arcade-heading]');
    const detail = root.querySelector('[data-arcade-detail]');
    const yards = root.querySelector('[data-arcade-yards]');
    const time = root.querySelector('[data-arcade-time]');
    const juke = root.querySelector('[data-arcade-juke]');
    const abort = new AbortController();
    let state, view, input, frameId, lastTime = null, disposed = false, lastAnnouncement = '', best = 0, observer;
    const freshState = () => createDrill({...payload, defenderCount: Number(scenario.value), seed: 20261110});
    state = freshState();
    const stepper = createFixedStepper(() => {
        if (state.status === 'running') stepDrill(state, input.sample());
    }, STEP_SECONDS);
    const focusField = () => host.focus({preventScroll: true});
    const start = () => {
        if (state.status === 'finished') state = freshState();
        startDrill(state);
        stepper.reset();
        lastTime = null;
        focusField();
        updateHud();
    };
    const pause = () => {
        if (state.status !== 'running') return;
        pauseDrill(state);
        stepper.reset();
        lastTime = null;
        updateHud();
    };
    const retry = () => {
        input.clear();
        state = freshState();
        start();
    };
    function updateHud() {
        const currentYards = state.result?.yards ?? (state.runner.x - state.startX) * state.direction;
        const cooldown = Math.max(0, state.runner.jukeCooldown || 0);
        yards.textContent = `${currentYards >= 0 ? '+' : ''}${Math.round(currentYards)}`;
        time.textContent = `${(state.elapsed || 0).toFixed(1)}s`;
        juke.textContent = cooldown > 0 ? `${cooldown.toFixed(1)}s` : 'READY';
        juke.dataset.ready = String(cooldown === 0);
        pauseButton.disabled = state.status !== 'running';
        startButton.textContent = state.status === 'paused' ? 'Resume run' : state.status === 'finished' ? 'Run again' : 'Start run';
        startButton.hidden = state.status === 'running';
        overlay.hidden = state.status === 'running';
        scenario.disabled = state.status === 'running';
        const message = state.status === 'finished' ? `${RESULTS[state.result.reason]} · ${Math.round(state.result.yards)} yards in ${state.result.time.toFixed(1)} seconds.`
            : state.status === 'paused' ? 'Paused. Resume when you are ready.'
                : state.status === 'running' ? 'Find the gap. Run right toward the end zone.' : 'Ready. Start your run, then use WASD or the arrow keys.';
        if (message !== lastAnnouncement) {
            status.textContent = message;
            lastAnnouncement = message;
        }
        if (state.status === 'finished') {
            best = Math.max(best, state.result.yards);
            heading.textContent = RESULTS[state.result.reason];
            detail.textContent = `${Math.round(state.result.yards)} yards · ${state.result.time.toFixed(1)}s · Best this visit: ${Math.round(best)} yards`;
        } else if (state.status === 'paused') {
            heading.textContent = 'TAKE A BREATHER';
            detail.textContent = 'Your run is paused. Nothing moves until you resume.';
        } else {
            heading.textContent = 'FIND YOUR GAP';
            detail.textContent = `${scenario.value === '2' ? 'Two defenders. ' : 'One defender. '}Thirty yards to the end zone. Make your move.`;
        }
        root.dataset.arcadeState = state.status;
        root.dataset.arcadeOutcome = state.result?.reason || '';
    }
    try {
        view = createArcadeView(host, payload);
        input = createArcadeInput(root, host, {start, pause, retry});
        observer = new ResizeObserver(() => view.resize());
        observer.observe(host);
    } catch (error) {
        observer?.disconnect();
        input?.dispose();
        view?.dispose();
        status.textContent = 'The drill needs WebGL. Try a browser with hardware acceleration enabled.';
        heading.textContent = 'FIELD UNAVAILABLE';
        detail.textContent = 'Your games and saved data are unchanged.';
        root.querySelectorAll('[data-arcade-start], [data-arcade-pause], [data-arcade-retry]').forEach(button => button.disabled = true);
        console.error('[WebSports Arcade] Field initialization failed:', error);
        delete root.dataset.arcadeMounted;
        return;
    }
    const options = {signal: abort.signal};
    startButton.addEventListener('click', start, options);
    pauseButton.addEventListener('click', pause, options);
    retryButton.addEventListener('click', retry, options);
    scenario.addEventListener('change', () => {
        input.clear(); state = freshState(); stepper.reset(); lastTime = null; updateHud();
    }, options);
    root.querySelector('form')?.addEventListener('submit', pause, options);
    const frame = timestamp => {
        if (disposed) return;
        if (!root.isConnected) { dispose(); return; }
        const seconds = lastTime === null ? 0 : (timestamp - lastTime) / 1000;
        lastTime = timestamp;
        if (state.status === 'running') stepper.advance(seconds);
        else stepper.reset();
        view.render(state);
        updateHud();
        frameId = requestAnimationFrame(frame);
    };
    function dispose() {
        if (disposed) return;
        disposed = true;
        cancelAnimationFrame(frameId);
        input.dispose();
        observer.disconnect();
        abort.abort();
        view.dispose();
        delete root.dataset.arcadeMounted;
        if (activeDrill === dispose) activeDrill = null;
    }
    document.addEventListener('livewire:navigating', dispose, options);
    window.addEventListener('pagehide', dispose, options);
    activeDrill = dispose;
    updateHud();
    frameId = requestAnimationFrame(frame);
    return dispose;
}

window.addEventListener('pageshow', event => {
    if (event.persisted) mountArcade(document.querySelector('[data-arcade]'));
});
