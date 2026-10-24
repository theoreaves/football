import {entrancePose} from './tunnel-entrance.js';
import {buildMascot} from './mascot-model.js';

// A clear lane behind the home benches, outside the player exchange routes.
// Walking and pauses use presentation time only, never the simulation RNG.
export function mascotPose(time) {
    const t=Math.max(0,Number.isFinite(time)?time:0), cycle=t%100;
    let x, moving=false, facing=0;
    if(cycle<8){x=35;facing=Math.PI;}
    else if(cycle<46){x=35+(cycle-8)*50/38;moving=true;facing=Math.PI/2;}
    else if(cycle<58){x=85;facing=Math.PI;}
    else if(cycle<96){x=85-(cycle-58)*50/38;moving=true;facing=-Math.PI/2;}
    else {x=35;facing=Math.PI;}
    return {x,z:-16,y:moving?Math.abs(Math.sin(t*6))*.035:0,moving,facing,wave:!moving};
}

export function mascotReaction(cue) {
    return cue?.type==='touchdown'?9:cue?.type==='home_turnover'?7:cue?.type==='mascot_big_play'?5:0;
}

export function mascotBigPlayCue(animation,before,after,penalty=null) {
    if(!animation || animation.no_snap || before?.possession!=='home' || !Number.isFinite(animation.gain) || animation.gain<20
        || ['touchdown','interception','fumble','penalty','sack','incomplete'].includes(animation.outcome)
        || after?.penalty_pending || (penalty && penalty.accepted!==false))return null;
    return {time:Math.max(animation.reveal_at ?? 0,animation.duration ?? 0)+.2,type:'mascot_big_play'};
}

export function buildSidelineMascot(scene,appearance,textureFor=()=>null,storageKey=null) {
    const profile=appearance.home?.mascot;
    if(!profile || profile.enabled === false || profile.enabled === 0 || profile.enabled === '0' || (!profile.character && !profile.name))return {update(){},cue(){},reset(){},save(){},model:null};
    const model=buildMascot(profile,appearance.home?.team_logo,textureFor);scene.add(model);
    const arms=[-1,1].map(sign=>model.getObjectByName(`mascot-arm-${sign}`));
    const legs=[-1,1].map(sign=>model.getObjectByName(`mascot-leg-${sign}`));
    let time=0,cheering=0,cheerTime=0;
    if(storageKey)try{const saved=Number(sessionStorage.getItem(storageKey));if(Number.isFinite(saved) && saved>=0)time=saved%100;}catch{ /* Storage is optional. */ }
    const update=(delta,entranceSeconds=null)=>{
        const step=Number.isFinite(delta)?Math.max(0,Math.min(.1,delta)):0;
        // Pause the route while cheering so the mascot never snaps back afterward.
        if(cheering>0){cheering=Math.max(0,cheering-step);cheerTime+=step;}else time+=step;
        // The normal game's home mascot enters at the left tunnel. For the
        // championship only, the home team owns the RIGHT tunnel and greets
        // its players outside the right end line before reaching the bench.
        const championship=appearance.home?.championship_field === true;
        const greeting={x:125,z:36.665};
        const bench={x:35,z:-16};
        const entry=entranceSeconds===null?null:championship
            ? (entranceSeconds<12
                ? entrancePose('away',0,greeting,entranceSeconds,[{x:133,z:36.665},greeting],false)
                : entrancePose('away',0,bench,entranceSeconds-12,[greeting,{x:127,z:36.665},{x:127,z:-16},bench],false))
            : entrancePose('home',0,bench,entranceSeconds);
        if(entry)time=0;
        const pose=entry?{x:entry.x,z:entry.z,y:entry.bob,moving:entry.moving,facing:entry.yaw}:mascotPose(time),active=cheering>0;
        model.visible=entry?entry.visible:true;
        model.position.set(pose.x,active?Math.abs(Math.sin(cheerTime*7))*.13:pose.y,pose.z);
        const facing=active?0:pose.facing;
        const turn=Math.atan2(Math.sin(facing-model.rotation.y),Math.cos(facing-model.rotation.y));
        model.rotation.y+=turn*(step===0?1:Math.min(1,step*5));
        arms.forEach((arm,i)=>{
            arm.rotation.x=active?-2.7+Math.sin(cheerTime*7+i)*.18:pose.moving?Math.sin(time*6+i*Math.PI)*.35: i===1?-2.4+Math.sin(time*4)*.25:0;
            arm.rotation.z=(i===0?-1:1)*(active?.35:.15);
        });
        legs.forEach((leg,i)=>leg.rotation.x=!active && pose.moving?Math.sin(time*6+i*Math.PI)*.35:0);
    };
    update(0);
    return {model,update,save(){if(storageKey)try{sessionStorage.setItem(storageKey,String(time%100));}catch{ /* Storage is optional. */ }},cue(cue){const duration=mascotReaction(cue);if(duration){cheering=duration;cheerTime=0;}},reset(){cheering=0;cheerTime=0;update(0);}};
}
