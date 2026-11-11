import test from 'node:test';
import assert from 'node:assert/strict';
import { createReplayPanel } from '../../resources/js/arcade/replay-panel.js';
import { createReplayPlayback } from '../../resources/js/arcade/replay.js';

class Target {
    listeners = new Map();
    addEventListener(type, handler) { const handlers = this.listeners.get(type) ?? new Set(); handlers.add(handler); this.listeners.set(type, handlers); }
    removeEventListener(type, handler) { this.listeners.get(type)?.delete(handler); }
    fire(type, values = {}) {
        const event = { preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; }, ...values };
        for (const handler of [...(this.listeners.get(type) ?? [])]) handler(event);
        return event;
    }
    get listenerCount() { return [...this.listeners.values()].reduce((total, handlers) => total + handlers.size, 0); }
}
class Element extends Target {
    constructor(tag, document) { super(); this.tag = tag; this.ownerDocument = document; this.children = []; this.attributes = {}; this.textContent = ''; this.hidden = false; this.disabled = false; }
    setAttribute(key, value) { this.attributes[key] = value; if (key === 'value') this.value = value; }
    append(...nodes) { nodes.forEach(node => { this.children.push(node); node.parent = this; }); }
    get isConnected() { return this === this.ownerDocument.root || Boolean(this.parent?.isConnected); }
    remove() { this.parent?.children.splice(this.parent.children.indexOf(this), 1); this.parent = null; }
    focus() { this.ownerDocument.activeElement = this; }
    showModal() { this.open = true; }
    close() { this.open = false; this.fire('close'); }
    descendants() { return this.children.flatMap(child => [child, ...child.descendants()]); }
    querySelectorAll() { return this.descendants().filter(node => ['button', 'input', 'select'].includes(node.tag)); }
}
function fixture({ failView = false } = {}) {
    const document = new Target(), window = new Target();
    document.defaultView = window; document.createElement = tag => new Element(tag, document);
    const root = document.root = new Element('main', document);
    const origin = new Element('button', document); root.append(origin); origin.focus();
    const views = [], playbacks = [];
    let opened = 0, closed = 0;
    const panel = createReplayPanel(root, {
        onOpen() { opened++; }, onClose() { closed++; },
        deps: {
            document, window,
            createReplayTimeline(recording) {
                if (recording.invalid) throw new Error('Invalid recording');
                return { recording, duration: 10, sample: time => ({ time, events: [{ type: 'broken_tackle' }], result: time === 10 ? { reason: 'touchdown' } : null }) };
            },
            createReplayPlayback(timeline) { const playback = createReplayPlayback(timeline); playbacks.push(playback); return playback; },
            createReplayView() {
                if (failView) throw new Error('WebGL unavailable');
                const view = { renders: [], resizes: 0, disposed: 0, render(sample) { this.renders.push(sample); }, resize() { this.resizes++; }, setCamera(camera) { this.camera = camera; }, dispose() { this.disposed++; } };
                views.push(view); return view;
            },
        },
    });
    return { panel, root, origin, document, window, views, playbacks,
        get opened() { return opened; }, get closed() { return closed; },
        get dialog() { return root.children.find(node => node.tag === 'dialog'); },
        control(name) { return root.descendants().find(node => node.attributes['data-replay'] === name); },
    };
}

test('replay controls play, pause, scrub, restart, set speed and camera without simulation', () => {
    const f = fixture(); f.panel.open({});
    assert.equal(f.opened, 1); assert.equal(f.panel.active, true);
    assert.deepEqual(f.control('camera').children.map(option => option.textContent), ['Original', 'Broadcast', 'Player Follow', 'Field Level', 'Free Camera']);
    assert.ok(f.dialog.descendants().some(node => node.textContent === 'Free camera: drag to rotate, right-drag to pan, scroll/pinch to zoom.'));
    f.panel.advance(2); assert.equal(f.playbacks[0].time, 2);
    f.control('play').fire('click'); f.panel.advance(2); assert.equal(f.playbacks[0].time, 2);
    f.control('seek').value = '7.5'; f.control('seek').fire('input');
    assert.equal(f.playbacks[0].time, 7.5); assert.equal(f.playbacks[0].playing, false);
    assert.equal(f.control('time').textContent, '0:07.5 / 0:10.0');
    for (const speed of [.25, .5, 1, 2]) { f.control('speed').value = String(speed); f.control('speed').fire('change'); assert.equal(f.playbacks[0].speed, speed); }
    f.control('restart').fire('click'); assert.equal(f.playbacks[0].time, 0); assert.equal(f.playbacks[0].playing, true);
    f.control('camera').value = 'free'; f.control('camera').fire('change'); assert.equal(f.views[0].camera, 'free');
    assert.equal(f.control('reset-camera').hidden, true);
    f.panel.advance(5); assert.equal(f.control('events').textContent, 'Result: touchdown');
    f.control('play').fire('click'); assert.equal(f.playbacks[0].time, 0);
    f.panel.dispose();
});

