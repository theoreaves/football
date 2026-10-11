import {tunnelPath} from './tunnel-layout.js';
// Pregame-only movement: straight onto the field, then toward the team sideline.
export function entrancePose(side,index,target,seconds,route=null,stagger=true) {
    const home=side==='home',lane=index%2?-.9:.9;
    const points=route || tunnelPath(side,lane,target);
    const delay=stagger?Math.floor(index/2)*.16:0;
    const speed=6,distances=points.slice(1).map((p,i)=>Math.hypot(p.x-points[i].x,p.z-points[i].z));
    const length=distances.reduce((a,b)=>a+b,0),t=Math.max(0,seconds-delay);
    // Ease into a six-yard-per-second jog.
    let distance=t<.6?speed*t*t/1.2:speed*(t-.3);
    distance=Math.min(length,distance);
    if(distance>=length)return {...target,yaw:home?0:Math.PI,moving:false,visible:true,bob:0,complete:true};
    let remaining=distance;
    for(let i=0;i<distances.length;i++){
        if(remaining<=distances[i] && distances[i]>0){const a=points[i],b=points[i+1],f=remaining/distances[i];return {x:a.x+(b.x-a.x)*f,z:a.z+(b.z-a.z)*f,yaw:Math.atan2(b.x-a.x,b.z-a.z),moving:distance<length,visible:seconds>=delay,bob:Math.sin(t*12)*.035,complete:false};}
        remaining-=distances[i];
    }
    return {...target,yaw:home?0:Math.PI,moving:false,visible:true,bob:0,complete:true};
}

export function entranceSchedule(cheerleaders,mascot) {
    const cheerStart=35,leadStart=cheerStart+(cheerleaders?7:0),homeStart=leadStart+(mascot?2:0);
    return {cheerStart,leadStart,homeStart,end:homeStart+35};
}
export function greetingSlot(index){return {x:-2+Math.floor(index/2)*3,z:16.665+(index%2?3:-3)};}
export function greetingPose(index,target,time,homeTime){
    const slot=greetingSlot(index);
    if(homeTime<12){
        // Go straight out to the greeting corridor rather than along the sidelines.
        const origin=tunnelPath('home',index%2?-.9:.9,slot)[0];
        const direct=entrancePose('home',index,slot,time,[origin,{x:slot.x,z:origin.z},slot]);
        if(direct.complete)direct.yaw=index%2?Math.PI:0;
        return direct;
    }
    return entrancePose('home',index,target,homeTime-12,[slot,{x:14,z:slot.z},{x:14,z:-6},{x:target.x,z:-6},target],false);
}
