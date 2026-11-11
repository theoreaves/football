import test from 'node:test';
import assert from 'node:assert/strict';
import {movementForKeys, editableTarget, createArcadeInput} from '../../resources/js/arcade/input.js';
import {createFixedStepper} from '../../resources/js/arcade/loop.js';

test('Arcade arrows and WASD combine without multiplying movement and opposite directions cancel', () => {
    assert.deepEqual(movementForKeys(new Set(['KeyD', 'ArrowRight', 'KeyW'])), {x: 1, z: -1});
    assert.deepEqual(movementForKeys(new Set(['KeyD', 'KeyA', 'KeyW', 'KeyS'])), {x: 0, z: 0});
    assert.deepEqual(movementForKeys(new Set()), {x: 0, z: 0});
});

test('Arcade fixed clock produces equal tick counts at 30, 60 and 120 render fps', () => {
    for (const fps of [30, 60, 120]) {
        let ticks = 0;
        const clock = createFixedStepper(() => ticks++);
        for (let frame = 0; frame < fps * 10; frame++) clock.advance(1 / fps);
        assert.equal(ticks, 600);
    }
});

test('Arcade fixed clock discards long pauses and clears fractional time on reset', () => {
    let ticks = 0;
    const clock = createFixedStepper(() => ticks++);
    assert.equal(clock.advance(20), 6);
    clock.advance(1 / 120);
    clock.reset();
    assert.equal(clock.advance(1 / 120), 0);
    assert.equal(clock.advance(1 / 120), 1);
    assert.equal(clock.advance(NaN), 0);
    assert.equal(clock.advance(-1), 0);
    assert.equal(ticks, 7);
});

test('Arcade input ignores editable controls', () => {
    assert.equal(editableTarget({closest: () => ({})}), true);
    assert.equal(editableTarget({closest: () => null}), false);
    assert.equal(editableTarget(null), false);
});


test('input releases held controls on blur, pause, focus loss and disposal; jukes do not auto-repeat', () => {
    const oldDocument = globalThis.document, oldWindow = globalThis.window;
    const document = new EventTarget(), window = new EventTarget(), root = new EventTarget(), host = new EventTarget();
    const field = {closest: () => null}, select = {closest: selector => selector.includes('select') ? {} : null};
    document.activeElement = field;
    root.contains = element => element === field || element === select;
    host.focus = () => { document.activeElement = field; };
    globalThis.document = document; globalThis.window = window;
    const calls = {start: 0, pause: 0, retry: 0};
    let enabled = true;
    const input = createArcadeInput(root, host, {...Object.fromEntries(Object.keys(calls).map(key => [key, () => calls[key]++])), enabled: () => enabled});
    const key = (code, repeat = false, target = field) => {
        const event = new Event('keydown', {cancelable: true});
        Object.defineProperties(event, {code: {value: code}, repeat: {value: repeat}, target: {value: target}});
        document.dispatchEvent(event);
    };
    try {
        enabled = false; key('KeyD'); key('KeyQ'); key('KeyR'); key('Space');
        assert.deepEqual(input.sample(), {x: 0, z: 0, juke: 0});
        assert.equal(calls.start, 0); assert.equal(calls.retry, 0);
        enabled = true; key('KeyD'); key('KeyQ');
        assert.deepEqual(input.sample(), {x: 1, z: 0, juke: -1});
        key('KeyQ', true);
        assert.deepEqual(input.sample(), {x: 1, z: 0, juke: 0});
        window.dispatchEvent(new Event('blur'));
        assert.equal(calls.pause, 1); assert.deepEqual(input.sample(), {x: 0, z: 0, juke: 0});
        key('KeyD'); key('Escape');
        assert.equal(calls.pause, 2); assert.equal(input.sample().x, 0);
        key('KeyD');
        const focusout = new Event('focusout'); Object.defineProperty(focusout, 'relatedTarget', {value: select});
        root.dispatchEvent(focusout);
        assert.equal(calls.pause, 3); assert.equal(input.sample().x, 0);
        document.activeElement = select; key('KeyR', false, select);
        assert.equal(calls.retry, 0);
        document.activeElement = field;
        for (const modifier of ['metaKey', 'ctrlKey', 'altKey']) {
            for (const code of ['KeyR', 'Space', 'KeyD']) {
                const shortcut = new Event('keydown', {cancelable: true});
                Object.defineProperties(shortcut, {code: {value: code}, target: {value: field}, [modifier]: {value: true}});
                document.dispatchEvent(shortcut);
                assert.equal(shortcut.defaultPrevented, false, 'browser shortcuts remain available');
            }
        }
        assert.equal(calls.start, 0); assert.equal(calls.retry, 0); assert.equal(input.sample().x, 0);
        key('Space'); key('KeyR');
        assert.equal(calls.start, 1); assert.equal(calls.retry, 1);
        key('KeyD'); document.hidden = true; document.dispatchEvent(new Event('visibilitychange'));
        assert.equal(calls.pause, 4); assert.equal(input.sample().x, 0);
        input.dispose(); key('Space'); window.dispatchEvent(new Event('blur'));
        assert.equal(calls.start, 1); assert.equal(calls.pause, 4);
    } finally {
        input.dispose(); globalThis.document = oldDocument; globalThis.window = oldWindow;
    }
});

