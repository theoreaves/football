import * as THREE from 'three';

export function addJerseyNumbers(jersey, player, document) {
    if (player.number == null) return;
    const canvas = document.createElement('canvas'); canvas.width = 64; canvas.height = 64;
    const ctx = canvas.getContext('2d'); ctx.textAlign = 'center'; ctx.font = 'bold 44px sans-serif';
    ctx.fillStyle = '#ffffff'; ctx.strokeStyle = '#111111'; ctx.lineWidth = 5;
    ctx.strokeText(String(player.number), 32, 50); ctx.fillText(String(player.number), 32, 50);
    const texture = new THREE.CanvasTexture(canvas); texture.colorSpace = THREE.SRGBColorSpace;
    const numberMaterial = new THREE.MeshBasicMaterial({ map: texture, transparent: true, depthWrite: false });
    for (const facing of [1, -1]) {
        const number = new THREE.Mesh(new THREE.PlaneGeometry(.65, .65), numberMaterial);
        number.position.set(0, 1.25, facing * .435);
        number.rotation.y = facing === 1 ? 0 : Math.PI;
        jersey.add(number);
    }
}
