import * as THREE from 'three';

export function jerseyLastName(player) {
    if (String(player.lastname ?? '').trim()) return String(player.lastname).trim();
    return String(player.name || '').trim().split(/\s+/).at(-1) || '';
}

export function addJerseyName(jersey, player, document, uniform = {}) {
    const text = jerseyLastName(player).toUpperCase();
    if (!uniform.name_enabled || !text) return;
    const canvas = document.createElement('canvas'); canvas.width = 512; canvas.height = 96;
    const context = canvas.getContext('2d');
    context.textAlign = 'center'; context.textBaseline = 'middle';
    context.font = 'bold 64px sans-serif'; context.fillStyle = uniform.name_color || uniform.number || '#ffffff';
    context.fillText(text, 256, 48, 490);
    const texture = new THREE.CanvasTexture(canvas); texture.colorSpace = THREE.SRGBColorSpace;
    const name = new THREE.Mesh(new THREE.PlaneGeometry(.92, .17), new THREE.MeshBasicMaterial({ map: texture, transparent: true, depthWrite: false }));
    name.name = 'jersey-name'; name.userData.text = text;
    // Keep the name in front of the jersey's back surface, including bulky models.
    name.position.set(0, 1.6, -.305); name.rotation.y = Math.PI;
    jersey.add(name);
}

// Shoulder pad is a box with a .56 front-to-back dimension. Mount the
// nameplate to its actual back face so bulk scaling is inherited automatically.
export function addShoulderJerseyName(shoulders, player, document, uniform = {}, fit = {}) {
    const text = jerseyLastName(player).toUpperCase();
    if (!uniform.name_enabled || !text) return;
    const canvas = document.createElement('canvas'); canvas.width = 512; canvas.height = 96;
    const ctx = canvas.getContext('2d');
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.font = 'bold 64px sans-serif';
    ctx.fillStyle = uniform.name_color || uniform.number || '#ffffff';
    ctx.fillText(text, 256, 48, 490);
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    const name = new THREE.Mesh(
        new THREE.PlaneGeometry(fit.nameWidth ?? .91, .17),
        new THREE.MeshBasicMaterial({map: texture, transparent: true, depthWrite: false})
    );
    name.name = 'jersey-name'; name.userData.text = text;
    name.position.set(0, fit.nameY ?? .012, -.285);
    name.rotation.y = Math.PI;
    name.renderOrder = 3;
    shoulders.add(name);
}
