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

export function stadiumDesign(home = {}) {
    const preset=STADIUMS[home.stadium_style] || STADIUMS.classic_oval,profile=home.stadium || {};
    const number=(value,min,max,fallback,integer=false)=>{
        const n=Number(value);return value!==null && value!=='' && Number.isFinite(n) && n>=min && n<=max && (!integer || Number.isInteger(n))?n:fallback;
    };
    return {...preset,decks:number(profile.tiers,1,3,preset.decks,true),rows:number(profile.rows,4,12,preset.rows,true),step:number(profile.row_height,.75,1.75,preset.step)};
}

export function stadiumTierColor(home,deck) {
    const profile=home.stadium || {},single=profile.one_color;
    const separate=single===false || single===0 || single==='0';
    return separate && profile.tier_colors?.[['lower','middle','upper'][deck]] || home.stadium_seat_color || '#315b85';
}

export function stadiumTierWallColor(home,deck) {
    const profile=home.stadium || {},single=profile.wall_one_color;
    const separate=single===false || single===0 || single==='0';
    return separate && profile.tier_wall_colors?.[['lower','middle','upper'][deck]] || home.stadium_wall_color || '#657587';
}

export function buildStadium(home, document, away = {}, crowd = {}) {
    const design = stadiumDesign(home),profile=home.stadium || {};
    const enabled=value=>value!==false && value!==0 && value!=='0';
    const group = new THREE.Group(); group.name = 'stadium'; group.position.set(60, 0, 26.665);
    group.userData.style = home.stadium_style in STADIUMS ? home.stadium_style : 'classic_oval';
    const paint = color => new THREE.MeshStandardMaterial({ color, roughness: .85, side: THREE.DoubleSide });
    const walls = paint(home.stadium_wall_color || '#657587');
    const seats = paint(stadiumTierColor(home,0));
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
        const deckWalls=stadiumTierWallColor(home,deck)===(home.stadium_wall_color || '#657587')?walls:paint(stadiumTierWallColor(home,deck));
        const deckGroup = new THREE.Group(); deckGroup.name = `seating-deck-${deck + 1}`; group.add(deckGroup);
        const count = Math.floor(210 + offset * 3), chairCount = count * design.rows;
        const chairs = new THREE.InstancedMesh(new THREE.BoxGeometry(.65, .5, .65), deck===0?seats:paint(stadiumTierColor(home,deck)), chairCount);
        chairs.name = `seats-${deck + 1}`;
        const dummy = new THREE.Object3D(); let instance = 0;
        for (let row = 0; row < design.rows; row++) {
            const rx = 80 + offset, rz = 48 + offset;
            const terrace = new THREE.Mesh(band(rx, rz, design.width, height, 0, allowed), deckWalls);
            terrace.name = 'terrace'; deckGroup.add(terrace);
            const riser = new THREE.Mesh(band(rx + design.width, rz + design.width, 0, height, design.step, allowed), deckWalls);
            riser.name='riser';deckGroup.add(riser);
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
        add(band(80 + offset, 48 + offset, 4, height, 0, allowed), deckWalls, 'concourse');
        offset += 4; height += 2;
    }
    const fans = buildStadiumCrowd(fanSeats,crowd,home,away);
    group.add(fans);
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
    // Tunnels sit in the clear apron in front of the seating, behind each end zone.
    if(enabled(profile.tunnels))for(const [side,x,team] of [['home',-70,home],['away',70,away]]){
        const z=side==='home'?-10:10,color=paint(team.primary_color || home.primary_color || '#315b85');
        box([10,.4,8],[x,.2,z],walls,`tunnel-${side}-floor`);
        for(const edge of [-1,1])box([.6,5,8],[x+edge*5,2.5,z],color,`tunnel-${side}-wall`);
        box([10.6,.6,8],[x,5,z],color,`tunnel-${side}-roof`);
        box([9.4,4.4,.2],[x,2.4,z+(side==='home'?-4:4)],paint('#101820'),`tunnel-${side}-interior`);
    }
    const lighting=['corners','rim'].includes(profile.lighting)?profile.lighting:'corners';
    const lamp=new THREE.MeshBasicMaterial({color:'#fff4ce'});
    if(lighting==='corners')for(const xsign of [-1,1])for(const zsign of [-1,1]){
        const x=xsign*(82+offset),z=zsign*(52+offset),y=height+10;
        box([1.2,y,1.2],[x,y/2,z],walls,'lighting-tower');
        box([10,3,1],[x,y,z],walls,'lighting-bank');
        for(let i=0;i<6;i++)box([1.1,1.8,.15],[x-4+i*1.6,y,z-zsign*.6],lamp,'stadium-lamp');
    }
    else for(let i=0;i<16;i++){
        const angle=i/16*Math.PI*2,x=(80+offset)*Math.cos(angle),z=(48+offset)*Math.sin(angle);
        const bank=box([6,1.2,1],[x,height+2,z],lamp,'rim-light');bank.rotation.y=-angle-Math.PI/2;
    }
    const canvas = document.createElement('canvas'); canvas.width = 1024; canvas.height = 512;
    const context = canvas.getContext('2d');
    const texture = new THREE.CanvasTexture(canvas); texture.colorSpace = THREE.SRGBColorSpace;
    const displayPaint = new THREE.MeshBasicMaterial({ map: texture });
    const boardScale={small:.75,medium:1,large:1.35}[profile.scoreboard_size] || 1;
    const boardSigns=profile.scoreboards==='left'?[-1]:profile.scoreboards==='right'?[1]:[-1,1];
    for (const sign of boardSigns) {
        box([1.3, 12*boardScale, 25*boardScale], [sign * 84, 20, 0], walls, 'scoreboard-frame');
        for (const z of [-8*boardScale, 8*boardScale]) box([1, 14, 1], [sign * 84, 7, z], walls, 'scoreboard-support');
        const display = add(new THREE.PlaneGeometry(23*boardScale, 10*boardScale), displayPaint, 'stadium-scoreboard');
        display.position.set(sign * 83.25, 20, 0); display.rotation.y = -sign * Math.PI / 2;
    }
    let last = '', displayState = null, displayNames = null;
    const logoImages = {};
    const updateScoreboard = (state, names) => {
        displayState = state; displayNames = names;
        const key = JSON.stringify([state, names]); if (key === last) return; last = key;
        context.fillStyle = '#06101b'; context.fillRect(0, 0, 1024, 512);
        context.textAlign = 'center'; context.fillStyle = '#ffffff'; context.font = 'bold 38px sans-serif';
        context.fillText(home.stadium?.name || (home.name || 'HOME') + ' STADIUM', 512, 54, 960);
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
    return {group,updateScoreboard,updateCrowd(delta){fans.userData.animation?.tick(delta);},
        crowdCue(cue){if(cue.type==='crowd')fans.userData.animation?.cheer(cue.mood==='groan'?'away':'home');
            else if(cue.type==='third_down')fans.userData.animation?.cheer('home');},
        resetCrowd(){fans.userData.animation?.reset();}};
}
