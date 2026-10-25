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
        number.position.set(0, (placement.height ?? 1.25), facing * Math.max(.38, placement.depth ?? .435));
        number.rotation.y = facing === 1 ? 0 : Math.PI;
        jersey.add(number);
    }
    return numberMaterial;
}

// Attach numbers to the top of the moving upper shoulders, rather than
// vertically to the outside sleeves. Geometry follows arm animation naturally.
export function addShoulderNumbers(arms, material) {
    if (!material) return;
    arms.forEach((arm, index) => {
        const sign = index === 0 ? -1 : 1;
        const number = new THREE.Mesh(new THREE.PlaneGeometry(.32, .22), material);
        number.name = sign < 0 ? 'jersey-number-shoulder-left' : 'jersey-number-shoulder-right';
        number.position.set(0, .135, 0);
        number.rotation.x = -Math.PI / 2;
        arm.add(number);
    });
}
