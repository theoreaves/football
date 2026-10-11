import {tunnelPath} from './tunnel-layout.js';
// Pregame-only movement: straight onto the field, then toward the team sideline.
export function entrancePose(side,index,target,seconds) {
    const home=side==='home',lane=index%2?-.9:.9;
    const points=tunnelPath(side,lane,target);
    const delay=Math.floor(index/2)*.16,speed=6,distances=points.slice(1).map((p,i)=>Math.hypot(p.x-points[i].x,p.z-points[i].z));
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
