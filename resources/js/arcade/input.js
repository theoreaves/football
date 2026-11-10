const MOVEMENT = new Set(['KeyW', 'KeyA', 'KeyS', 'KeyD', 'ArrowUp', 'ArrowLeft', 'ArrowDown', 'ArrowRight']);
const ACTIONS = new Set([...MOVEMENT, 'KeyQ', 'KeyE', 'Space', 'Enter', 'Escape', 'KeyR']);

export function movementForKeys(keys) {
    return {
        x: Number(keys.has('KeyD') || keys.has('ArrowRight')) - Number(keys.has('KeyA') || keys.has('ArrowLeft')),
        z: Number(keys.has('KeyS') || keys.has('ArrowDown')) - Number(keys.has('KeyW') || keys.has('ArrowUp')),
    };
}

export function editableTarget(target) {
    return Boolean(target?.closest?.('input, select, textarea, [contenteditable="true"]'));
}

export function createArcadeInput(root, host, callbacks) {
    const keys = new Set();
    const abort = new AbortController();
    let juke = 0;
    const clear = () => { keys.clear(); juke = 0; };
    const options = {signal: abort.signal};
    document.addEventListener('keydown', event => {
        if (!root.contains(document.activeElement) || editableTarget(event.target) || !ACTIONS.has(event.code)) return;
        if ((event.code === 'Space' || event.code === 'Enter') && event.target.closest?.('button, a')) return;
        event.preventDefault();
        if (MOVEMENT.has(event.code)) keys.add(event.code);
        if (event.repeat) return;
        if (event.code === 'KeyQ') juke = -1;
        if (event.code === 'KeyE') juke = 1;
        if (event.code === 'Space' || event.code === 'Enter') callbacks.start();
        if (event.code === 'Escape') { clear(); callbacks.pause(); }
        if (event.code === 'KeyR') { clear(); callbacks.retry(); }
    }, options);
    document.addEventListener('keyup', event => keys.delete(event.code), options);
    host.addEventListener('pointerdown', () => host.focus({preventScroll: true}), options);
    root.addEventListener('focusout', event => {
        if (!root.contains(event.relatedTarget) || editableTarget(event.relatedTarget)) {
            clear();
            callbacks.pause();
        }
    }, options);
    window.addEventListener('blur', () => { clear(); callbacks.pause(); }, options);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { clear(); callbacks.pause(); }
    }, options);
    return {
        sample() { const value = {...movementForKeys(keys), juke}; juke = 0; return value; },
        clear,
        dispose() { clear(); abort.abort(); },
    };
}
