import { addJerseyName } from './jersey-names.js';
import { buildPlayerFace } from './player-face.js';
import { helmetShellGeometry, helmetLogoGeometry, helmetStripeGeometry } from './helmet-shell.js';
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
    const shoulders = box(group, [1.17, .22, .56], [0, 1.58, 0], shirt);
    const hips = box(group, [.69, .21, .44], [0, .83, 0], pants);
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
    const headPartsStart = group.children.length;
    const head = new THREE.Mesh(new THREE.SphereGeometry(1, 24, 16), skin);
    head.name = 'player-head'; head.scale.set(.225, .21, .18); head.position.set(0, 1.97, .08); group.add(head);
    for (const sign of [-1, 1]) {
        const ear = new THREE.Mesh(new THREE.SphereGeometry(.04, 12, 8), skin);
        ear.scale.set(.5, 1, .7); ear.position.set(sign * .23, 1.95, .07); group.add(ear);
    }
    group.add(buildPlayerFace(player, skin));
    const shape = player.appearance?.head_shape || 'round';
    if (shape === 'square') {
        const vertices = head.geometry.attributes.position;
        for (let i = 0; i < vertices.count; i++) {
            const y = vertices.getY(i);
            if (y < 0) vertices.setX(i, vertices.getX(i) * (1 + .18 * Math.sin(-y * Math.PI)));
        }
        head.geometry.computeVertexNormals();
    }
    const headParts = new THREE.Group(); headParts.name = 'player-head-shape';
    headParts.position.set(0, 1.97, .08);
    for (const part of group.children.slice(headPartsStart)) {
        part.position.sub(headParts.position); headParts.add(part);
    }
    headParts.scale.set(...({oval:[.95,1.07,1],square:[1,1,1],wide:[1.12,.96,1.04],long:[.91,1.13,1]}[shape] || [1,1,1]));
    group.add(headParts);
    helmetPaint.flatShading = false; helmetPaint.roughness = .32;
    const helmet = new THREE.Mesh(helmetShellGeometry(), helmetPaint);
    helmet.name = 'helmet-shell';
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
        const stripePaint = mat(kit.helmet_stripe || '#ffffff'); stripePaint.flatShading = false;
        stripePaint.side = THREE.FrontSide;
        stripePaint.polygonOffset = true;
        stripePaint.polygonOffsetFactor = -1;
        stripePaint.polygonOffsetUnits = -1;
        const stripe = new THREE.Mesh(helmetStripeGeometry(), stripePaint);
        stripe.name = 'helmet-stripe'; helmet.add(stripe);
    }
    for (const [sign, url] of [[-1, kit.helmet_logo_left], [1, kit.helmet_logo_right]]) {
        const texture = url && textureFor(url); if (!texture) continue;
        const logo = new THREE.Mesh(helmetLogoGeometry(sign), new THREE.MeshBasicMaterial({map: texture, transparent: true, depthWrite: false, side: THREE.DoubleSide}));
        logo.name = sign < 0 ? 'helmet-logo-left' : 'helmet-logo-right';
        let dimensions = '';
        logo.onBeforeRender = () => {
            if (!texture.image) return;
            const key = `${texture.image.width}:${texture.image.height}`;
            if (key === dimensions) return;
            const size = fitLogo(texture.image.width, texture.image.height, .36, .29);
            logo.geometry.dispose(); logo.geometry = helmetLogoGeometry(sign, size.width, size.height);
            dimensions = key;
        };
        helmet.add(logo);
    }
    const labelStart = group.children.length;
    addJerseyNumbers(group, player, document, kit, {depth: .253, height: 1.27});
    addJerseyName(group, player, document, kit);
    const height = Math.max(48, Math.min(96, Number(player.height_inches) || 72)) / 72;
    const bulk = Math.max(.8, Math.min(1.35, Math.sqrt((Number(player.weight_pounds) || 215) / 215)));
    group.scale.set(Math.pow(bulk, .2), height, Math.pow(bulk, .2));
    shoulders.scale.x = Math.pow(bulk,.6); shoulders.scale.z = Math.pow(bulk,.6); hips.scale.x = Math.pow(bulk,.4); hips.scale.z = Math.pow(bulk,.4);
    torso.scale.x = Math.pow(bulk, .7); torso.scale.z *= Math.pow(bulk, .9);
    group.children.slice(labelStart).forEach(label => { label.position.z *= Math.pow(bulk, .9); });
    arms.forEach((arm, i) => { arm.position.x = (i === 0 ? -1 : 1) * .57 * Math.pow(bulk, .6); arm.scale.set(Math.pow(bulk,.35),1,Math.pow(bulk,.35)); });
    legs.forEach(leg => { leg.scale.x = Math.pow(bulk,.4); leg.scale.z = Math.pow(bulk,.4); });
    group.userData.legs = legs; group.userData.arms = arms; group.userData.elbows = elbows;
    // Saved in appearance JSON; existing quarterbacks default to right-handed.
    group.userData.throwingHand = player.appearance?.throwing_hand === 'left' ? 'left' : 'right';
    // Articulated waist: preserve every part's rest position while moving it
    // under a common pivot. Arms remain attached to the upper body, so the QB
    // hand-target solver and football attachment retain the same coordinates.
    const waist = new THREE.Group();
    waist.position.y = .83;
    for (const part of [...group.children]) {
        if (part === hips || legs.includes(part)) continue;
        part.position.y -= .83;
        waist.add(part);
    }
    group.add(waist);
    group.userData.waist = waist;
    // Articulated knee/shin joint; upper-leg pieces stay at hip level.
    const knees = legs.map(leg => {
        const knee = new THREE.Group();
        knee.position.y = -.40;
        for (const part of [...leg.children]) {
            if (part.position.y >= -.45) continue;
            part.position.y += .40;
            knee.add(part);
        }
        leg.add(knee);
        return knee;
    });
    group.userData.knees = knees;

    return group;
}

