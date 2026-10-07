import { buildStadiumCrowd } from './stadium-crowd.js';
import * as THREE from 'three';
import { fitLogo } from './logo-fit.js';
import { scoreboardText } from './scoreboard.js';

export const STADIUMS = {
    classic_oval: { decks: 3, rows: 8, step: 1.05, width: 1.8 },
    grand_bowl: { decks: 3, rows: 11, step: 1, width: 2 },
    horseshoe: { decks: 3, rows: 8, step: 1.1, width: 1.8, gap: 'end' },
    open_corners: { decks: 3, rows: 8, step: 1.05, width: 1.8, gap: 'corners' },
    steep_bowl: { decks: 3, rows: 9, step: 1.5, width: 1.55 },
    skyline: { decks: 3, rows: 8, step: 1.1, width: 1.8, asymmetric: true, tower: true },
    sideline_canopy: { decks: 3, rows: 8, step: 1.05, width: 1.8, roof: 'canopy' },
    twin_roof: { decks: 3, rows: 9, step: 1, width: 1.8, roof: 'twin' },
    indoor_dome: { decks: 3, rows: 8, step: 1.05, width: 1.8, roof: 'dome' },
    retractable_roof: { decks: 3, rows: 8, step: 1.05, width: 1.8, roof: 'open' },
};

