import * as THREE from 'three';

// All dimensions are in the existing field coordinate system (one unit per yard).
// This group is presentation-only; no game state is changed.
export function buildChainGang(scene, document) {
    const group = new THREE.Group();
    group.name = 'sideline-chain-gang';
    scene.add(group);

    const orange = new THREE.MeshStandardMaterial({ color: 0xff7518, roughness: .75 });
    const black = new THREE.MeshStandardMaterial({ color: 0x17202b, roughness: .85 });
    const shirt = new THREE.MeshStandardMaterial({ color: 0xf4dc72, roughness: .8 });
    const pants = new THREE.MeshStandardMaterial({ color: 0x35475b, roughness: .85 });
    const skin = new THREE.MeshStandardMaterial({ color: 0xb98863, roughness: .9 });
    const metal = new THREE.MeshStandardMaterial({ color: 0xc4b090, metalness: .25, roughness: .55 });
    const makeBox = (parent, size, material, position) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(...size), material);
        mesh.position.set(...position);
        mesh.castShadow = true;
        parent.add(mesh);
        return mesh;
    };
    const makePole = () => {
        const pole = new THREE.Group();
        makeBox(pole, [.13, 2.5, .13], metal, [0, 1.25, 0]);
        makeBox(pole, [.47, .5, .3], orange, [0, 2.17, 0]);
        group.add(pole);
        return pole;
    };
    const rear = makePole();
    const front = makePole();
    const down = makePole();
    // Make the down box distinctive, taller than the chain poles.
    makeBox(down, [.76, .75, .4], black, [0, 2.19, 0]);
    const canvas = document.createElement('canvas');
    canvas.width = 128; canvas.height = 128;
    const context = canvas.getContext('2d');
    const numberTexture = new THREE.CanvasTexture(canvas);
    numberTexture.colorSpace = THREE.SRGBColorSpace;
    const numberMaterial = new THREE.MeshBasicMaterial({map: numberTexture, transparent: false, side: THREE.DoubleSide});
    const number = new THREE.Mesh(new THREE.PlaneGeometry(.57, .57), numberMaterial);
    number.position.set(0, 2.19, .211);
    number.renderOrder = 10;
    down.add(number);
    const numberBack = number.clone();
    numberBack.position.z = -.211;
    numberBack.rotation.y = Math.PI;
    down.add(numberBack);
    let lastDown = null;
    const setDown = value => {
        const shown = Math.max(1, Math.min(4, Number(value) || 1));
        if (shown === lastDown) return;
        lastDown = shown;
        context.fillStyle = '#ff8a18';
        context.fillRect(0, 0, 128, 128);
        context.fillStyle = '#101820';
        context.font = 'bold 105px sans-serif';
        context.textAlign = 'center';
        context.textBaseline = 'middle';
        context.fillText(String(shown), 64, 69);
        numberTexture.needsUpdate = true;
    };

    const chain = new THREE.Mesh(new THREE.CylinderGeometry(.027, .027, 1, 8), metal);
    group.add(chain);
    const makeWorker = () => {
        const worker = new THREE.Group();
        makeBox(worker, [.66, .73, .34], shirt, [0, 1.32, 0]);
        makeBox(worker, [.42, .24, .33], pants, [0, .84, 0]);
        for (const sign of [-1, 1]) {
            makeBox(worker, [.21, .71, .23], pants, [sign * .14, .41, 0]);
            makeBox(worker, [.2, .63, .24], shirt, [sign * .43, 1.29, 0]);
            makeBox(worker, [.21, .12, .32], black, [sign * .14, .06, .08]);
        }
        const head = new THREE.Mesh(new THREE.SphereGeometry(.24, 12, 10), skin);
        head.position.set(0, 1.98, 0);
        worker.add(head);
        makeBox(worker, [.52, .08, .35], orange, [0, 2.19, .03]);
        group.add(worker);
        return worker;
    };
    const crew = [makeWorker(), makeWorker(), makeWorker()];
    // Field of play begins at z=0; the near sideline is at negative z.
    // A higher z is closer to the field, not closer to the camera.
    const sideline = -2.9;
    const chainLane = sideline - 0.65; // Behind the down box, away from the field.
    const downMarkerLane = sideline + 1.20; // Field-facing lane, clearly in front of chains.
    const clamp = x => Math.max(0, Math.min(120, Number(x) || 0));

    const update = (scrimmage, lineToGain, downNumber, direction = 1, visible = true) => {
        const los = clamp(scrimmage);
        const goal = lineToGain == null ? null : clamp(lineToGain);
        // Chains mark the original ten-yard series, not the new ball spot.
        // In goal-to-go, clamp to the playable field edge.
        const originalSpot = goal == null ? los : clamp(goal - (direction >= 0 ? 10 : -10));
        group.visible = visible;
        setDown(downNumber);
        down.position.set(los, 0, downMarkerLane);
        // LOS and line to gain are different when the ball has advanced
        // within a series: the chain is anchored to the series markers.
        rear.position.set(originalSpot, 0, chainLane);
        front.position.set(goal ?? los, 0, chainLane);
        rear.visible = front.visible = chain.visible = goal !== null;
        if (goal !== null) {
            const distance = Math.abs(goal - originalSpot);
            chain.scale.y = Math.max(.01, distance);
            chain.position.set((originalSpot + goal) / 2, .055, chainLane);
            chain.rotation.z = Math.PI / 2;
        }
        crew[0].position.set(originalSpot - 1.0, 0, chainLane - .25);
        crew[1].position.set((goal ?? los) + 1.0, 0, chainLane - .25);
        crew[2].position.set(los - 1.0, 0, downMarkerLane - .15);
        crew[0].visible = crew[1].visible = goal !== null;
    };
    return { group, update };
}
