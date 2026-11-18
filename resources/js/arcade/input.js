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
    const keys = new Set(), abort = new AbortController();
    let juke = 0, pass = null, drag = null, aim = null, gesture = 'No pass gesture yet';
    const updateAim = point => {
        aim = point && Number.isFinite(point.x) && Number.isFinite(point.z) ? {...point} : null;
        callbacks.onAim?.(aim);
    };
    const cancelAim = () => {
        const pointer = drag?.id;
        drag = null;
        if (pointer !== undefined && host.hasPointerCapture?.(pointer)) host.releasePointerCapture(pointer);
        if (pointer !== undefined || aim !== null) updateAim(null);
    };
    const clear = () => { keys.clear(); juke = 0; pass = null; cancelAim(); };
    const canControl = () => callbacks.canControl?.() !== false;
    const canPass = () => callbacks.enabled?.() !== false && canControl() && callbacks.canPass?.();
    const options = {signal: abort.signal};
    document.addEventListener('keydown', event => {
        if (callbacks.enabled?.() === false || event.metaKey || event.ctrlKey || event.altKey || !root.contains(document.activeElement) || editableTarget(event.target) || !ACTIONS.has(event.code)) return;
        if ((event.code === 'Space' || event.code === 'Enter') && event.target.closest?.('button, a')) return;
        if ((MOVEMENT.has(event.code) || event.code === 'KeyQ' || event.code === 'KeyE') && !canControl()) return;
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
    host.addEventListener('pointerdown', event => {
        if (callbacks.enabled?.() === false) return;
        host.focus({preventScroll: true});
        if (event.button !== 0 || event.isPrimary === false || drag || !canPass()) return;
        event.preventDefault();
        gesture = 'Aiming';
        drag = {id: event.pointerId, clientX: event.clientX, clientY: event.clientY};
        host.setPointerCapture?.(event.pointerId);
        updateAim(callbacks.aimAt?.(event.clientX, event.clientY));
    }, options);
    host.addEventListener('pointermove', event => {
        if (!drag || event.pointerId !== drag.id) return;
        if (!canPass()) { cancelAim(); return; }
        drag.clientX = event.clientX; drag.clientY = event.clientY;
        updateAim(callbacks.aimAt?.(event.clientX, event.clientY));
    }, options);
    host.addEventListener('pointerup', event => {
        if (!drag || event.pointerId !== drag.id) return;
        // A stationary release uses the most recently displayed world target.
        // A moving camera must not silently replace it between frames.
        if (event.clientX !== drag.clientX || event.clientY !== drag.clientY) {
            updateAim(callbacks.aimAt?.(event.clientX, event.clientY));
        }
        if (aim && canPass() && event.button === 0 && event.isPrimary !== false) {
            pass = {...aim}; gesture = `Released at ${aim.x.toFixed(1)}, ${aim.z.toFixed(1)}`;
        } else gesture = 'Cancelled aim';
        cancelAim();
    }, options);
    for (const type of ['pointercancel', 'lostpointercapture']) host.addEventListener(type, event => {
        if (drag?.id === event.pointerId) cancelAim();
    }, options);
    root.addEventListener('focusout', event => {
        if (!root.contains(event.relatedTarget) || editableTarget(event.relatedTarget)) {
            clear(); callbacks.pause();
        }
    }, options);
    window.addEventListener('blur', () => { clear(); callbacks.pause(); }, options);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { clear(); callbacks.pause(); }
    }, options);
    return {
        // Called after camera follow is updated and before drawing its indicator.
        refreshAim() {
            if (!drag) return aim;
            if (!canPass()) { cancelAim(); return null; }
            updateAim(callbacks.aimAt?.(drag.clientX, drag.clientY));
            return aim;
        },
        sample() {
            if (!canControl()) { clear(); return {x: 0, z: 0, juke: 0}; }
            const movement = movementForKeys(keys);
            const value = {...(callbacks.transformMovement?.(movement) ?? movement), juke};
            if (canPass()) {
                if (pass) value.pass = {...pass};
            } else if (drag) cancelAim();
            juke = 0; pass = null;
            return value;
        },
        clear,
        aimStatus: () => gesture,
        dispose() { clear(); abort.abort(); },
    };
}