export function animateFootballPlayer(group, moving, time, index, throwing = null, reception = null) {
    if (group.userData.holderKneel) {
        group.userData.legs?.forEach((leg, i) => { leg.rotation.x = i === 0 ? -1.55 : 1.1; });
        group.userData.arms?.forEach((arm, i) => { arm.rotation.z = i === 0 ? -.12 : .12; arm.rotation.x = -1.05; });
        group.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.85; });
        return;
    }
    // Independent limb joints let the runner pump arms and bend elbows.
    // Keep this purely visual: no game-state or player-path modifications.
    const stride = moving ? Math.sin(time * 13.5 + index * .83) : 0;
    const swing = moving ? .58 * stride : 0;
    const bend = moving ? Math.max(0, -stride) * .23 : 0;
    group.userData.legs?.forEach((leg, i) => {
        leg.rotation.x = (i === 0 ? 1 : -1) * swing + bend;
    });
    group.userData.arms?.forEach((arm, i) => {
        const phase = i === 0 ? -1 : 1;
        arm.rotation.z = phase * .10;
        arm.rotation.x = phase * swing * .72 - (moving ? .14 : 0);
    });
    group.userData.elbows?.forEach((elbow, i) => {
        elbow.rotation.x = moving ? -.60 - Math.abs(stride) * .18 : -.12;
    });
    // High-and-tight carry: one elbow stays close to the ribs, its forearm
    // crosses the football; the free arm keeps its running pump.
    // Arm selection matches the renderer's RB-left / receiver-right tuck side.
    if (group.userData.cradlingBall) {
        const carryArm = group.userData.carryingArm ?? 0;
        const arm = group.userData.arms?.[carryArm];
        const elbow = group.userData.elbows?.[carryArm];
        if (arm) {
            arm.rotation.x = -.67;
            arm.rotation.y = carryArm === 0 ? -.25 : .25;
            arm.rotation.z = carryArm === 0 ? -.18 : .18;
        }
        if (elbow) {
            elbow.rotation.x = -1.30;
            elbow.rotation.y = carryArm === 0 ? -.20 : .20;
        }
    }
    // During a tackle, brace with bent arms instead of continuing to sprint.
    // The tackle direction and whole-body rotation remain owned by field.js.
    if (Math.abs(group.rotation.z) > .12 && !group.userData.holderKneel) {
        group.userData.legs?.forEach((leg, i) => {
            leg.rotation.x = i === 0 ? -.42 : .28;
        });
        group.userData.arms?.forEach((arm, i) => {
            arm.rotation.x = i === 0 ? -.75 : -.95;
            arm.rotation.z = i === 0 ? -.25 : .25;
        });
        group.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.9; });
    }
    // Reach at the actual ball-arrival time. Catch and miss poses use the
    // existing arm/elbow joints and never change the recorded ball path.
    if (reception === 'catch' || reception === 'reach') {
        group.userData.arms?.forEach((arm, i) => {
            arm.rotation.x = reception === 'catch' ? -1.2 : -1.65;
            arm.rotation.z = i === 0 ? -.23 : .23;
        });
        group.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.40; });
    } else if (reception === 'tuck') {
        const arm = group.userData.arms?.[1];
        if (arm) { arm.rotation.x = -.88; arm.rotation.z = -.38; }
        const elbow = group.userData.elbows?.[1];
        if (elbow) elbow.rotation.x = -1.0;
    }
    if (throwing !== null && throwing >= .6 && throwing <= 2.9) {
        const hand = group.userData.throwingHand === 'left' ? 0 : 1;
        const support = 1 - hand;
        const arm = group.userData.arms?.[hand];
        const elbow = group.userData.elbows?.[hand];
        const other = group.userData.arms?.[support];
        const otherElbow = group.userData.elbows?.[support];
        const handed = hand === 0 ? -1 : 1;
        const smooth = value => {
            const t = Math.max(0, Math.min(1, value));
            return t * t * (3 - 2 * t);
        };
        const blend = (a, b, t) => a.clone().lerp(b, smooth(t));
        // Poses are specified as the actual hand location in model-local space.
        // The renderer already reads this same elbow's hand position for the ball.
        const ready = new THREE.Vector3(handed * .24, 1.49, .50);
        const windup = new THREE.Vector3(handed * .90, 1.95, -.08);
        const highRelease = new THREE.Vector3(handed * .61, 2.12, .32);
        const followThrough = new THREE.Vector3(-handed * .17, 1.46, .43);
        let target = ready;
        if (throwing >= 1.40 && throwing < 1.98) {
            target = blend(ready, windup, (throwing - 1.40) / .58);
        } else if (throwing >= 1.98 && throwing < 2.20) {
            target = blend(windup, highRelease, (throwing - 1.98) / .22);
        } else if (throwing >= 2.20) {
            target = blend(highRelease, followThrough, (throwing - 2.20) / .52);
        }
        // Solve upper arm and forearm toward the target instead of guessing
        // Euler angles. Keep the elbow outside and raised during the windup.
        if (arm && elbow) {
            const shoulder = arm.position.clone();
            const reach = target.clone().sub(shoulder);
            const length = reach.length();
            const upper = .46;
            const forearm = Math.hypot(.35, .04);
            const direction = reach.clone().normalize();
            const distance = Math.min(upper + forearm - .002, Math.max(.12, length));
            const along = (upper * upper + distance * distance - forearm * forearm) / (2 * distance);
            const rise = Math.sqrt(Math.max(0, upper * upper - along * along));
            const hint = new THREE.Vector3(handed * 1, .65, -.35);
            const outward = hint.addScaledVector(direction, -hint.dot(direction)).normalize();
            const elbowPoint = shoulder.clone().addScaledVector(direction, along).addScaledVector(outward, rise);
            const upperDirection = elbowPoint.sub(shoulder).normalize();
            arm.quaternion.setFromUnitVectors(new THREE.Vector3(0, -1, 0), upperDirection);
            // The hand is at elbow-local (0,-.35,+.04): match its true axis.
            const wristDirection = target.clone().sub(shoulder.clone().addScaledVector(upperDirection, upper));
            wristDirection.applyQuaternion(arm.quaternion.clone().invert()).normalize();
            elbow.quaternion.setFromUnitVectors(
                new THREE.Vector3(0, -.35, .04).normalize(), wristDirection
            );
        }
        // Opposite hand cups the ball until the windup, then clears the throw.
        const clear = smooth((throwing - 1.42) / .48);
        if (other) {
            other.rotation.set(-1.13 + .90 * clear, -handed * .16 * (1 - clear), -handed * (.28 - .14 * clear));
        }
        if (otherElbow) otherElbow.rotation.set(-1.03 * (1 - clear) - .15 * clear, 0, 0);
    }
}

