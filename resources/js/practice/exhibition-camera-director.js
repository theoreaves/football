import * as THREE from 'three';

// A single display camera interpolates poses and projection matrices. Endpoint
// camera objects keep their exact perspective/orthographic definitions.
export function createExhibitionCameraDirector(initial) {
    let active=initial, transition=null;
    const display=new THREE.Camera(), lastDisplay=new THREE.Camera();
    initial.updateMatrixWorld();lastDisplay.position.copy(initial.position);lastDisplay.quaternion.copy(initial.quaternion);lastDisplay.projectionMatrix.copy(initial.projectionMatrix);
    return {
        get camera(){return transition ? display : active;},
        get transitioning(){return Boolean(transition);},
        use(camera,{duration=.6}={}){
            if(camera===active && !transition && camera.position.equals(lastDisplay.position) && camera.quaternion.equals(lastDisplay.quaternion) && camera.projectionMatrix.equals(lastDisplay.projectionMatrix))return;
            const previous=transition ? display : lastDisplay;
            previous.updateMatrixWorld();camera.updateMatrixWorld();
            transition=duration>0 ? {position:previous.position.clone(),quaternion:previous.quaternion.clone(),projection:previous.projectionMatrix.clone(),elapsed:0,duration} : null;
            active=camera;
            if(transition){display.position.copy(previous.position);display.quaternion.copy(previous.quaternion);display.projectionMatrix.copy(previous.projectionMatrix);display.projectionMatrixInverse.copy(display.projectionMatrix).invert();display.updateMatrixWorld();}
        },
        tick(delta){
            if(!transition){lastDisplay.position.copy(active.position);lastDisplay.quaternion.copy(active.quaternion);lastDisplay.projectionMatrix.copy(active.projectionMatrix);return;}
            transition.elapsed=Math.min(transition.duration,transition.elapsed+Math.max(0,Math.min(.1,delta)));
            const t=transition.elapsed/transition.duration,blend=t*t*(3-2*t);
            display.position.lerpVectors(transition.position,active.position,blend);
            display.quaternion.slerpQuaternions(transition.quaternion,active.quaternion,blend);
            for(let i=0;i<16;i++)display.projectionMatrix.elements[i]=transition.projection.elements[i]*(1-blend)+active.projectionMatrix.elements[i]*blend;
            display.projectionMatrixInverse.copy(display.projectionMatrix).invert();display.updateMatrixWorld();
            lastDisplay.position.copy(display.position);lastDisplay.quaternion.copy(display.quaternion);lastDisplay.projectionMatrix.copy(display.projectionMatrix);
            if(t===1)transition=null;
        },
    };
}
