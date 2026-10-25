import * as THREE from 'three';

export function addJerseyNumbers(jersey, player, document, uniform = {}, placement = {}) {
    if (player.number == null) return;
    const canvas = document.createElement('canvas'); canvas.width = 64; canvas.height = 64;
    const ctx = canvas.getContext('2d'); ctx.textAlign = 'center'; ctx.font = 'bold 44px sans-serif';
    ctx.fillStyle = uniform.number || '#ffffff'; ctx.strokeStyle = uniform.number_outline || '#111111'; ctx.lineWidth = 5;
    ctx.strokeText(String(player.number), 32, 50); ctx.fillText(String(player.number), 32, 50);
    const texture = new THREE.CanvasTexture(canvas); texture.colorSpace = THREE.SRGBColorSpace;
    const numberMaterial = new THREE.MeshBasicMaterial({ map: texture, transparent: true, depthWrite: false });
    for (const facing of [1, -1]) {
        const number = new THREE.Mesh(new THREE.PlaneGeometry(.65, .65), numberMaterial);
        // Push the decal clear of the torso surface (including bulkier kits).
        number.position.set(0, (placement.height ?? 1.25), facing * Math.max(.318, placement.depth ?? .326));
        number.rotation.y = facing === 1 ? 0 : Math.PI;
        jersey.add(number);
    }
    return numberMaterial;
}

// Position uniform numerals on the actual shoulder-pad top, not the sleeves.
// Parent to the padded shoulder mesh so arm swings cannot carry them outward.
// This also inherits the shoulder pad's per-player width scaling.
export function addShoulderNumbers(shoulderPads, material) {
    if (!material || !shoulderPads) return;
    for (const sign of [-1, 1]) {
        const number = new THREE.Mesh(new THREE.PlaneGeometry(.30, .30), material);
        number.name = sign < 0 ? 'jersey-number-shoulder-left' : 'jersey-number-shoulder-right';
        // The shoulder box is .22 tall: +.11 is its top surface. Add .014
        // clearance to avoid coplanar z-fighting, even in angled camera views.
        number.position.set(sign * .405, .124, 0);
        number.rotation.x = -Math.PI / 2;
        // Turn shoulder numerals sideways across the pad, mirrored by side.
        number.rotation.z = sign * Math.PI / 2;
        shoulderPads.add(number);
    }
}

// Render front/back numbers ON the sloping faces of the actual four-sided
// jersey cylinder. This surface shares the torso's position and scale, so
// different player builds cannot pull it away from the fabric.
export function addSurfaceJerseyNumbers(torso, player, document, uniform = {}, fit = {}) {
    if (player.number == null) return null;
    const canvas = document.createElement('canvas'); canvas.width = 64; canvas.height = 64;
    const ctx = canvas.getContext('2d');
    ctx.textAlign = 'center'; ctx.font = 'bold 44px sans-serif';
    ctx.fillStyle = uniform.number || '#ffffff';
    ctx.strokeStyle = uniform.number_outline || '#111111'; ctx.lineWidth = 5;
    ctx.strokeText(String(player.number), 32, 50);
    ctx.fillText(String(player.number), 32, 50);
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    const material = new THREE.MeshBasicMaterial({ map: texture, transparent: true, depthWrite: false, side: THREE.DoubleSide });
    const width = fit.numberWidth ?? .52;
    const height = fit.numberHeight ?? .51;
    const centerY = fit.numberY ?? -.035;
    const radiusAt = y => .44 + (.6 - .44) * ((y + .36) / .72);
    // Each side of the diamond-cross-section cylinder has a flat front/back
    // facet. The small offset is in *torso coordinates*, not world space.
    for (const sign of [1, -1]) {
        const low = centerY - height / 2, high = centerY + height / 2;
        const half = width / 2;
        const faceZ = y => sign * (radiusAt(y) / Math.SQRT2 + .005);
        const positions = new Float32Array([
            -half, low, faceZ(low), half, low, faceZ(low),
            -half, high, faceZ(high), half, high, faceZ(high),
        ]);
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geometry.setAttribute('uv', new THREE.Float32BufferAttribute([sign === 1 ? 0 : 1, 0, sign === 1 ? 1 : 0, 0, sign === 1 ? 0 : 1, 1, sign === 1 ? 1 : 0, 1], 2));
        geometry.setIndex(sign === 1 ? [0, 1, 2, 2, 1, 3] : [1, 0, 2, 3, 1, 2]);
        geometry.computeVertexNormals();
        const mesh = new THREE.Mesh(geometry, material);
        mesh.name = sign === 1 ? 'jersey-number-front-surface' : 'jersey-number-back-surface';
        mesh.renderOrder = 3;
        torso.add(mesh);
    }
    return material;
}