// Pre-snap stance uses the waist and knee joints instead of tipping the whole
// model sideways. This is purely visual and does not touch play physics.
export function applyPreSnapStance(group, stance) {
    const waist = group.userData.waist;
    const knees = group.userData.knees;
    if (!waist || !knees) return;
    if (!stance) {
        waist.rotation.x = 0;
        knees.forEach(knee => { knee.rotation.x = 0; });
        return;
    }
    // One authoritative stance pose, applied *after* the normal running pose.
    // Keep the root upright; articulate the trunk, hips and knees separately.
    const center = stance === 'center';
    const threePoint = stance === 'three';
    const defensiveFront = stance === 'def-front';
    const ready = stance === 'ready';
    const depth = center ? 1 : threePoint ? .82 : defensiveFront ? .88 : .36;
    waist.rotation.x = (center ? .66 : threePoint ? .54 : defensiveFront ? .57 : .19);
    group.userData.legs?.forEach((leg, i) => {
        // Both thighs flex together. A slight stagger creates a stable base.
        leg.rotation.x = (.37 + (i === 0 ? -.04 : .04)) * depth;
    });
    knees.forEach(knee => { knee.rotation.x = -.82 * depth; });
    if (center) {
        group.userData.arms?.forEach(arm => { arm.rotation.x = -.98; });
        group.userData.elbows?.forEach(elbow => { elbow.rotation.x = -.74; });
    } else if (threePoint || defensiveFront) {
        const supportArm = group.userData.arms?.[0];
        const supportElbow = group.userData.elbows?.[0];
        if (supportArm) supportArm.rotation.x = -.82;
        if (supportElbow) supportElbow.rotation.x = -.70;
        const freeArm = group.userData.arms?.[1];
        if (freeArm) freeArm.rotation.x = -.27;
    } else if (ready) {
        group.userData.arms?.forEach(arm => { arm.rotation.x = -.22; });
    }
}

