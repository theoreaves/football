import * as THREE from 'three';

function scalpGeometry() {
    const geometry = new THREE.SphereGeometry(1, 32, 16, 0, Math.PI * 2, 0, Math.PI * .52);
    const positions = geometry.attributes.position, uv = geometry.attributes.uv;
    for (let i = 0; i < positions.count; i++) {
        const phi = uv.getX(i) * Math.PI * 2;
        // Raise the front hairline above the brows, retaining lower sides and back.
        const edge = Math.PI * (.52 - .20 * Math.max(0, Math.sin(phi)));
        const theta = (1 - uv.getY(i)) * edge;
        positions.setXYZ(i, -Math.cos(phi) * Math.sin(theta), Math.cos(theta), Math.sin(phi) * Math.sin(theta));
    }
    geometry.computeVertexNormals();
    return geometry;
}

export function buildPlayerFace(player, skin) {
    const profile = player.appearance || {};
    const face = new THREE.Group(); face.name = 'player-face';
    face.userData.profile = player.appearance || 'generic';
    face.position.set(0, 1.97, .257);
    const dark = new THREE.MeshStandardMaterial({ color: profile.hair_color || '#25201e', roughness: .9 });
    const iris = new THREE.MeshStandardMaterial({ color: profile.eye_color || '#60452f', roughness: .6 });
    const pupil = new THREE.MeshStandardMaterial({ color: '#111111' });
    const white = new THREE.MeshStandardMaterial({ color: '#e9e4dd', roughness: .9 });
    const lips = new THREE.MeshStandardMaterial({ color: '#7b4440', roughness: .9 });
    const feature = (name, geometry, material, x, y, z) => {
        const mesh = new THREE.Mesh(geometry, material); mesh.name = name;
        mesh.position.set(x, y, z); face.add(mesh); return mesh;
    };
    for (const sign of [-1, 1]) {
        feature('eye-white', new THREE.BoxGeometry(.05, .026, .008), white, sign * .075, .045, .004);
        feature('eye', new THREE.SphereGeometry(.011, 12, 8), iris, sign * .075, .045, .011);
        feature('pupil', new THREE.SphereGeometry(.005, 8, 6), pupil, sign * .075, .045, .02);
        let geometry = new THREE.BoxGeometry(profile.brow === 'thick' ? .07 : .06, profile.brow === 'thick' ? .02 : .012, .009);
        if (profile.brow === 'arched') geometry = new THREE.TubeGeometry(new THREE.CatmullRomCurve3([new THREE.Vector3(-.035,0,0),new THREE.Vector3(0,.013,0),new THREE.Vector3(.035,0,0)]), 10, .006, 6, false);
        const brow = feature('eyebrow', geometry, dark, sign * .075, .075, .006);
        if (profile.brow === 'angled') brow.rotation.z = sign * .28;
    }
    const nose = feature('nose', new THREE.SphereGeometry(.027, 16, 10), skin, 0, -.005, .016);
    nose.scale.set(...({small:[.55,.85,.7],wide:[1.2,1.1,1],long:[.7,1.65,1.4]}[profile.nose] || [.7,1.2,1]));
    const mouth = profile.mouth || 'neutral';
    const mouthGeometry = mouth === 'smile'
        ? new THREE.TubeGeometry(new THREE.CatmullRomCurve3([new THREE.Vector3(-.045,.008,0),new THREE.Vector3(0,-.008,0),new THREE.Vector3(.045,.008,0)]),12,.006,6,false)
        : new THREE.BoxGeometry(mouth === 'wide' ? .105 : .075, mouth === 'thin' ? .005 : .011, .008);
    feature('mouth', mouthGeometry, lips, 0, -.075, .018);
    const beard = profile.beard || 'none';
    if (beard === 'moustache' || beard === 'full') feature('moustache', new THREE.BoxGeometry(.1,.018,.014), dark, 0,-.05,.012);
    if (beard === 'goatee') feature('beard', new THREE.BoxGeometry(.08,.07,.025), dark, 0,-.125,.005);
    if (beard === 'stubble' || beard === 'full') {
        const geometry = new THREE.SphereGeometry(1,16,10);
        const mesh = feature('beard',geometry,dark,0,-.13,-.035);
        mesh.scale.set(.19,beard === 'full' ? .09 : .055,.07);
    }
    const hair = profile.hair || 'bald';
    if (hair !== 'bald') {
        // Hair stays close to the skull so it fits under the game helmet.
        const cap = feature('hair', scalpGeometry(), dark, 0, 0, -.177);
        cap.scale.set(.23, hair === 'buzz' ? .214 : .235, .185);
        if (hair === 'short') cap.rotation.z = -.07;
        if (hair === 'curly') {
            for (let i = 0; i < 18; i++) {
                const angle = i * Math.PI * 2 / 18;
                feature('hair-curl', new THREE.SphereGeometry(.035, 8, 6), dark, Math.cos(angle) * .18, .15, -.177 + Math.sin(angle) * .13);
            }
        }
        if (hair === 'long') {
            const back = feature('hair-back', new THREE.SphereGeometry(1, 20, 12), dark, 0, -.035, -.29);
            back.scale.set(.225, .24, .085);
        }
    }
    return face;
}
