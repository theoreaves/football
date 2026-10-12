import * as THREE from 'three';
import {helmetPoint} from './helmet-shell.js';

export function helmetDesignGeometry(design) {
    if (!['cat_stripes','ram_horns'].includes(design)) return null;
    const positions=[],normals=[],indices=[];
    const ribbon=(side,pointAt,widthAt,steps=64)=>{
        const start=positions.length/3;
        for(let i=0;i<=steps;i++){
            const t=i/steps,p=pointAt(t),a=pointAt(Math.max(0,t-.001)),b=pointAt(Math.min(1,t+.001));
            const tangent=b.clone().sub(a).normalize(),perpendicular=new THREE.Vector2(-tangent.y,tangent.x);
            for(const edge of [-1,1]){
                const q=p.clone().addScaledVector(perpendicular,edge*widthAt(t));
                const phi=(side<0?Math.PI:0)+side*q.x;
                const bottom=2.25-.9*Math.max(0,Math.sin(phi))**4;
                const theta=Math.max(.04,Math.min(bottom-.025,q.y));
                const v=helmetPoint(theta,phi,.0012);
                positions.push(...v.toArray());normals.push(...new THREE.Vector3(v.x/.355**2,v.y/.38**2,(v.z+.025)/.405**2).normalize().toArray());
            }
            if(i<steps){const x=start+i*2;indices.push(x,x+1,x+2,x+1,x+3,x+2);}
        }
    };
    for(const side of [-1,1]){
        if(design==='cat_stripes'){
            for(let i=0;i<6;i++){
                const offset=-1.10+i*.42;
                ribbon(side,t=>new THREE.Vector2(offset+.12*Math.sin(t*5+i*.8),.20+t*(1.72-(i%2)*.23)),
                    t=>(.075+(i%3)*.014)*(1-t)**.65,36);
            }
        }else{
            ribbon(side,t=>{const angle=-Math.PI/4+t*Math.PI*1.75,radius=1-.72*t;
                return new THREE.Vector2(-.06+.92*radius*Math.cos(angle),1.2+.75*radius*Math.sin(angle));},
                t=>.145*(1-t)**.65+.003,96);
        }
    }
    // Use outward winding on both mirrored sides.
    for(let i=0;i<indices.length;i+=3){const a=new THREE.Vector3().fromArray(positions,indices[i]*3),b=new THREE.Vector3().fromArray(positions,indices[i+1]*3),c=new THREE.Vector3().fromArray(positions,indices[i+2]*3);
        const center=a.clone().add(b).add(c).divideScalar(3);center.z+=.025;
        if(b.sub(a).cross(c.sub(a)).dot(center)<0)[indices[i+1],indices[i+2]]=[indices[i+2],indices[i+1]];
    }
    const geometry=new THREE.BufferGeometry();geometry.setAttribute('position',new THREE.Float32BufferAttribute(positions,3));geometry.setAttribute('normal',new THREE.Float32BufferAttribute(normals,3));geometry.setIndex(indices);return geometry;
}

export function helmetLogoMaterial(texture,tint=null) {
    const material=new THREE.MeshBasicMaterial({map:texture,transparent:true,depthWrite:false,side:THREE.DoubleSide});
    if(/^#[0-9a-f]{6}$/i.test(tint || '')){
        material.onBeforeCompile=shader=>{shader.uniforms.helmetLogoTint={value:new THREE.Color(tint)};
            shader.fragmentShader='uniform vec3 helmetLogoTint;\n'+shader.fragmentShader.replace('#include <map_fragment>','#include <map_fragment>\n diffuseColor.rgb = helmetLogoTint;');};
        material.customProgramCacheKey=()=>`helmet-tint-${tint}`;
    }
    return material;
}
