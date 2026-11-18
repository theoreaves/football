import * as THREE from 'three';

const clamp = THREE.MathUtils.clamp;
// View-only ownership: input always remains with the quarterback.
export function quarterbackCameraActor(state) {
    const actors = state.actors || state.players || [];
    const qb = actors.find(actor => actor.key === 'offense_QB') || state.quarterback;
    const holder = state.ball?.phase !== 'flight' && actors.find(actor => actor.key === state.ball?.holderKey);
    return holder || qb || state.runner || {x: 60, z: 26.665};
}

export function quarterbackCameraFrame(width, height, actor, direction = 1) {
    const aspect = Math.max(1, width) / Math.max(1, height);
    const sign = direction < 0 ? -1 : 1;
    const x = Number.isFinite(actor?.x) ? actor.x : 60;
    const z = Number.isFinite(actor?.z) ? actor.z : 26.665;
    return {
        aspect, fov: aspect < 1 ? 72 : 58,
        position: [clamp(x - sign * 17, -17, 137), 18, clamp(z, 4, 49.33)],
        target: [x + sign * 19, 0, clamp(z, 4, 49.33)],
    };
}

export function createQuarterbackCameraFollow(camera) {
    let position = null, target = null;
    return {
        update(frame, delta = 0, reset = false) {
            const nextPosition = new THREE.Vector3(...frame.position);
            const nextTarget = new THREE.Vector3(...frame.target);
            if (!position || reset) { position = nextPosition; target = nextTarget; }
            else {
                const blend = 1 - Math.exp(-6 * Math.max(0, Math.min(.1, delta)));
                position.lerp(nextPosition, blend); target.lerp(nextTarget, blend);
            }
            camera.aspect = frame.aspect; camera.fov = frame.fov;
            camera.position.copy(position); camera.lookAt(target);
            camera.updateProjectionMatrix(); camera.updateMatrixWorld();
        },
    };
}