test('drag aiming queues one pass at the release target and cancelled or tiny drags never throw', () => {
    const oldDocument = globalThis.document, oldWindow = globalThis.window;
    const document = new EventTarget(), window = new EventTarget(), root = new EventTarget(), host = new EventTarget();
    const field = {closest: () => null};
    document.activeElement = field; root.contains = element => element === field; host.focus = () => { document.activeElement = field; };
    let captured = null, canPass = true, latestAim = null, pauses = 0;
    host.setPointerCapture = id => { captured = id; };
    host.hasPointerCapture = id => captured === id;
    host.releasePointerCapture = () => { captured = null; };
    globalThis.document = document; globalThis.window = window;
    const input = createArcadeInput(root, host, {start() {}, retry() {}, pause() { pauses++; },
        canPass: () => canPass, aimAt: (x, y) => ({x: x / 2, z: y / 2}), onAim: point => { latestAim = point; }});
    const pointer = (type, x, y, extra = {}) => {
        const event = new Event(type, {cancelable: true});
        Object.entries({clientX: x, clientY: y, pointerId: 7, button: 0, isPrimary: true, ...extra})
            .forEach(([key, value]) => Object.defineProperty(event, key, {value}));
        host.dispatchEvent(event);
    };
    try {
        pointer('pointerdown', 100, 100);
        assert.equal(captured, 7); assert.deepEqual(latestAim, {x: 50, z: 50});
        pointer('pointermove', 160, 70);
        assert.deepEqual(latestAim, {x: 80, z: 35});
        pointer('pointerup', 180, 60);
        assert.equal(captured, null); assert.equal(latestAim, null);
        assert.deepEqual(input.sample().pass, {x: 90, z: 30});
        assert.equal(input.sample().pass, undefined);
        pointer('pointerdown', 100, 100); pointer('pointerup', 102, 102);
        assert.equal(input.sample().pass, undefined, 'clicks do not accidentally throw');
        pointer('pointerdown', 100, 100); pointer('pointermove', 180, 60); pointer('pointercancel', 180, 60);
        pointer('pointerup', 180, 60);
        assert.equal(input.sample().pass, undefined); assert.equal(latestAim, null);
        pointer('pointerdown', 100, 100); pointer('pointermove', 180, 60); pointer('lostpointercapture', 180, 60);
        assert.equal(input.sample().pass, undefined); assert.equal(latestAim, null);
        pointer('pointerdown', 100, 100); pointer('pointerup', 180, 60); window.dispatchEvent(new Event('blur'));
        assert.equal(input.sample().pass, undefined); assert.equal(pauses, 1);
        canPass = false; pointer('pointerdown', 100, 100); pointer('pointerup', 180, 60);
        assert.equal(input.sample().pass, undefined); assert.equal(captured, null);
        canPass = true; pointer('pointerdown', 100, 100, {button: 2}); pointer('pointerup', 180, 60);
        assert.equal(input.sample().pass, undefined);
        pointer('pointerdown', 100, 100); canPass = false; pointer('pointermove', 180, 60);
        assert.equal(latestAim, null); assert.equal(captured, null);
        canPass = true; pointer('pointerdown', 100, 100); input.dispose(); pointer('pointerup', 180, 60);
        assert.equal(captured, null); assert.equal(input.sample().pass, undefined);
    } finally {
        input.dispose(); globalThis.document = oldDocument; globalThis.window = oldWindow;
    }
});
