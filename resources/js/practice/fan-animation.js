import * as THREE from 'three';
// A fixed seed selects participants; shared uniforms animate the whole stand on the GPU.
export function fanAnimation(plan) {
    const uniforms = {fanTime:{value:0},fanExit:{value:0},fanHomeStart:{value:-100},fanAwayStart:{value:-100}};
    const traits = new Float32Array(plan.flatMap(f=>[f.allegiance==='home'?1:f.allegiance==='away'?-1:0,f.shade,(f.seat%37)*.17]));
    return {
        attach(mesh, armSign=0) {
            mesh.geometry.setAttribute('fanTraits',new THREE.InstancedBufferAttribute(traits,3));
            mesh.material.customProgramCacheKey=()=>`stadium-fan-v1-${armSign}`;
            mesh.material.onBeforeCompile=shader=>{
                Object.assign(shader.uniforms,uniforms);
                shader.vertexShader=`attribute vec3 fanTraits;
                    uniform float fanExit;uniform float fanTime;uniform float fanHomeStart;uniform float fanAwayStart;
                    `+shader.vertexShader;
                shader.vertexShader=shader.vertexShader.replace('#include <begin_vertex>',`#include <begin_vertex>
                    float leaving = step(fanTraits.y,fanExit);
                    transformed *= 1.0-leaving;
                    float fanStart = fanTraits.x > .5 ? fanHomeStart : fanTraits.x < -.5 ? fanAwayStart : max(fanHomeStart,fanAwayStart);
                    float fanT = fanTime - fanStart - fanTraits.y * .65;
                    float fanSelected = 1.0 - step(abs(fanTraits.x) < .5 ? .15 : .65,fanTraits.y);
                    float fanPulse = smoothstep(0.0,.55,fanT) * (1.0-smoothstep(3.3,4.8,fanT)) * fanSelected;
                    float fanAngle = ${armSign.toFixed(1)} * (fanPulse*(2.25+.12*sin(fanT*5.0+fanTraits.z)) + .025*sin(fanTime*.8+fanTraits.z));
                    float fanC = cos(fanAngle),fanS = sin(fanAngle);
                    transformed.xy = mat2(fanC,fanS,-fanS,fanC)*transformed.xy;
                    transformed.y += fanPulse*.025*sin(fanT*5.0+fanTraits.z);
                `);
                shader.vertexShader=shader.vertexShader.replace('#include <beginnormal_vertex>',`#include <beginnormal_vertex>
                    float normalStart = fanTraits.x > .5 ? fanHomeStart : fanTraits.x < -.5 ? fanAwayStart : max(fanHomeStart,fanAwayStart);
                    float normalT = fanTime-normalStart-fanTraits.y*.65;
                    float normalPulse = smoothstep(0.0,.55,normalT)*(1.0-smoothstep(3.3,4.8,normalT))*(1.0-step(abs(fanTraits.x)<.5?.15:.65,fanTraits.y));
                    float normalAngle = ${armSign.toFixed(1)}*(normalPulse*(2.25+.12*sin(normalT*5.0+fanTraits.z))+.025*sin(fanTime*.8+fanTraits.z));
                    objectNormal.xy = mat2(cos(normalAngle),sin(normalAngle),-sin(normalAngle),cos(normalAngle))*objectNormal.xy;
                `);
            };
            // Shader motion extends beyond the static arm bounds.
            mesh.frustumCulled=false;
        },
        tick(delta) {uniforms.fanTime.value+=Math.max(0,Math.min(delta,.1));},
        cheer(side) {if(side==='home')uniforms.fanHomeStart.value=uniforms.fanTime.value;else if(side==='away')uniforms.fanAwayStart.value=uniforms.fanTime.value;},
        reset() {uniforms.fanHomeStart.value=uniforms.fanAwayStart.value=-100;},
        depart(progress) {uniforms.fanExit.value=Math.max(0,Math.min(1,progress));},
        uniforms,
    };
}
