import * as THREE from 'three';
import { sampleTrack } from './engine-timeline.js';

// Use committed results, never infer a ruling from an animated ball position.
export function refereeSignal(animation, before, after) {
    if (!animation || !before || !after || animation.no_snap || after.penalty_pending) return null;
    const summary = animation.events?.at(-1)?.[1]?.toLowerCase() || '';
    if (summary.includes('nullified') || summary.includes('awaiting')) return null;
    if (after.home_score - before.home_score >= 6 || after.away_score - before.away_score >= 6) return 'touchdown';
    if (before.phase === 'scrimmage' && after.phase === 'scrimmage'
        && before.possession === after.possession && after.down === 1
        && !['penalty', 'interception', 'fumble', 'incomplete'].includes(animation.outcome)
        && Number(animation.gain) >= Number(before.distance)) return 'first_down';
    return null;
}

export function signalProgress(time) {
    const t = Math.max(0, Math.min(1, time / .65));
    return t * t * (3 - 2 * t);
}

export function buildReferees(scene) {
    const group = new THREE.Group();
    group.name = 'referee-crew';
    scene.add(group);
    const white = new THREE.MeshStandardMaterial({color: 0xf5f5ee, roughness: .85});
    const black = new THREE.MeshStandardMaterial({color: 0x15191e, roughness: .85});
    const skin = new THREE.MeshStandardMaterial({color: 0xbb8965, roughness: .9});
    const box = (parent, size, material, position) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(...size), material);
        mesh.position.set(...position); mesh.castShadow = true; parent.add(mesh);
        return mesh;
    };
    const official = cap => {
        const body = new THREE.Group(); group.add(body);
        box(body, [.52, .65, .3], white, [0, 1.27, 0]);
        for (const x of [-.21, -.07, .07, .21]) {
            box(body, [.065, .65, .012], black, [x, 1.27, .156]);
            box(body, [.065, .65, .012], black, [x, 1.27, -.156]);
        }
        box(body, [.46, .24, .3], black, [0, .83, 0]);
        const legs = [-1, 1].map(sign => {
            const leg = new THREE.Group(); leg.position.set(sign * .14, .77, 0); body.add(leg);
            box(leg, [.18, .7, .22], black, [0, -.35, 0]);
            box(leg, [.2, .12, .33], black, [0, -.71, .06]);
            return leg;
        });
        const head = new THREE.Mesh(new THREE.SphereGeometry(.21, 12, 10), skin);
        head.position.set(0, 1.84, 0); body.add(head);
        box(body, [.45, .1, .38], cap, [0, 2.02, .025]);
        box(body, [.32, .035, .18], cap, [0, 1.99, .24]);
        const elbows = [];
        const arms = [-1, 1].map(sign => {
            const arm = new THREE.Group(); arm.position.set(sign * .34, 1.52, 0); body.add(arm);
            box(arm, [.19, .28, .23], white, [0, -.14, 0]);
            box(arm, [.08, .28, .012], black, [0, -.14, .12]);
            const elbow = new THREE.Group(); elbow.position.y = -.28; arm.add(elbow); elbows.push(elbow);
            box(elbow, [.15, .32, .17], skin, [0, -.15, 0]);
            box(elbow, [.17, .14, .19], skin, [0, -.36, 0]);
            return arm;
        });
        return {body, arms, legs, elbows};
    };
    const roles = ['R', 'U', 'DJ', 'LJ', 'FJ', 'SJ', 'BJ'];
    const crew = roles.map((role, index) => {
        const ref = official(index === 0 ? white : black);
        ref.body.name = role;
        return ref;
    });
    const update = ({poses, signal = null, time = 0, active = false, visible = true, direction = 1, penalty = null, penaltyTime = 0, flagThrow = null}) => {
        group.visible = visible;
        crew.forEach((ref, index) => {
            const pose = poses[index];
            ref.body.position.set(pose.x, 0, pose.z);
            const moving = Math.abs(pose.velocity) > .1;
            const canSignal = active && !moving && (signal === 'first_down' ? index === 0
                : index >= 2 && Math.abs(pose.x - (direction > 0 ? 110 : 10)) < 2);
            const progress = canSignal ? signalProgress(time) : 0;
            ref.body.rotation.y = canSignal && signal === 'first_down' ? 0
                : moving ? (pose.velocity > 0 ? Math.PI / 2 : -Math.PI / 2)
                : pose.z > 26.7 ? Math.PI : 0;
            const stride = Math.sin(pose.travel * 3.8) * Math.min(1, Math.abs(pose.velocity) / 3);
            ref.body.position.y = moving ? Math.abs(Math.sin(pose.travel * 3.8)) * .035 : 0;
            ref.legs.forEach((leg, i) => leg.rotation.x = (i === 0 ? 1 : -1) * stride * .48);
            ref.elbows.forEach(elbow => elbow.rotation.set(0,0,0));
            ref.arms.forEach((arm, i) => {
                arm.rotation.set((i === 0 ? -1 : 1) * stride * .35, 0, 0);
                if (canSignal) {
                    arm.rotation.x = 0;
                    if (signal === 'touchdown') arm.rotation.z = (i === 0 ? -1 : 1) * Math.PI * progress;
                    if (signal === 'first_down' && i === (direction > 0 ? 1 : 0)) arm.rotation.z = direction * Math.PI / 2 * progress;
                }
            });
            if (flagThrow?.official === index && flagThrow.time >= -.18 && flagThrow.time < .45) {
                ref.arms[1].rotation.x = -1.1 * Math.sin(Math.PI * (flagThrow.time + .18) / .63);
                ref.arms[1].rotation.z = .3;
            }
            if (penalty && active && index === 0) {
                ref.body.rotation.y = 0;
                applyPenaltySignal(ref, penalty, penaltyTime);
            }
        });
    };
    return {group, update};
}

