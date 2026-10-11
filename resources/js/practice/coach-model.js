import * as THREE from 'three';
import {fitLogo} from './logo-fit.js';
import {buildPlayerFace} from './player-face.js';
export function buildCoach(profile = {}, role = 'head', logoUrl = null, textureFor = () => null) {
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
    const capped=profile.hat===true || profile.hat===1 || profile.hat==='1';
    if(capped) {
        // Keep hair under the fitted crown; the face and ears remain visible.
        group.traverse(object=>{if(['hair','hair-curl','hair-back'].includes(object.name))object.visible=false;});
        const paint=material(profile.hat_color || profile.shirt || '#174880');
        const crown=new THREE.Mesh(new THREE.SphereGeometry(1,24,12,0,Math.PI*2,0,Math.PI/2),paint);
        crown.name='coach-cap';crown.scale.set(.245,.24,.205);crown.position.set(0,2.01,.08);group.add(crown);
        const brim=new THREE.Mesh(new THREE.CylinderGeometry(1,1,.022,24),paint);
        brim.name='coach-cap-brim';brim.scale.set(.23,1,.19);brim.position.set(0,2.025,.23);group.add(brim);
    }
    const texture=logoUrl && textureFor(logoUrl);
    if(texture) {
        const logo=(name,maxWidth,maxHeight,x,y,z,curved=false)=>{
            const geometry=(width,height)=>{
                const shape=new THREE.PlaneGeometry(width,height,curved?8:1,curved?6:1);
                if(curved){const points=shape.attributes.position;for(let i=0;i<points.count;i++){const px=points.getX(i),py=points.getY(i)+y-2.01;points.setZ(i,.08+.205*Math.sqrt(Math.max(0,1-(px/.245)**2-(py/.24)**2))+.004-z);}shape.computeVertexNormals();}
                return shape;
            };
            const mesh=new THREE.Mesh(geometry(maxWidth,maxHeight),new THREE.MeshBasicMaterial({map:texture,transparent:true,depthWrite:false}));
            mesh.name=name;mesh.position.set(x,y,z);group.add(mesh);let dimensions='';
            mesh.onBeforeRender=()=>{if(!texture.image)return;const key=`${texture.image.width}:${texture.image.height}`;if(key===dimensions)return;const size=fitLogo(texture.image.width,texture.image.height,maxWidth,maxHeight);mesh.geometry.dispose();mesh.geometry=geometry(size.width,size.height);dimensions=key;};
        };
        logo('coach-shirt-logo',.18,.18,-.15*bulk,1.43,.175*bulk+.004);
        if(capped)logo('coach-cap-logo',.14,.105,0,2.14,.26,true);
    }
    const band=new THREE.Mesh(new THREE.TorusGeometry(.24,.018,6,24,Math.PI),black);band.name='headset';band.position.set(0,1.99,.06);group.add(band);
    for(const sign of [-1,1]) box('headset-earcup',[.07,.13,.1],[sign*.235,1.95,.075],black);
    box('headset-microphone',[.025,.025,.22],[-.235,1.87,.2],black);
    group.userData.profile=profile;
    return group;
}
export function buildSidelineCoaches(scene,appearance,textureFor = () => null) {
    const coaches=[];
    for(const side of ['home','away']) {
        Object.entries(appearance[side]?.staff || {}).forEach(([role,profile],index)=>{
            const coach=buildCoach(profile,role,appearance[side]?.team_logo,textureFor);coach.position.set(47+index*7,0,side==='home'?-3.7:57.03);coach.rotation.y=side==='home'?0:Math.PI;
            scene.add(coach);coaches.push({coach,index,x:coach.position.x});
        });
    }
    let time=0;
    return {update(delta){time+=Math.min(.1,Math.max(0,delta));for(const {coach,index,x} of coaches){coach.position.x=x+Math.sin(time*.13+index)*.65;const arm=coach.getObjectByName('coach-arm-1');arm.rotation.x=Math.sin(time*.7+index)*.07;}}};
}
