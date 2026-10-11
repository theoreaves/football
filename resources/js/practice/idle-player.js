// Additive, presentation-only idle pose. Called after the base pose resets each frame.
export function idlePlayerPose(model,time,index){
    if(!model.visible || Math.abs(model.rotation.z)>.15 || Math.abs(model.rotation.x)>.3)return;
    model.position.y+=Math.sin(time*1.8+index*.7)*.012;
    if(!model.userData.cradlingBall && !model.userData.holderKneel)model.userData.arms?.forEach((arm,i)=>arm.rotation.x+=Math.sin(time*1.1+index+i)*.035);
}
