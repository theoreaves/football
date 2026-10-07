import * as THREE from 'three';

// Kept separate from the helmet so future player face profiles can replace it.
export function buildPlayerFace(player, skin) {
    const face = new THREE.Group(); face.name = 'player-face';
    face.userData.profile = player.face_profile || 'generic';
    face.position.set(0, 1.97, .257);
    const dark = new THREE.MeshStandardMaterial({ color: '#25201e', roughness: .9 });
    const white = new THREE.MeshStandardMaterial({ color: '#e9e4dd', roughness: .9 });
    const feature = (name, geometry, material, x, y, z) => {
        const mesh = new THREE.Mesh(geometry, material); mesh.name = name;
        mesh.position.set(x, y, z); face.add(mesh); return mesh;
    };
    for (const sign of [-1, 1]) {
        feature('eye-white', new THREE.BoxGeometry(.05, .026, .008), white, sign * .075, .045, .004);
        feature('eye', new THREE.SphereGeometry(.011, 8, 6), dark, sign * .075, .045, .011);
        feature('eyebrow', new THREE.BoxGeometry(.06, .012, .009), dark, sign * .075, .075, .006);
    }
    const nose = feature('nose', new THREE.SphereGeometry(.027, 10, 8), skin, 0, -.005, .016);
    nose.scale.set(.7, 1.2, 1);
    feature('mouth', new THREE.BoxGeometry(.075, .011, .008), dark, 0, -.075, .006);
    return face;
}
