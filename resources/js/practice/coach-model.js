import * as THREE from 'three';
import {buildPlayerFace} from './player-face.js';
export function buildCoach(profile = {}, role = 'head') {
    const group = new THREE.Group(); group.name = `coach-${role}`;
    const material = color => new THREE.MeshStandardMaterial({color,roughness:.85});
    const skin=material(profile.skin || '#b9825b'),shirt=material(profile.shirt || '#174880'),pants=material(profile.pants || '#303b48'),black=material('#17202b');
    const box=(name,size,position,paint,parent=group)=>{const mesh=new THREE.Mesh(new THREE.BoxGeometry(...size),paint);mesh.name=name;mesh.position.set(...position);parent.add(mesh);return mesh;};
    const bulk=({slim:.88,average:1,stocky:1.2}[profile.build] || 1);
    box('coach-shirt',[.65*bulk,.72,.35*bulk],[0,1.28,0],shirt);
    box('coach-hips',[.55*bulk,.22,.32],[0,.85,0],pants);
    for(const sign of [-1,1]) {
        box('coach-leg',[.21,.72,.25],[sign*.17,.43,0],pants);
        box('coach-shoe',[.24,.13,.38],[sign*.17,.07,.055],black);
        const arm=new THREE.Group();arm.name=`coach-arm-${sign}`;arm.position.set(sign*(.36*bulk),1.56,0);group.add(arm);
        box('coach-sleeve',[.2,.26,.25],[0,-.12,0],shirt,arm);
        box('coach-forearm',[.145,.4,.16],[0,-.43,0],skin,arm);
        box('coach-hand',[.16,.15,.17],[0,-.68,0],skin,arm);
        if(sign===-1) arm.rotation.x=-.35;
        if(role==='head' && sign===-1) {
            const board=box('clipboard',[.27,.36,.035],[0,-.65,.14],material('#b98a50'),arm);
            box('clipboard-paper',[.23,.29,.01],[0,0,.025],material('#f4f1df'),board);
            box('clipboard-clip',[.1,.035,.025],[0,.15,.025],black,board);
        }
    }
    box('coach-neck',[.17,.15,.17],[0,1.73,0],skin);
    const head=new THREE.Mesh(new THREE.SphereGeometry(1,20,14),skin);head.scale.set(.225,.21,.18);head.position.set(0,1.97,.08);group.add(head);
    group.add(buildPlayerFace({appearance:{hair:profile.hair || 'short',hair_color:profile.hair_color || '#30251f'}},skin));
    const band=new THREE.Mesh(new THREE.TorusGeometry(.24,.018,6,24,Math.PI),black);band.name='headset';band.position.set(0,1.99,.06);group.add(band);
    for(const sign of [-1,1]) box('headset-earcup',[.07,.13,.1],[sign*.235,1.95,.075],black);
    box('headset-microphone',[.025,.025,.22],[-.235,1.87,.2],black);
    group.userData.profile=profile;
    return group;
}
export function buildSidelineCoaches(scene,appearance) {
    const coaches=[];
    for(const side of ['home','away']) {
        Object.entries(appearance[side]?.staff || {}).forEach(([role,profile],index)=>{
            const coach=buildCoach(profile,role);coach.position.set(47+index*7,0,side==='home'?-3.7:57.03);coach.rotation.y=side==='home'?0:Math.PI;
            scene.add(coach);coaches.push({coach,index,x:coach.position.x});
        });
    }
    let time=0;
    return {update(delta){time+=Math.min(.1,Math.max(0,delta));for(const {coach,index,x} of coaches){coach.position.x=x+Math.sin(time*.13+index)*.65;const arm=coach.getObjectByName('coach-arm-1');arm.rotation.x=Math.sin(time*.7+index)*.07;}}};
}
