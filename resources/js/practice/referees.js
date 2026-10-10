import * as THREE from 'three';

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
        for (const sign of [-1, 1]) {
            box(body, [.18, .7, .22], black, [sign * .14, .42, 0]);
            box(body, [.2, .12, .33], black, [sign * .14, .06, .06]);
        }
        const head = new THREE.Mesh(new THREE.SphereGeometry(.21, 12, 10), skin);
        head.position.set(0, 1.84, 0); body.add(head);
        box(body, [.45, .1, .38], cap, [0, 2.02, .025]);
        box(body, [.32, .035, .18], cap, [0, 1.99, .24]);
        const arms = [-1, 1].map(sign => {
            const arm = new THREE.Group(); arm.position.set(sign * .34, 1.52, 0); body.add(arm);
            box(arm, [.19, .28, .23], white, [0, -.14, 0]);
            box(arm, [.08, .28, .012], black, [0, -.14, .12]);
            box(arm, [.15, .32, .17], skin, [0, -.43, 0]);
            box(arm, [.17, .14, .19], skin, [0, -.64, 0]);
            return arm;
        });
        return {body, arms};
    };
    const crew = [official(white), official(black), official(black)];
    const update = ({line = 40, spot = line, direction = 1, signal = null, time = 0, active = false, visible = true}) => {
        group.visible = visible;
        const clamp = x => Math.max(10, Math.min(110, x));
        const progress = active ? signalProgress(time) : 0;
        // Sideline judges stay outside the field and follow the dead-ball spot.
        const x = clamp(line + (spot - line) * (active ? 1 : 0));
        crew[0].body.position.set(clamp(line - direction * 8), 0, 15);
        crew[1].body.position.set(x, 0, -1.25);
        crew[2].body.position.set(x, 0, 54.58);
        crew.forEach((ref, index) => {
            ref.body.rotation.y = index === 2 ? Math.PI : 0;
            ref.arms.forEach((arm, armIndex) => {
                arm.rotation.set(0, 0, 0);
                if (signal === 'touchdown' && index > 0) arm.rotation.z = (armIndex === 0 ? -1 : 1) * Math.PI * progress;
                // Facing the near sideline makes local +X the offense's direction.
                if (signal === 'first_down' && index === 0 && armIndex === (direction > 0 ? 1 : 0)) {
                    arm.rotation.z = (direction > 0 ? 1 : -1) * Math.PI / 2 * progress;
                }
            });
            if (active && signal === 'first_down' && index === 0) ref.body.position.set(clamp(spot - direction * 4), 0, 15);
        });
    };
    return {group, update};
}
