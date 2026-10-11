export function restoreCamera(saved, focus, direction = 1) {
    if (!saved || !['broadcast', 'overhead', 'quarterback', 'free'].includes(saved.mode)) return null;
    const valid = vector => Array.isArray(vector) && vector.length === 3 && vector.every(value => Number.isFinite(value) && Math.abs(value) < 1000);
    if (saved.mode === 'free') return valid(saved.position) && valid(saved.target)
        ? { mode: 'free', position: [...saved.position], target: [...saved.target] } : null;
    if (!valid(saved.offset) || !valid(saved.pan)) return null;
    const flip = saved.mode === 'quarterback' && saved.direction && saved.direction !== direction ? -1 : 1;
    const pan = saved.pan.map((value, i) => i === 1 ? value : value * flip);
    const offset = saved.offset.map((value, i) => i === 1 ? value : value * flip);
    const target = focus.map((value, i) => value + pan[i]);
    return { mode: saved.mode, target, position: target.map((value, i) => value + offset[i]) };
}

export function captureCamera(mode, position, target, focus, direction = 1) {
    if (mode === 'free') return { mode, position: [...position], target: [...target] };
    return { mode, direction, offset: position.map((value, i) => value - target[i]), pan: target.map((value, i) => value - focus[i]) };
}

export function cameraPreset(mode, focus, direction = 1) {
    const offset = mode === 'quarterback' ? [-22 * direction, 10, 0] : mode === 'overhead' ? [0, 85, .01] : [0, 48, 65];
    return { target: [...focus], position: focus.map((value, i) => value + offset[i]) };
}

export function translateCameraAnchor(position, target, previousAnchor, nextAnchor, mode = 'broadcast') {
    if (mode === 'free') return { position: [...position], target: [...target] };
    const shift = nextAnchor.map((value, i) => value - previousAnchor[i]);
    return { position: position.map((value, i) => value + shift[i]), target: target.map((value, i) => value + shift[i]) };
}

export function cameraLimits(mode) {
    return mode === 'free'
        ? { minDistance: 0.5, maxDistance: 280, maxPolarAngle: Math.PI * 0.98 }
        : { minDistance: 16, maxDistance: 180, maxPolarAngle: Math.PI / 2.1 };
}
