import * as THREE from 'three';
import {buildPlayerFace} from './player-face.js';
import {fitLogo} from './logo-fit.js';

export function buildCheerleader(profile={},logoUrl=null,textureFor=()=>null) {
    const model=new THREE.Group();model.name='cheerleader';
    const paint=(name,color)=>{const material=new THREE.MeshStandardMaterial({color,roughness:.85});material.name=name;return material;};
    const skin=paint('cheer-skin',profile.skin || '#b9825b'),primary=paint('cheer-primary',profile.primary || '#174880');
    const secondary=paint('cheer-secondary',profile.secondary || '#ffffff'),accent=paint('cheer-accent',profile.accent || '#ffffff');
    const hair=paint('cheer-hair',profile.hair_color || '#38271f');
    const box=(name,size,position,material,parent=model)=>{const mesh=new THREE.Mesh(new THREE.BoxGeometry(...size),material);mesh.name=name;mesh.position.set(...position);parent.add(mesh);return mesh;};
    const ball=(name,size,position,material,parent=model)=>{const mesh=new THREE.Mesh(new THREE.SphereGeometry(1,16,10),material);mesh.name=name;mesh.scale.set(...size);mesh.position.set(...position);parent.add(mesh);return mesh;};
    ball('top',[.27,.32,.17],[0,1.34,0],primary);
    box('top-stripe',[.53,.08,.025],[0,1.4,.167],secondary);
    box('waistband',[.49,.085,.32],[0,1.08,0],secondary);
    const skirt=new THREE.Mesh(new THREE.CylinderGeometry(.25,.34,.33,16),primary);skirt.name='skirt';skirt.scale.z=.65;skirt.position.y=.88;model.add(skirt);
    box('skirt-trim',[.65,.05,.4],[0,.72,0],secondary);
    for(const sign of [-1,1]) {
        const leg=new THREE.Group();leg.name=`cheer-leg-${sign}`;leg.position.set(sign*.15,.76,0);model.add(leg);
        ball('leg',[.095,.3,.1],[0,-.29,0],skin,leg);
        box('boot',[.19,.2,.23],[0,-.6,0],secondary,leg);
        box('shoe',[.2,.1,.31],[0,-.71,.045],secondary,leg);
        const arm=new THREE.Group();arm.name=`cheer-arm-${sign}`;arm.position.set(sign*.3,1.56,0);model.add(arm);
        ball('sleeve',[.115,.13,.13],[0,-.06,0],primary,arm);
        ball('arm',[.065,.24,.075],[0,-.29,0],skin,arm);
        ball('hand',[.075,.085,.075],[0,-.54,0],skin,arm);
        const pom=new THREE.Group();pom.name='pom-pom';pom.position.set(0,-.65,0);arm.add(pom);
        ball('pom-core',[.16,.16,.16],[0,0,0],accent,pom);
        // Crossed ribbons give volume without dozens of strands per pom-pom.
        for(let i=0;i<6;i++) {
            const strand=box('pom-ribbon',[.04,.42,.055],[0,0,0],i%2?accent:secondary,pom);
            strand.rotation.set(i*.7,i*.9,i*.55);
        }
    }
    box('neck',[.12,.13,.12],[0,1.69,0],skin);
    ball('head',[.21,.2,.17],[0,1.91,.08],skin);
    const face=buildPlayerFace({appearance:{hair:'long',hair_color:profile.hair_color || '#38271f',mouth:'smile',brow:'arched'}},skin);
    face.position.y-=.06;model.add(face);
    face.traverse(mesh=>{if(['hair','hair-back'].includes(mesh.name))mesh.material=hair;});
    ball('ponytail',[.11,.25,.1],[0,1.78,-.17],hair);
    for(const sign of [-1,1])box('hair-bow',[.1,.07,.035],[sign*.065,2.08,-.08],accent).rotation.z=sign*.4;
    const texture=logoUrl && textureFor(logoUrl);
    if(texture) {
        const logo=new THREE.Mesh(new THREE.PlaneGeometry(.17,.17),new THREE.MeshBasicMaterial({map:texture,transparent:true,depthWrite:false}));
        logo.name='cheer-team-logo';logo.position.set(0,1.28,.174);model.add(logo);
        logo.onBeforeRender=()=>{if(!texture.image)return;const size=fitLogo(texture.image.width,texture.image.height,.17,.17);logo.scale.set(size.width/.17,size.height/.17,1);};
    }
    animateCheerleader(model,0,0,false);
    return model;
}

export function animateCheerleader(model,time,index=0,cheering=false) {
    const t=Number.isFinite(time)?time:0,beat=t*3.2+index*.12;
    model.position.y=cheering?Math.abs(Math.sin(beat))*.08:Math.sin(beat)*.015+.015;
    model.rotation.z=Math.sin(beat)*.035;
    for(const [i,sign] of [-1,1].entries()) {
        const arm=model.getObjectByName(`cheer-arm-${sign}`);
        const segment=Math.floor(t/3)%4;
        arm.rotation.x=cheering?-2.8+Math.sin(beat+i)*.17:segment===0?-.25:segment===1?-1.55:segment===2?-2.55:-.65;
        arm.rotation.z=sign*(cheering?.45:segment===1?1.25:.25)+Math.sin(beat+i)*.07;
        model.getObjectByName(`cheer-leg-${sign}`).rotation.x=Math.sin(beat+i*Math.PI)*(cheering?.12:.06);
    }
}
