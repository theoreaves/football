const ease=value=>{const t=Math.max(0,Math.min(1,value));return t*t*(3-2*t);};

export function tackleFallAngle(style) {
    return Math.PI*(style==='wrap'?.35:style==='lunge'?.53:style==='drive'?.25:.46);
}

// Joint poses only: roots, holder events and the football remain authoritative.
export function applyAnimationVariety(mesh,animation,player,phase,elapsed,postElapsed=0) {
    const profile=animation?.variety;
    if(profile?.version===1 && profile.pose_only){
        applyExpandedVariety(mesh,animation,player,phase,elapsed,postElapsed);
        return;
    }
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

// These outcomes use joint motion only. Do not alter catch/recovery timing,
// goal-line crossings, sideline exits, kick trajectories or pursuit paths.
function applyExpandedVariety(mesh,animation,player,phase,elapsed,postElapsed) {
    if(!['play','settling','result','celebration','big_play'].includes(phase))return;
    const {movement,handling,finish,family}=animation.variety;
    if(typeof family!=='string' || ![movement,handling].every(v=>Number.isInteger(v)&&v>=0&&v<5)
        || !Number.isInteger(finish)||finish<0||finish>3)return;
    const special=family.startsWith('special_');
    const holder=[...(animation.ballHolders || [])].reverse().find(event=>event[0]<=elapsed);
    const same=(team,role)=>player.team===team&&player.role===role;
    const owns=holder && holder[1] && same(holder[1],holder[2]);
    const turnover=['interception','fumble','defensive_return'].includes(family);
    // A turnover variation starts only after the actual defensive recovery.
    const recovered=owns && (holder[1]==='defense' || family==='special_onside_recovered');
    const kickAt=(animation.events || []).find(event=>event[1]==='Kick' || event[1]==='Onside kick')?.[0] ?? 1.2;
    const kicker=special && same('offense','QB') && elapsed>kickAt+.12 && elapsed<kickAt+1.1;
    const sack=family==='sack' && same('offense','QB') && elapsed>1.55;
    const carry=family!=='sack' && owns && player.role!=='C'
        && (!['touchdown','safety','sideline'].includes(family) || same('offense',animation.carrier) || holder[1]==='defense') && elapsed>holder[0]+.3
        && (!turnover || recovered) && (!special || holder[1]==='defense' || animation.declared_onside);
    if(!kicker && !sack && !carry)return;
    const contact=animation.contact_at;
    const contactActor=same(animation.contact_carrier_team || 'offense',animation.carrier);
    const falling=contact!=null && contactActor && elapsed>=contact;
    const resultAt=animation.result_at ?? animation.reveal_at ?? 5.3;
    const after=phase==='play' ? Math.max(0,elapsed-resultAt) : postElapsed;
    const finished=elapsed>=resultAt || phase==='result';
    const recovery=phase==='result' ? 1-ease(postElapsed/1.6) : 1;
    const start=kicker?kickAt+.12:sack?1.55:holder[0]+.3;
    const blend=ease((elapsed-start)/.25)*recovery;
    if(blend<.001)return;
    const wave=Math.sin((elapsed-start)*[8,9,10.5,11.5,13][movement]);
    // Five different stride/torso rhythms. Keep the body root authoritative.
    if(mesh.userData.waist){
        mesh.userData.waist.rotation.x+=([-.06,-.025,.02,.055,.09][movement]+wave*.025)*blend;
    }
    if(!falling && !finished){
        mesh.userData.knees?.forEach((knee,i)=>{knee.rotation.x+=Math.max(0,wave*(i===0?1:-1))*[.08,.12,.16,.20,.24][movement]*blend;});
    }
    // Work on the free arm, so existing ball-carry/catch joints keep their job.
    const carryingArm=mesh.userData.carryingArm ?? (player.team==='defense'?1:player.role==='RB'?0:1);
    const free=carryingArm===0?1:0;
    const arm=mesh.userData.arms?.[free],elbow=mesh.userData.elbows?.[free];
    if(arm)arm.rotation.x+=([-.18,-.09,.025,.12,.23][handling]+wave*.045)*blend;
    if(elbow)elbow.rotation.x+=[-.2,-.12,-.04,.06,.15][handling]*blend;
    if(kicker){
        // Follow-through starts after contact with the ball, never before it.
        const follow=Math.sin(Math.PI*Math.min(1,(elapsed-kickAt-.12)/.98))*blend;
        mesh.userData.legs?.[0] && (mesh.userData.legs[0].rotation.x+=[-.18,-.25,-.32,-.39][finish]*follow);
    }else if(falling){
        const fall=ease((elapsed-contact)/.55)*blend;
        mesh.userData.knees?.forEach((knee,i)=>{knee.rotation.x+=[.12,.22,.34,.46][finish]*(i===0?1:.6)*fall;});
    }else if(finished){
        const finishBlend=ease(after/.4)*blend;
        if(arm)arm.rotation.z+=(free===0?-1:1)*[.1,.24,.4,.58][finish]*finishBlend;
        if(elbow)elbow.rotation.x+=[.08,.18,.3,.44][finish]*finishBlend;
    }
}
