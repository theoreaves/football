import { fitLogo } from './logo-fit.js';
import * as THREE from 'three';
import { addJerseyNumbers } from './jersey-numbers.js';

export function buildFootballPlayer(player, kit, document, textureFor = () => null) {
    const group = new THREE.Group();
    const mat = color => new THREE.MeshStandardMaterial({ color, roughness: .65, flatShading: true });
    const shirt = mat(kit.shirt || '#3997ff'), pants = mat(kit.pants || '#eeeeee');
    const skin = mat(player.skin_tone || '#c78e61'), helmetPaint = mat(kit.helmet || '#eeeeee');
    const black = mat('#17202b'), socks = mat(kit.socks || '#ffffff');
    const box = (parent, size, position, material) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(...size), material);
        mesh.position.set(...position); mesh.castShadow = true; parent.add(mesh); return mesh;
    };
    const torso = new THREE.Mesh(new THREE.CylinderGeometry(.6, .44, .72, 4), shirt);
    torso.geometry.rotateY(Math.PI / 4); torso.scale.z = .6; torso.position.y = 1.25; torso.castShadow = true; group.add(torso);
    box(group, [1.17, .22, .56], [0, 1.58, 0], shirt);
    box(group, [.69, .21, .44], [0, .83, 0], pants);
    const legs = [], arms = [], elbows = [];
    for (const sign of [-1, 1]) {
        const leg = new THREE.Group(); leg.position.set(sign * .23, .84, 0); group.add(leg); legs.push(leg);
        box(leg, [.3, .42, .36], [0, -.2, 0], pants);
        box(leg, [.23, .32, .24], [0, -.55, 0], socks);
        box(leg, [.27, .14, .42], [0, -.76, .06], black);
        if (kit.pants_stripe_enabled) box(leg, [.025, .42, .065], [sign * .155, -.2, .03], mat(kit.pants_stripe || '#ffffff'));
        const arm = new THREE.Group(); arm.position.set(sign * .57, 1.49, 0); arm.rotation.z = sign * .12; group.add(arm); arms.push(arm);
        box(arm, [.3, .3, .49], [0, -.04, 0], shirt);
        box(arm, [.2, .33, .22], [0, -.32, 0], skin);
        const elbow = new THREE.Group(); elbow.position.set(0, -.46, 0); arm.add(elbow); elbows.push(elbow);
        box(elbow, [.17, .29, .19], [0, -.15, .025], skin);
        box(elbow, [.19, .17, .21], [0, -.35, .04], skin);
        if (kit.shoulder_stripe_enabled) box(arm, [.305, .07, .5], [0, -.1, 0], mat(kit.shoulder_stripe || '#ffffff'));
    }
    box(group, [.23, .2, .23], [0, 1.75, 0], skin);
    box(group, [.43, .38, .35], [0, 1.97, .08], skin);
    const helmet = new THREE.Mesh(new THREE.SphereGeometry(.37, 12, 8, 0, Math.PI * 2, 0, 2.05), helmetPaint);
    helmet.position.set(0, 2.03, 0); helmet.castShadow = true; group.add(helmet);
    const faceMask = new THREE.Group(); faceMask.name = 'facemask'; group.add(faceMask);
    const maskPaint = mat(kit.facemask || '#17202b'); maskPaint.roughness = .4;
    const rail = points => {
        const curve = new THREE.CatmullRomCurve3(points.map(point => new THREE.Vector3(...point)));
        const mesh = new THREE.Mesh(new THREE.TubeGeometry(curve, 16, .019, 6, false), maskPaint);
        mesh.castShadow = true; faceMask.add(mesh); return curve;
    };
    const bars = [[1.97, 1.99], [1.82, 1.94]].map(([y, mountY]) => rail([
        [-.31, mountY, .17], [-.34, y, .34], [-.24, y, .48], [0, y, .53],
        [.24, y, .48], [.34, y, .34], [.31, mountY, .17],
    ]));
    // Uprights join the curved bars exactly, with both side rails anchored in the shell.
    for (const t of [.38, .62]) {
        const upper = bars[0].getPoint(t), lower = bars[1].getPoint(t);
        rail([lower.toArray(), upper.toArray()]);
    }
    for (const sign of [-1, 1]) {
        const mount = new THREE.Mesh(new THREE.SphereGeometry(.032, 8, 6), maskPaint);
        mount.position.set(sign * .31, 1.965, .17); faceMask.add(mount);
    }
    group.userData.faceMask = faceMask;
    if (kit.helmet_stripe_enabled) {
        const stripe = new THREE.Mesh(new THREE.TorusGeometry(.374, .027, 4, 20, Math.PI), mat(kit.helmet_stripe || '#ffffff'));
        stripe.rotation.y = Math.PI / 2; stripe.position.y = 2.03; group.add(stripe);
    }
    for (const [sign, url] of [[-1, kit.helmet_logo_left], [1, kit.helmet_logo_right]]) {
        const texture = url && textureFor(url); if (!texture) continue;
        const logo = new THREE.Mesh(new THREE.PlaneGeometry(.36, .29), new THREE.MeshBasicMaterial({map: texture, transparent: true, depthWrite: false}));
        logo.onBeforeRender = () => { if (texture.image) { const size = fitLogo(texture.image.width, texture.image.height, .36, .29); logo.scale.set(size.width / .36, size.height / .29, 1); } };
        logo.position.set(sign * .368, 2.04, 0); logo.rotation.y = sign * Math.PI / 2; group.add(logo);
    }
    addJerseyNumbers(group, player, document, kit, {depth: .253, height: 1.27});
    const height = Math.max(48, Math.min(96, Number(player.height_inches) || 72)) / 72;
    const bulk = Math.max(.8, Math.min(1.35, Math.sqrt((Number(player.weight_pounds) || 215) / 215)));
    group.scale.set(bulk, height, bulk);
    group.userData.legs = legs; group.userData.arms = arms; group.userData.elbows = elbows;
    return group;
}

export function animateFootballPlayer(group, moving, time, index, throwing = null) {
    const stride = moving ? Math.sin(time * 16 + index) * .5 : 0;
    group.userData.legs?.forEach((leg, i) => { leg.rotation.x = i === 0 ? stride : -stride; });
    group.userData.arms?.forEach((arm, i) => { arm.rotation.z = 0; arm.rotation.x = i === 0 ? -stride * .65 : stride * .65; });
    group.userData.elbows?.forEach(elbow => { elbow.rotation.x = 0; });
    if (throwing !== null && throwing >= 1.4 && throwing <= 2.9) {
        const arm = group.userData.arms?.[1];
        if (arm) {
            const smooth = value => { const t = Math.max(0, Math.min(1, value)); return t * t * (3 - 2 * t); };
            const cock = smooth((throwing - 1.4) / .55);
            const release = smooth((throwing - 2.1) / .1);
            const recover = smooth((throwing - 2.3) / .6);
            arm.rotation.x = (-1.5 * cock - .8 * release) * (1 - recover);
            arm.rotation.z = -.45 * cock * (1 - recover);
            group.userData.elbows[1].rotation.x = -1.5 * cock * (1 - release) * (1 - recover);
            if (group.userData.arms[0]) group.userData.arms[0].rotation.x = -.6 * cock * (1 - recover);
        }
    }
}
