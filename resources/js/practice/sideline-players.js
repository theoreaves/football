import * as THREE from 'three';
import {helmetShellGeometry} from './helmet-shell.js';
import {playerKey,sidelineSlot} from './unit-exchange.js';
// Shared geometry and instanced parts keep a full bench inexpensive to draw.
export function buildSidelinePlayers(scene, roster, appearance) {
    const groups = [], dummy = new THREE.Object3D();
    for (const side of ['home','away']) {
        const people = roster[side] || [], kit = appearance[side]?.uniform || {};
        if (!people.length) continue;
        const teamArea = new THREE.Group();teamArea.name = `${side}-team-area`;scene.add(teamArea);
        const centerZ = side === 'home' ? -8.9 : 62.23;
        const addBox = (size,position,color) => {
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(...size),new THREE.MeshStandardMaterial({color,roughness:.9}));
            mesh.position.set(...position);teamArea.add(mesh);return mesh;
        };
        // The boundary/officials' strip stays clear in front of the team area.
        addBox([86,.04,8.4],[60,-.03,centerZ],'#244b35');
        for (const z of [centerZ-4.2,centerZ+4.2]) addBox([86,.03,.12],[60,.015,z],'#f3f1d9');
        for (const x of [17,103]) addBox([.12,.03,8.4],[x,.015,centerZ],'#f3f1d9');
        const benchZ = side === 'home' ? -12.3 : 65.63;
        for (const x of [35,60,85]) {
            addBox([8.5,.14,.9],[x,.55,benchZ],kit.helmet || '#eeeeee');
            addBox([8.5,.65,.12],[x,1,benchZ + (side === 'home' ? -.4 : .4)],kit.shirt || '#3997ff');
            for (const dx of [-3,3]) addBox([.12,.55,.65],[x+dx,.27,benchZ],'#657587');
        }
        const parts = [
            [[.88,.66,.5],[0,1.25,0],kit.shirt || '#3997ff'],
            [[.69,.25,.44],[0,.83,0],kit.pants || '#eeeeee'],
            [[.26,.62,.28],[-.23,.47,0],kit.pants || '#eeeeee'],
            [[.26,.62,.28],[.23,.47,0],kit.pants || '#eeeeee'],
            [[.24,.21,.25],[-.23,.22,0],kit.socks || '#ffffff'],
            [[.24,.21,.25],[.23,.22,0],kit.socks || '#ffffff'],
            [[.27,.14,.42],[-.23,.08,.06],'#17202b'],
            [[.27,.14,.42],[.23,.08,.06],'#17202b'],
            [[.25,.5,.3],[-.55,1.25,0],kit.shirt || '#3997ff'],
            [[.25,.5,.3],[.55,1.25,0],kit.shirt || '#3997ff'],
            [[.45,.42,.36],[0,1.97,.08],'#c78e61','head'],
            [[1,1,1],[0,2.03,0],kit.helmet || '#eeeeee','helmet'],
            [[.5,.07,.06],[0,1.87,.3],kit.facemask || '#17202b'],
        ];
        for (const [size,offset,color,shape] of parts) {
            const geometry = shape === 'helmet' ? helmetShellGeometry()
                : shape === 'head' ? new THREE.SphereGeometry(.5,12,8).scale(...size) : new THREE.BoxGeometry(...size);
            const mesh = new THREE.InstancedMesh(geometry,new THREE.MeshStandardMaterial({color:shape === 'head' ? '#ffffff' : color,roughness:.75}),people.length);
            if (shape === 'head') people.forEach((person,i)=>mesh.setColorAt(i,new THREE.Color(person.skin_tone || '#c78e61')));
            mesh.name = `${side}-sideline-players`; mesh.frustumCulled = false; scene.add(mesh);
            groups.push({mesh,people,side,offset});
        }
    }
    let previous = '';
    return {update(active) {
        const signature = [...active].sort().join('|');
        if (signature === previous && previous !== '') return;
        previous = signature;
        for (const {mesh,people,side,offset} of groups) {
            people.forEach((person,i) => {
                const slot = sidelineSlot(side,i), height = (person.height_inches || 73)/73;
                const sign = side === 'home' ? 1 : -1;
                dummy.position.set(slot.x + offset[0]*sign,offset[1]*height,slot.z + offset[2]*sign);
                dummy.rotation.set(0,side === 'home' ? 0 : Math.PI,0);
                dummy.scale.setScalar(active.has(playerKey({...person,side})) ? 0 : height);
                dummy.updateMatrix();mesh.setMatrixAt(i,dummy.matrix);
            });
            mesh.instanceMatrix.needsUpdate = true;
        }
    }};
}
