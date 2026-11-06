const ease=value=>{const t=Math.max(0,Math.min(1,value));return t*t*(3-2*t);};

export function tackleFallAngle(style) {
    return Math.PI*(style==='wrap'?.35:style==='lunge'?.53:style==='drive'?.25:.46);
}

// Joint poses only: roots, holder events and the football remain authoritative.
export function applyAnimationVariety(mesh,animation,player,phase,elapsed,postElapsed=0) {
    const profile=animation?.variety;
    if(profile?.version!==1 || !['play','settling','result'].includes(phase)
        || player.team!=='offense' || player.role!==(animation.receiver_role || animation.carrier))return;
    const carrying=profile.family==='run' || profile.family==='scramble';
    const handling=profile.handling,finish=profile.finish;
    if(!Number.isInteger(handling) || handling<0 || handling>4 || !Number.isInteger(finish) || finish<0 || finish>3)return;
    const catchAt=animation.catch_at ?? 3.8;
    const contact=animation.contact_at;
    const fall=contact!=null?ease((elapsed-contact)/.55):0;
    const reach=!carrying && phase==='play'
        ? ease((elapsed-catchAt+.32)/.2)*(1-ease((elapsed-catchAt-.25)/.25)):0;
    if(reach>0){
        const armX=[-1.08,-1.25,-1.46,-1.58,-1.32][handling];
        const width=[.16,.23,.19,.32,.10][handling];
        mesh.userData.arms?.forEach((arm,index)=>{
            arm.rotation.x+=(armX-arm.rotation.x)*reach;
            arm.rotation.z+=((index===0?-width:width)-arm.rotation.z)*reach;
        });
        mesh.userData.elbows?.forEach(elbow=>{elbow.rotation.x+=([-.55,-.42,-.25,-.65,-.80][handling]-elbow.rotation.x)*reach;});
    }
    const held=profile.family!=='incomplete' && elapsed>(profile.family==='scramble'?2.25:profile.family==='run'?1:catchAt)+.3 && fall<.1;
    if(held){
        const side=mesh.userData.carryingArm ?? (carrying?0:1);
        const arm=mesh.userData.arms?.[side],elbow=mesh.userData.elbows?.[side];
        if(arm)arm.rotation.x=-.67+[-.12,-.05,0,.08,.15][handling];
        if(elbow)elbow.rotation.x=-1.3+[-.10,-.04,.02,.09,.16][handling];
    }
    if(fall>0){
        const recovery=phase==='result'?1-ease(postElapsed/1.5):1;
        const blend=fall*recovery;
        if(blend<.001)return;
        mesh.userData.legs?.forEach((leg,index)=>{leg.rotation.x=(index===0?[-.42,-.65,-.27,-.85][finish]:[.28,.45,.62,.15][finish])*blend;});
        mesh.userData.knees?.forEach((knee,index)=>{knee.rotation.x=(index===0?[.2,.45,.65,.8][finish]:[.45,.2,.35,.6][finish])*blend;});
        if(mesh.userData.waist)mesh.userData.waist.rotation.x=[.08,.20,-.06,.32][finish]*blend;
    }else if(profile.family==='incomplete' && elapsed>catchAt+.45){
        const blend=ease((elapsed-catchAt-.45)/.45);
        if(mesh.userData.waist)mesh.userData.waist.rotation.x=[.04,-.06,.12,.2][finish]*blend;
        mesh.userData.arms?.forEach((arm,index)=>{arm.rotation.x+=([-.12,-.25,-.42,-.60][finish]-arm.rotation.x)*blend;arm.rotation.z=(index===0?-1:1)*(.08+handling*.025);});
    }
}
