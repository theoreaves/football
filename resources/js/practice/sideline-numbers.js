import * as THREE from 'three';
import {mergeGeometries} from 'three/addons/utils/BufferGeometryUtils.js';
export function sidelineNumbers(people, kit, document) {
    const canvas = document.createElement('canvas');canvas.width=640;canvas.height=640;
    const ctx=canvas.getContext('2d');ctx.textAlign='center';ctx.font='bold 44px sans-serif';
    ctx.fillStyle=kit.number || '#ffffff';ctx.strokeStyle=kit.number_outline || '#111111';ctx.lineWidth=5;
    for(let number=0;number<100;number++) {
        const x=(number%10)*64+32,y=Math.floor(number/10)*64+50;
        ctx.strokeText(String(number),x,y);ctx.fillText(String(number),x,y);
    }
    const texture=new THREE.CanvasTexture(canvas);texture.colorSpace=THREE.SRGBColorSpace;
    const material=new THREE.MeshBasicMaterial({map:texture,transparent:true,depthWrite:false});
    material.onBeforeCompile=shader=>{
        shader.vertexShader='attribute vec2 numberCell;\n'+shader.vertexShader;
        shader.vertexShader=shader.vertexShader.replace('#include <uv_vertex>','#include <uv_vertex>\n vMapUv = (vMapUv + numberCell) / 10.0;');
    };
    material.customProgramCacheKey=()=> 'sideline-number-atlas-v1';
    const front=new THREE.PlaneGeometry(.65,.65).translate(0,1.25,.265);
    const back=new THREE.PlaneGeometry(.65,.65).rotateY(Math.PI).translate(0,1.25,-.265);
    const geometry=mergeGeometries([front,back]);front.dispose();back.dispose();
    geometry.setAttribute('numberCell',new THREE.InstancedBufferAttribute(new Float32Array(people.flatMap(p=>{
        const n=Math.max(0,Math.min(99,Math.trunc(Number(p.number)||0)));return [n%10,9-Math.floor(n/10)];
    })),2));
    return {geometry,material};
}
