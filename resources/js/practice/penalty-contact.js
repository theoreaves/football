import * as THREE from 'three';
import {sampleEnginePlay} from './engine-timeline.js';

const smooth = value => {
    const t = Math.max(0, Math.min(1, value));
    return t*t*(3-2*t);
};
const same = (player, identity) => player.team === identity.team && player.role === identity.role;

// Select a pair once from the recorded foul frame, never from render history.
export function buildPenaltyContact(animation, moment) {
    if (!animation || animation.no_snap || !moment || !['holding','defensive_pass_interference'].includes(moment.type)) return null;
    if (moment.type === 'defensive_pass_interference'
        && (!animation.passing || !animation.receiver_role || !Number.isFinite(animation.catch_at))) return null;
    const frame = sampleEnginePlay(animation, moment.at);
    const actor = frame.players.find(p => p.team === moment.team && p.role === moment.role);
    if (!actor) return null;
    const victim = moment.type === 'holding'
        ? frame.players.filter(p => p.team === 'defense' && /^(DE|DT)/.test(p.role))
            .sort((a,b) => Math.hypot(a.x-actor.x,a.z-actor.z)-Math.hypot(b.x-actor.x,b.z-actor.z)
                || a.role.localeCompare(b.role))[0]
        : frame.players.find(p => p.team === 'offense' && p.role === animation.receiver_role);
    if (!victim) return null;
    const at = moment.at;
    const end = Math.min(animation.duration, moment.type === 'holding' ? at+.65 : animation.catch_at-.02);
    if (end <= at) return null;
    return {type:moment.type, at, start:Math.max(0,at-.75), end,
        releaseEnd:Math.min(animation.duration,end+.4), direction:animation.direction === -1 ? -1 : 1,
        side:actor.z < victim.z ? -1 : 1,
        actor:{team:actor.team,role:actor.role}, victim:{team:victim.team,role:victim.role}};
}

// Pure per-time choreography: safe for seeking, pausing and deterministic replays.
// Inputs are the current rendered positions, including pass-pocket offsets.
export function samplePenaltyContact(plan, time, poses) {
    if (!plan || time <= plan.start || time >= plan.releaseEnd) return null;
    const actor = poses.find(p => same(p,plan.actor)), victim = poses.find(p => same(p,plan.victim));
    if (!actor || !victim) return null;
    const approach = smooth((time-plan.start)/Math.max(.01,plan.at-plan.start));
    const release = 1-smooth((time-plan.end)/Math.max(.01,plan.releaseEnd-plan.end));
    const blend = approach*release;
    const holding = plan.type === 'holding';
    // The blocker/receiver remains on its rendered track. Only the defender
    // closes the short gap; a distant player can never teleport into contact.
    const anchor = holding ? actor : victim, defender = holding ? victim : actor;
    const target = holding ? {x:anchor.x+plan.direction*.95,z:anchor.z+.12*plan.side}
        : {x:anchor.x-plan.direction*.2,z:anchor.z+plan.side*.95};
    const dx=target.x-defender.x, dz=target.z-defender.z, distance=Math.hypot(dx,dz);
    const limit = Math.min(4, Math.max(.25,(plan.at-plan.start)*5));
    const scale = Math.min(1,limit/Math.max(.001,distance))*blend;
    const moved = {...defender,x:defender.x+dx*scale,z:defender.z+dz*scale};
    const contactDistance = Math.hypot(moved.x-anchor.x,moved.z-anchor.z);
    const touch = 1-smooth((contactDistance-1.25)/.5);
    // DPI hands and receiver reaction stop before arrival; position releases
    // smoothly afterward without overriding catch, possession or tackle poses.
    const actionRelease = holding ? release : 1-smooth((time-plan.at)/Math.max(.01,plan.end-plan.at));
    const strength = approach*actionRelease*touch;
    return {actor:holding?{...actor}:moved, victim:holding?moved:{...victim}, strength,
        strain:Math.sin(Math.max(0,time-plan.at)*12)*strength, blend};
}

