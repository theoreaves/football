import { createReplayTimeline, createReplayPlayback } from './replay.js';
import { createReplayView } from './replay-view.js';

const clock = seconds => `${Math.floor(seconds / 60)}:${(seconds % 60).toFixed(1).padStart(4, '0')}`;
let nextPanelId = 0;

/** A disposable presentation-only overlay. Never changes simulation state. */
export function createReplayPanel(root, { onOpen = () => {}, onClose = () => {}, deps = {} } = {}) {
    const document = deps.document ?? root.ownerDocument;
    const window = deps.window ?? document.defaultView;
    const timelineFactory = deps.createReplayTimeline ?? createReplayTimeline;
    const playbackFactory = deps.createReplayPlayback ?? createReplayPlayback;
    const viewFactory = deps.createReplayView ?? createReplayView;
    let session = null, disposed = false;
    const element = (tag, text, attributes = {}) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value);
        return node;
    };
    function close({ restoreFocus = true } = {}) {
        const current = session;
        if (!current) return;
        session = null;
        current.playback.pause();
        current.cleanup.forEach(remove => remove());
        try { current.view?.dispose(); } finally {
            if (current.dialog.open) current.dialog.close();
            current.dialog.remove();
            if (restoreFocus && current.returnFocus?.isConnected) current.returnFocus.focus();
            onClose();
        }
    }
    function pause() {
        if (!session) return;
        session.playback.pause();
        session.update();
    }
    function open(recording, { automatic = false } = {}) {
        if (disposed) throw new Error('This replay panel has been disposed.');
        // Reject malformed imports without disturbing the replay already on screen.
        const timeline = timelineFactory(recording);
        const playback = playbackFactory(timeline);
        const returnFocus = session?.returnFocus ?? document.activeElement;
        close({ restoreFocus: false });
        const dialog = element('dialog', undefined, { class: 'arcade-replay', 'aria-labelledby': `replay-title-${++nextPanelId}` });
        const header = element('header', undefined, { class: 'arcade-replay-header' });
        header.append(element('h2', automatic ? 'Highlight replay' : 'Replay', { id: `replay-title-${nextPanelId}` }));
        const exit = element('button', automatic ? 'Skip replay' : 'Return to drill', { type: 'button', 'data-replay': 'close' });
        header.append(exit);
        const host = element('div', undefined, { class: 'arcade-replay-field', 'aria-label': 'Recorded drill playback' });
        const controls = element('div', undefined, { class: 'arcade-replay-controls' });
        const toggle = element('button', 'Pause', { type: 'button', 'data-replay': 'play' });
        const restart = element('button', 'Restart', { type: 'button', 'data-replay': 'restart' });
        const seek = element('input', undefined, { type: 'range', min: '0', max: String(timeline.duration), step: '.01', value: '0', 'aria-label': 'Replay timeline', 'data-replay': 'seek' });
        const time = element('output', undefined, { 'aria-label': 'Replay elapsed and total time', 'data-replay': 'time' });
        const speedLabel = element('label', 'Speed ');
        const speed = element('select', undefined, { 'aria-label': 'Playback speed', 'data-replay': 'speed' });
        [.25, .5, 1, 2].forEach(value => speed.append(element('option', `${value}×`, { value: String(value) })));
        speed.value = '1'; speedLabel.append(speed);
        const cameraLabel = element('label', 'Camera ');
        const camera = element('select', undefined, { 'aria-label': 'Replay camera', 'data-replay': 'camera' });
        [['original', 'Original'], ['broadcast', 'Broadcast'], ['follow', 'Player Follow'], ['field', 'Field Level'], ['free', 'Free Camera']]
            .forEach(([value, label]) => camera.append(element('option', label, { value })));
        cameraLabel.append(camera);
        const reset = element('button', 'Reset camera', { type: 'button', 'data-replay': 'reset-camera' });
        const notice = element('p', timeline.recording.truncated ? 'This recording was truncated. Some moments may be missing.' : 'Recorded playback. Your drill result is unchanged.', { class: 'arcade-replay-notice' });
        const events = element('p', '', { class: 'arcade-replay-events', 'aria-live': 'polite', 'data-replay': 'events' });
        controls.append(toggle, restart, seek, time, speedLabel, cameraLabel, reset);
        const cameraHint = element('p', 'Free camera: drag to rotate, right-drag to pan, scroll/pinch to zoom.', { class: 'arcade-replay-notice' });
        dialog.append(header, host, controls, cameraHint, notice, events);
        const cleanup = [];
        const listen = (target, name, handler) => {
            target.addEventListener(name, handler);
            cleanup.push(() => target.removeEventListener(name, handler));
        };
        const current = { dialog, playback, returnFocus, cleanup, view: null, update: null };
        session = current;
        current.update = () => {
            const sample = playback.sample();
            current.view.render(sample);
            toggle.textContent = playback.playing ? 'Pause' : 'Play';
            seek.value = String(playback.time);
            seek.setAttribute('aria-valuetext', `${clock(playback.time)} of ${clock(timeline.duration)}`);
            time.textContent = `${clock(playback.time)} / ${clock(timeline.duration)}`;
            const latest = sample.events?.at(-1);
            const eventText = latest ? String(latest.type ?? latest[2] ?? '').replaceAll('_', ' ') : '';
            const text = sample.result ? `Result: ${sample.result.reason ?? 'Drill completed'}` : eventText;
            if (events.textContent !== text) events.textContent = text;
        };
        try {
            root.append(dialog); dialog.showModal();
            current.view = viewFactory(host, timeline.recording);
            reset.hidden = typeof current.view.resetCamera !== 'function';
            listen(exit, 'click', () => close());
            listen(toggle, 'click', () => {
                if (playback.playing) playback.pause();
                else { if (playback.time >= timeline.duration) playback.restart(); playback.play(); }
                current.update();
            });
            listen(restart, 'click', () => { playback.restart(); playback.play(); current.update(); });
            listen(seek, 'input', () => { playback.pause(); playback.seek(Number(seek.value)); current.update(); });
            listen(speed, 'change', () => { playback.setSpeed(Number(speed.value)); current.update(); });
            listen(camera, 'change', () => { current.view.setCamera(camera.value); current.update(); });
            listen(reset, 'click', () => { current.view.resetCamera?.(); current.update(); });
            listen(dialog, 'cancel', event => { event.preventDefault(); close(); });
            listen(dialog, 'close', () => { if (session === current) close(); });
            listen(dialog, 'keydown', event => {
                event.stopPropagation();
                if (event.key === 'Escape') { event.preventDefault(); close(); return; }
                if (event.key !== 'Tab') return;
                const focusable = [...dialog.querySelectorAll('button, input, select, [tabindex="0"]')].filter(node => !node.disabled && !node.hidden);
                const first = focusable[0], last = focusable.at(-1);
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
            });
            listen(dialog, 'keyup', event => event.stopPropagation());
            listen(window, 'blur', pause);
            listen(document, 'visibilitychange', () => { if (document.hidden) pause(); });
            listen(window, 'resize', () => { current.view.resize(); current.update(); });
            playback.play();
            if (document.hidden) playback.pause();
            current.view.resize(); current.update(); exit.focus(); onOpen();
            return true;
        } catch (error) { close(); throw error; }
    }
    return {
        open, close: () => close(), pause,
        advance(seconds) { if (session) { session.playback.advance(seconds); session.update(); } },
        get active() { return session !== null; },
        dispose() { if (!disposed) { disposed = true; close(); } },
    };
}