function band(rx, rz, width, y, rise, allowed) {
    const points = [], indices = [], segments = 160;
    for (let i = 0; i <= segments; i++) {
        const angle = i / segments * Math.PI * 2;
        for (const outer of [0, 1]) points.push((rx + outer * width) * Math.cos(angle), y + outer * rise, (rz + outer * width) * Math.sin(angle));
        if (i < segments && allowed((i + .5) / segments * Math.PI * 2)) {
            const a = i * 2; indices.push(a, a + 2, a + 1, a + 1, a + 2, a + 3);
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(points, 3));
    geometry.setIndex(indices); geometry.computeVertexNormals(); return geometry;
}

export function buildStadium(home, document, away = {}, crowd = {}) {
    const design = STADIUMS[home.stadium_style] || STADIUMS.classic_oval;
    const group = new THREE.Group(); group.name = 'stadium'; group.position.set(60, 0, 26.665);
    group.userData.style = home.stadium_style in STADIUMS ? home.stadium_style : 'classic_oval';
    const paint = color => new THREE.MeshStandardMaterial({ color, roughness: .85, side: THREE.DoubleSide });
    const walls = paint(home.stadium_wall_color || '#657587');
    const seats = paint(home.stadium_seat_color || '#315b85');
    const roofPaint = paint(home.stadium_roof_color || '#cbd5e1');
    const allowed = angle => {
        if (design.gap === 'end') return Math.cos(angle) < .83;
        if (design.gap === 'corners') return Math.abs(Math.sin(2 * angle)) < .88;
        return true;
    };
    const add = (geometry, material, name) => {
        const mesh = new THREE.Mesh(geometry, material); mesh.name = name; group.add(mesh); return mesh;
    };
    const box = (size, position, material, name) => {
        const mesh = add(new THREE.BoxGeometry(...size), material, name); mesh.position.set(...position); return mesh;
    };
    // Continuous grass apron beneath the entire bowl, including open corners.
    const ground = add(new THREE.PlaneGeometry(360, 280), paint('#18392a'), 'stadium-ground');
    ground.rotation.x = -Math.PI / 2;
    ground.position.y = -.26;
    const fanSeats = [];
    let offset = 0, height = 1;
    for (let deck = 0; deck < design.decks; deck++) {
        const deckGroup = new THREE.Group(); deckGroup.name = `seating-deck-${deck + 1}`; group.add(deckGroup);
        const count = Math.floor(210 + offset * 3), chairCount = count * design.rows;
        const chairs = new THREE.InstancedMesh(new THREE.BoxGeometry(.65, .5, .65), seats, chairCount);
        chairs.name = `seats-${deck + 1}`;
        const dummy = new THREE.Object3D(); let instance = 0;
        for (let row = 0; row < design.rows; row++) {
            const rx = 80 + offset, rz = 48 + offset;
            const terrace = new THREE.Mesh(band(rx, rz, design.width, height, 0, allowed), walls);
            terrace.name = 'terrace'; deckGroup.add(terrace);
            const riser = new THREE.Mesh(band(rx + design.width, rz + design.width, 0, height, design.step, allowed), walls);
            deckGroup.add(riser);
            for (let i = 0; i < count; i++) {
                const angle = i / count * Math.PI * 2;
                if (!allowed(angle) || (design.asymmetric && deck === 2 && Math.sin(angle) > .2)) continue;
                dummy.position.set((rx + .7) * Math.cos(angle), height + .3, (rz + .7) * Math.sin(angle));
                dummy.rotation.y = -angle - Math.PI / 2;
                fanSeats.push({ x: dummy.position.x, y: dummy.position.y, z: dummy.position.z, yaw: dummy.rotation.y });
                dummy.updateMatrix(); chairs.setMatrixAt(instance++, dummy.matrix);
            }
            offset += design.width; height += design.step;
        }
        chairs.count = instance; chairs.instanceMatrix.needsUpdate = true; deckGroup.add(chairs);
        add(band(80 + offset, 48 + offset, 4, height, 0, allowed), walls, 'concourse');
        offset += 4; height += 2;
    }
    group.add(buildStadiumCrowd(fanSeats, crowd, home, away));
    // Lower terrace closes the open end beneath the horseshoe's upper decks.
    if (design.gap === 'end') add(band(80, 48, 8, 1, 3, angle => Math.cos(angle) >= .83), seats, 'end-terrace');
    if (design.tower) {
        box([75, 14, 8], [0, height - 8, 48 + offset], walls, 'suite-tower');
        box([70, 8, .2], [0, height - 7, 44 + offset], paint('#243a50'), 'suite-windows');
    }
    if (design.roof === 'canopy' || design.roof === 'twin') {
        for (const sign of [-1, 1]) {
            const roof = box([130, .7, design.roof === 'twin' ? 24 : 16], [0, height + 3, sign * (45 + offset - 6)], roofPaint, 'sideline-roof');
            roof.rotation.x = sign * .12;
        }
    }
    if (design.roof === 'open') add(band(80, 37, offset + 13, height + 4, 3, () => true), roofPaint, 'open-center-roof');
    if (design.roof === 'dome') {
        const roof = new THREE.Mesh(new THREE.SphereGeometry(1, 64, 24, 0, Math.PI * 2, 0, Math.PI / 2), roofPaint.clone());
        roof.name = 'dome-roof'; roof.scale.set(82 + offset, 29, 50 + offset); roof.position.y = height;
        // Transparent from outside for high camera views; still reads as an enclosed roof inside.
        roof.material.transparent = true; roof.material.opacity = .3; roof.material.depthWrite = false; group.add(roof);
        add(band(80 + offset, 48 + offset, 0, 0, height, () => true), walls, 'dome-wall');
    }
    const canvas = document.createElement('canvas'); canvas.width = 1024; canvas.height = 512;
    const context = canvas.getContext('2d');
    const texture = new THREE.CanvasTexture(canvas); texture.colorSpace = THREE.SRGBColorSpace;
    const displayPaint = new THREE.MeshBasicMaterial({ map: texture });
    for (const sign of [-1, 1]) {
        box([1.3, 12, 25], [sign * 84, 20, 0], walls, 'scoreboard-frame');
        for (const z of [-8, 8]) box([1, 14, 1], [sign * 84, 7, z], walls, 'scoreboard-support');
        const display = add(new THREE.PlaneGeometry(23, 10), displayPaint, 'stadium-scoreboard');
        display.position.set(sign * 83.25, 20, 0); display.rotation.y = -sign * Math.PI / 2;
    }
    let last = '', displayState = null, displayNames = null;
    const logoImages = {};
    const updateScoreboard = (state, names) => {
        displayState = state; displayNames = names;
        const key = JSON.stringify([state, names]); if (key === last) return; last = key;
        context.fillStyle = '#06101b'; context.fillRect(0, 0, 1024, 512);
        context.textAlign = 'center'; context.fillStyle = '#ffffff'; context.font = 'bold 38px sans-serif';
        context.fillText((home.name || 'HOME') + ' STADIUM', 512, 54, 960);
        if (!state || !names) {
            context.font = 'bold 64px sans-serif'; context.fillText('WEBSPORTS FOOTBALL', 512, 270, 960);
        } else {
            const labels = scoreboardText(state, names);
            for (const [side, x] of [['away', 260], ['home', 764]]) {
                const logo = logoImages[side];
                if (logo) {
                    const size = fitLogo(logo.naturalWidth || logo.width, logo.naturalHeight || logo.height, 70, 70);
                    context.drawImage(logo, x - 210 + (70 - size.width) / 2, 94 + (70 - size.height) / 2, size.width, size.height);
                }
                context.font = 'bold 44px sans-serif'; context.fillText(names[side], logo ? x + 35 : x, 140, logo ? 350 : 450);
                context.fillStyle = '#f8e18b'; context.font = 'bold 118px monospace'; context.fillText(String(state[`${side}_score`]), x, 272);
                context.fillStyle = '#ffffff';
                for (let mark = 0; mark < 3; mark++) {
                    const left = x - 75 + mark * 55;
                    if (mark < (state.timeouts?.[side] ?? 3)) context.fillRect(left, 302, 40, 8);
                    else { context.strokeStyle = '#ffffff'; context.lineWidth = 2; context.strokeRect(left, 302, 40, 8); }
                }
            }
            context.font = 'bold 44px monospace'; context.fillText(labels.clock, 512, 396);
            context.font = '28px sans-serif'; context.fillText(labels.situation, 512, 462, 960);
        }
        texture.needsUpdate = true;
    };
    updateScoreboard(null, null);
    for (const [side, team] of [['home', home], ['away', away]]) {
        if (!team.team_logo) continue;
        const image = document.createElement('img');
        image.onload = () => { logoImages[side] = image; last = ''; updateScoreboard(displayState, displayNames); };
        image.src = team.team_logo;
    }
    return { group, updateScoreboard };
}