// Aim the articulated hand at a shoulder-pad point in world space.
function reach(mesh, index, target, strength) {
    const arm=mesh.userData.arms?.[index], elbow=mesh.userData.elbows?.[index];
    if (!arm || !elbow || !arm.parent) return;
    mesh.updateMatrixWorld(true);
    const local=arm.parent.worldToLocal(target.clone()), shoulder=arm.position.clone();
    const delta=local.clone().sub(shoulder), upper=.46, forearm=Math.hypot(.35,.04);
    if (delta.length()<.001) return;
    const direction=delta.clone().normalize(), distance=Math.min(upper+forearm-.002,Math.max(.12,delta.length()));
    const along=(upper*upper+distance*distance-forearm*forearm)/(2*distance);
    const hint=new THREE.Vector3(index===0?-1:1,.3,0);
    let outward=hint.addScaledVector(direction,-hint.dot(direction));
    if (outward.lengthSq()<.00001) outward=new THREE.Vector3(0,0,1).addScaledVector(direction,-direction.z);
    outward.normalize();
    const upperDirection=direction.clone().multiplyScalar(along)
        .addScaledVector(outward,Math.sqrt(Math.max(0,upper*upper-along*along))).normalize();
    const armPose=new THREE.Quaternion().setFromUnitVectors(new THREE.Vector3(0,-1,0),upperDirection);
    const wrist=local.clone().sub(shoulder.addScaledVector(upperDirection,upper))
        .applyQuaternion(armPose.clone().invert()).normalize();
    const elbowPose=new THREE.Quaternion().setFromUnitVectors(new THREE.Vector3(0,-.35,.04).normalize(),wrist);
    arm.quaternion.slerp(armPose,strength); elbow.quaternion.slerp(elbowPose,strength);
}

export function applyPenaltyContact(plan, time, framePlayers, meshes) {
    if (!plan) return;
    const poses=framePlayers.map((player,i) => ({team:player.team,role:player.role,
        x:meshes[i].position.x,z:meshes[i].position.z}));
    const sample=samplePenaltyContact(plan,time,poses);
    if (!sample) return;
    const actor=meshes[framePlayers.findIndex(p=>same(p,plan.actor))];
    const victim=meshes[framePlayers.findIndex(p=>same(p,plan.victim))];
    if (!actor || !victim) return;
    actor.position.x=sample.actor.x; actor.position.z=sample.actor.z;
    victim.position.x=sample.victim.x; victim.position.z=sample.victim.z;
    const strength=sample.strength;
    if (strength<=0) return;
    actor.userData.penaltyContactPose = true;
    const holding=plan.type==='holding';
    const facing=Math.atan2(victim.position.x-actor.position.x,victim.position.z-actor.position.z);
    const turn=Math.atan2(Math.sin(facing-actor.rotation.y),Math.cos(facing-actor.rotation.y));
    actor.rotation.y+=turn*strength;
    // Holding: a sustained two-hand grip, with the rusher twisting to escape.
    // DPI: a brief shove/restriction and an upright receiver stumble.
    if (holding) {
        const faceBlocker=Math.atan2(actor.position.x-victim.position.x,actor.position.z-victim.position.z)+.3*plan.side;
        victim.rotation.y+=Math.atan2(Math.sin(faceBlocker-victim.rotation.y),Math.cos(faceBlocker-victim.rotation.y))*strength;
    } else victim.rotation.y+=.14*plan.side*strength;
    if (victim.userData.waist) victim.userData.waist.rotation.x+=(holding ? .18 : .1)*strength;
    victim.rotation.z+=plan.side*(holding ? .035 : .12)*strength;
    if (holding) {
        victim.userData.legs?.forEach((leg,i)=>leg.rotation.x=(i===0?-.22:.24)*strength+leg.rotation.x*(1-strength));
        victim.userData.arms?.forEach((arm,i)=>arm.rotation.x=(-.75+(i===0?1:-1)*sample.strain*.25)*strength+arm.rotation.x*(1-strength));
    }
    victim.updateMatrixWorld(true);
    actor.updateMatrixWorld(true);
    const pads=[-.32,.32].map(x=>victim.localToWorld(new THREE.Vector3(x,1.45,.23)));
    // Match pad sides in the blocker's local space instead of crossing wrists.
    if (holding) {
        pads.sort((a,b)=>actor.worldToLocal(a.clone()).x-actor.worldToLocal(b.clone()).x);
        for (const index of [0,1]) reach(actor,index,pads[index],strength);
    } else {
        const near=pads.sort((a,b)=>a.distanceToSquared(actor.position)-b.distanceToSquared(actor.position))[0];
        reach(actor,plan.side===-1?1:0,near,strength);
    }
}


export function resetPenaltyContactPose(mesh) {
    if (!mesh.userData.penaltyContactPose) return;
    // Normal running/blocking owns X (and arm Z). Clear the extra IK axes
    // before that pose is rebuilt, including when seeking out of the foul.
    mesh.userData.arms?.forEach(arm => { arm.rotation.y = 0; });
    mesh.userData.elbows?.forEach(elbow => { elbow.rotation.y = 0; elbow.rotation.z = 0; });
    delete mesh.userData.penaltyContactPose;
}
