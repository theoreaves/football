import {entrancePose,greetingPose} from './tunnel-entrance.js';
import * as THREE from 'three';
import {buildCheerleader,animateCheerleader} from './cheerleader-model.js';
import {mascotReaction} from './sideline-mascot.js';

export const CHEERLEADER_SLOTS = [16,21,26,94,99,104].flatMap(x=>[-7,-10].map(z=>({x,z})));
const skins=['#edc3a1','#b9825b','#754d38','#d8a780','#503628','#c88f6b'];
const hairs=['#33231b','#a87735','#171717','#64402d','#c09a55','#39251e'];

export function buildSidelineCheerleaders(scene,appearance,textureFor=()=>null) {
    const home=appearance.home || {},profile=home.cheerleaders || {};
    if(profile.enabled===false || profile.enabled===0 || profile.enabled==='0')return {group:null,update(){},cue(){},reset(){}};
    const group=new THREE.Group();group.name='home-cheerleaders';scene.add(group);
    const template=buildCheerleader({primary:home.primary_color,secondary:home.secondary_color,...profile},home.team_logo,textureFor);
    template.visible=false;group.add(template);
    const parts=[];
    template.traverse(part=>{
        if(!part.isMesh)return;
        const tinted=['cheer-skin','cheer-hair'].includes(part.material.name);
        const material=tinted?part.material.clone():part.material;
        if(tinted)material.color.set('#ffffff');
        const mesh=new THREE.InstancedMesh(part.geometry,material,CHEERLEADER_SLOTS.length);
        mesh.name=`cheer-squad-${part.name}`;mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);mesh.frustumCulled=false;
        for(let i=0;i<CHEERLEADER_SLOTS.length;i++)mesh.setColorAt(i,new THREE.Color(tinted?(part.material.name==='cheer-skin'?skins[i%skins.length]:hairs[i%hairs.length]):'#ffffff'));
        group.add(mesh);parts.push({source:part,mesh});
    });
    let time=0,remaining=0;
    const update=(delta,entranceSeconds=null)=>{
        const step=Number.isFinite(delta)?Math.max(0,Math.min(.1,delta)):0;time+=step;remaining=Math.max(0,remaining-step);
        template.getObjectByName('cheer-team-logo')?.onBeforeRender();
        CHEERLEADER_SLOTS.forEach((slot,index)=>{
            animateCheerleader(template,time,index,remaining>0 || (entranceSeconds && typeof entranceSeconds==='object' && entranceSeconds.home<12));
            template.position.x=slot.x;template.position.z=slot.z;template.rotation.y=remaining>0?0:Math.PI;
            template.position.y=0;template.scale.setScalar(1);
            if(entranceSeconds!==null){const pose=typeof entranceSeconds==='object'?greetingPose(index,slot,entranceSeconds.cheer,entranceSeconds.home):entrancePose('home',index,slot,entranceSeconds);template.position.set(pose.x,pose.moving?pose.bob:0,pose.z);template.rotation.y=pose.yaw;template.scale.setScalar(pose.visible?1:0);}
            template.updateMatrixWorld(true);
            for(const {source,mesh} of parts)mesh.setMatrixAt(index,source.matrixWorld);
        });
        for(const {mesh} of parts)mesh.instanceMatrix.needsUpdate=true;
    };
    update(0);
    return {group,update,cue(cue){remaining=Math.max(remaining,mascotReaction(cue));},reset(){remaining=0;update(0);}};
}