test('blur and hidden document pause; resize redraws only while open', () => {
    const f = fixture(); f.panel.open({});
    f.window.fire('blur'); assert.equal(f.playbacks[0].playing, false);
    f.control('play').fire('click'); f.document.hidden = true; f.document.fire('visibilitychange'); assert.equal(f.playbacks[0].playing, false);
    f.window.fire('resize'); assert.equal(f.views[0].resizes, 2);
    f.panel.close(); f.window.fire('resize'); assert.equal(f.views[0].resizes, 2);
    assert.equal(f.window.listenerCount, 0); assert.equal(f.document.listenerCount, 0);
});

test('native modal traps focus, Escape closes, and repeated close/dispose is harmless', () => {
    const f = fixture(); f.panel.open({}, { automatic: true });
    const dialog = f.dialog;
    assert.equal(dialog.open, true); assert.equal(f.control('close').textContent, 'Skip replay');
    assert.equal(f.document.activeElement, f.control('close'));
    const backwards = dialog.fire('keydown', { key: 'Tab', shiftKey: true });
    assert.equal(backwards.prevented, true); assert.equal(f.document.activeElement, f.control('camera'));
    dialog.fire('keydown', { key: 'Tab' }); assert.equal(f.document.activeElement, f.control('close'));
    const escape = dialog.fire('keydown', { key: 'Escape' }); assert.equal(escape.stopped, true);
    assert.equal(f.panel.active, false); assert.equal(f.document.activeElement, f.origin);
    assert.equal(dialog.listenerCount, 0); assert.equal(f.views[0].disposed, 1);
    f.panel.close(); f.panel.dispose(); f.panel.dispose(); assert.equal(f.closed, 1);
    assert.throws(() => f.panel.open({}), /disposed/);
});

test('invalid replacement preserves replay; valid replacement disposes old view and preserves original focus', () => {
    const f = fixture(); f.panel.open({}); const originalDialog = f.dialog;
    assert.throws(() => f.panel.open({ invalid: true }), /Invalid/);
    assert.equal(f.dialog, originalDialog); assert.equal(f.views[0].disposed, 0);
    f.panel.open({ truncated: true });
    assert.equal(f.views[0].disposed, 1); assert.equal(originalDialog.isConnected, false);
    assert.equal(f.window.listenerCount, 2);
    assert.ok(f.dialog.descendants().some(node => node.textContent.includes('truncated')));
    f.panel.close(); assert.equal(f.document.activeElement, f.origin); assert.equal(f.views[1].disposed, 1);
});

test('renderer failure removes dialog and restores focus without dangling callbacks', () => {
    const f = fixture({ failView: true });
    assert.throws(() => f.panel.open({}), /WebGL unavailable/);
    assert.equal(f.panel.active, false); assert.equal(f.dialog, undefined);
    assert.equal(f.document.activeElement, f.origin); assert.equal(f.window.listenerCount, 0);
});

test('native cancel and programmatic dialog closure release resources', () => {
    const f = fixture(); f.panel.open({}); const cancelled = f.dialog.fire('cancel');
    assert.equal(cancelled.prevented, true); assert.equal(f.views[0].disposed, 1);
    f.panel.open({}); f.dialog.close(); assert.equal(f.views[1].disposed, 1); assert.equal(f.panel.active, false);
});
