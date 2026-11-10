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
    const input = createArcadeInput(root, host, Object.fromEntries(Object.keys(calls).map(key => [key, () => calls[key]++])));
    const key = (code, repeat = false, target = field) => {
        const event = new Event('keydown', {cancelable: true});
        Object.defineProperties(event, {code: {value: code}, repeat: {value: repeat}, target: {value: target}});
        document.dispatchEvent(event);
    };
    try {
        key('KeyD'); key('KeyQ');
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
        document.activeElement = field; key('Space'); key('KeyR');
        assert.equal(calls.start, 1); assert.equal(calls.retry, 1);
        key('KeyD'); document.hidden = true; document.dispatchEvent(new Event('visibilitychange'));
        assert.equal(calls.pause, 4); assert.equal(input.sample().x, 0);
        input.dispose(); key('Space'); window.dispatchEvent(new Event('blur'));
        assert.equal(calls.start, 1); assert.equal(calls.pause, 4);
    } finally {
        input.dispose(); globalThis.document = oldDocument; globalThis.window = oldWindow;
    }
});
