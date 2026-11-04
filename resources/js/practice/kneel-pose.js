const ease=value=>{const t=Math.max(0,Math.min(1,value));return t*t*(3-2*t);};

export function kneelActive(animation,phase) {
    return animation?.call==='kneel' && !animation.no_snap && ['play','result'].includes(phase);
}

export function applyKneelPose(mesh,animation,player,phase,elapsed,direction) {
    if(!kneelActive(animation,phase))return false;
    mesh.rotation.y=(player.team==='offense'?1:-1)*direction*Math.PI/2;
    if(player.team!=='offense' || player.role!=='QB')return false;
    const blend=ease((elapsed-(animation.kneel_at ?? 1.1))/.6);
    mesh.position.y=-.55*blend;
    mesh.rotation.x=0;mesh.rotation.z=0;
    if(mesh.userData.waist)mesh.userData.waist.rotation.x=.10*blend;
    mesh.userData.legs?.forEach((leg,index)=>{leg.rotation.x=(index===0?-1.25:.30)*blend;});
    mesh.userData.knees?.forEach((knee,index)=>{knee.rotation.x=(index===0?1.35:1.65)*blend;});
    if(elapsed>=.6){
        mesh.userData.arms?.forEach((arm,index)=>{arm.rotation.x=-.9;arm.rotation.z=index===0?-.12:.12;});
        mesh.userData.elbows?.forEach(elbow=>{elbow.rotation.x=-.9;});
    }
    return true;
}
