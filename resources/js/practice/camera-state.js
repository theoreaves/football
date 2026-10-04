export function restoreCamera(saved, focus) {
    if (!saved || !['broadcast', 'overhead'].includes(saved.mode)) return null;
    const valid = vector => Array.isArray(vector) && vector.length === 3 && vector.every(value => Number.isFinite(value) && Math.abs(value) < 1000);
    if (!valid(saved.offset) || !valid(saved.pan)) return null;
    const target = focus.map((value, i) => value + saved.pan[i]);
    return { mode: saved.mode, target, position: target.map((value, i) => value + saved.offset[i]) };
}

export function captureCamera(mode, position, target, focus) {
    return { mode, offset: position.map((value, i) => value - target[i]), pan: target.map((value, i) => value - focus[i]) };
}