// Fixed-step paths make seeking/replaying independent of render frame rate.
export function refereeFormation(line, direction) {
    const offsets = [-8, -8, 0, 0, 20, 20, 25];
    const lanes = [15, 38, -1.25, 54.58, -1.25, 54.58, 26.7];
    return offsets.map((offset, i) => ({x: Math.max(2, Math.min(118, line + direction * offset)),
        z: lanes[i], velocity: 0, travel: 0}));
}

function advance(previous, target, step) {
    const distance = target - previous.x;
    const desired = Math.sign(distance) * Math.min(6, Math.sqrt(2 * 3.5 * Math.abs(distance)));
    const velocity = previous.velocity + Math.max(-3.5 * step, Math.min(3.5 * step, desired - previous.velocity));
    let move = velocity * step;
    if (Math.sign(move) === Math.sign(distance) && Math.abs(move) > Math.abs(distance)) move = distance;
    return {...previous, x: previous.x + move, velocity: Math.abs(distance) < .02 && Math.abs(previous.velocity) <= 3.5 * step ? 0 : velocity,
        travel: previous.travel + Math.abs(move)};
}

export function buildRefereePaths(animation) {
    const line = animation?.line ?? 40, direction = animation?.direction ?? 1;
    const duration = animation?.duration ?? 6, deadAt = animation?.result_at ?? animation?.reveal_at ?? duration;
    const initial = refereeFormation(line, direction);
    const paths = [initial];
    const step = .05;
    const targets = time => {
        const ball = animation?.ball?.length ? sampleTrack(animation.ball, Math.min(time, deadAt)).x : line;
        const final = animation?.ball?.length ? sampleTrack(animation.ball, deadAt).x : line;
        const dead = time >= deadAt;
        const offsets = dead ? [-4, -6, 0, 0, 0, 0, 0] : [-8, -8, -1, -1, 20, 20, 25];
        return offsets.map((offset, i) => Math.max(i < 2 ? 2 : 10, Math.min(i < 2 ? 118 : 110,
            (dead ? final : ball) + direction * offset)));
    };
    for (let tick = 1; tick <= Math.ceil((duration + 40) / step); tick++) {
        const time = tick * step, desired = targets(time);
        paths.push(paths.at(-1).map((pose, i) => advance(pose, time < .6 ? pose.x : desired[i], step)));
    }
    const sample = time => {
        const tick = Math.max(0, Math.min(paths.length - 1, time / step));
        const low = Math.floor(tick), high = Math.min(paths.length - 1, low + 1), fraction = tick - low;
        return paths[low].map((a, i) => {
            const b = paths[high][i];
            return {...a, x: a.x + (b.x - a.x) * fraction,
                velocity: a.velocity + (b.velocity - a.velocity) * fraction,
                travel: a.travel + (b.travel - a.travel) * fraction};
        });
    };
    const reset = (start, nextLine, nextDirection) => {
        const formation = refereeFormation(nextLine, nextDirection), resetPaths = [sample(start)];
        for (let tick = 1; tick <= 800; tick++) resetPaths.push(resetPaths.at(-1).map((pose, i) => advance(pose, formation[i].x, step)));
        return time => {
            const tick = Math.max(0, Math.min(800, time / step)), low = Math.floor(tick), high = Math.min(800, low + 1);
            return resetPaths[low].map((a, i) => {
                const b = resetPaths[high][i], f = tick - low;
                return {...a, x: a.x + (b.x - a.x) * f, velocity: a.velocity + (b.velocity - a.velocity) * f,
                    travel: a.travel + (b.travel - a.travel) * f};
            });
        };
    };
    return {sample, reset};
}

// NFL signals, with articulated forearms for rolling and wrist gestures.
export function applyPenaltySignal(ref, type, time) {
    const blend = signalProgress(time), arms = ref.arms, elbows = ref.elbows;
    arms.forEach(arm => arm.rotation.set(0,0,0));
    elbows.forEach(elbow => elbow.rotation.set(0,0,0));
    if (type === 'false_start') {
        arms.forEach((arm,i) => { arm.rotation.x = -1.05 * blend; arm.rotation.z = (i === 0 ? -.45 : .45) * blend; });
        elbows.forEach((elbow,i) => { elbow.rotation.x = -1.3 * blend; elbow.rotation.z = Math.sin(time*7+i*Math.PI) * .65 * blend; });
    } else if (type === 'encroachment') {
        arms[0].rotation.z = -.48*blend; arms[1].rotation.z = .48*blend;
        elbows[0].rotation.z = 1.5*blend; elbows[1].rotation.z = -1.5*blend;
    } else if (type === 'holding') {
        arms[0].rotation.x = -1.3*blend; elbows[0].rotation.x = -1.1*blend;
        arms[1].rotation.x = -.9*blend; arms[1].rotation.z = .8*blend; elbows[1].rotation.z = -1.6*blend;
    } else if (type === 'defensive_pass_interference') {
        arms.forEach(arm => arm.rotation.x = -Math.PI/2*blend);
    } else if (type === 'face_mask') {
        arms[1].rotation.x = -1.4*blend; elbows[1].rotation.x = -1.5*blend;
        arms[0].rotation.x = -1.1*blend; arms[0].rotation.z = -.4*blend;
        elbows[0].rotation.x = -1.3*blend;
    }
}
