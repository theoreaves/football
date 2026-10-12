import test from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import {helmetDesignGeometry,helmetLogoMaterial} from '../../resources/js/practice/helmet-designs.js';
import {helmetLogoGeometry} from '../../resources/js/practice/helmet-shell.js';
import {buildFootballPlayer} from '../../resources/js/practice/player-model.js';
import {buildSidelinePlayers} from '../../resources/js/practice/sideline-players.js';
const document={createElement:()=>({getContext:()=>({strokeText(){},fillText(){},measureText:()=>({width:20}),clearRect(){}})})};
test('cat stripes and horns conform to both helmet sides and have outward winding',()=>{
 for(const design of ['cat_stripes','ram_horns']){
  const g=helmetDesignGeometry(design),p=g.attributes.position;let left=false,right=false;
  for(let i=0;i<p.count;i++){
   const x=p.getX(i),y=p.getY(i),z=p.getZ(i)+.025;
   const radius=Math.sqrt((x/.355)**2+(y/.38)**2+(z/.405)**2);assert.ok(radius>1&&radius<1.01);
   const theta=Math.acos(y/(.38*radius)),phi=Math.atan2(z/.405,x/.355);
   assert.ok(theta<2.25-.9*Math.max(0,Math.sin(phi))**4+.005);
   left ||= x<0;right ||= x>0;
  }
  assert.ok(left&&right);
  for(let i=0;i<g.index.count;i+=3){const a=new THREE.Vector3().fromBufferAttribute(p,g.index.getX(i)),b=new THREE.Vector3().fromBufferAttribute(p,g.index.getX(i+1)),c=new THREE.Vector3().fromBufferAttribute(p,g.index.getX(i+2)),mid=a.clone().add(b).add(c).divideScalar(3);mid.z+=.025;assert.ok(b.sub(a).cross(c.sub(a)).dot(mid)>-1e-8);}
 }
});
test('player and instanced sideline helmets share patterns instead of a center stripe',()=>{
 for(const design of ['cat_stripes','ram_horns']){
  const uniform={helmet_design:design,helmet_stripe_enabled:true,helmet_stripe:'#ffaa00'};
  const player=buildFootballPlayer({},uniform,document);assert.ok(player.getObjectByName('helmet-design-'+design));assert.equal(player.getObjectByName('helmet-stripe'),undefined);
  const scene=new THREE.Scene();buildSidelinePlayers(scene,{home:[{id:1,number:1}],away:[]},{home:{uniform}},document);
  assert.ok(scene.getObjectByName('home-sideline-helmet-design-'+design));assert.equal(scene.getObjectByName('home-sideline-helmet-stripe'),undefined);
 }
});
test('mirroring a decal changes only its UV coordinates and tint preserves map alpha',()=>{
 const texture=new THREE.Texture(),g=helmetLogoGeometry(1),flip=helmetLogoGeometry(1,.36,.29,true);
 assert.deepEqual(Array.from(g.attributes.position.array),Array.from(flip.attributes.position.array));
 for(let i=0;i<g.attributes.uv.count;i++)assert.ok(Math.abs(g.attributes.uv.getX(i)+flip.attributes.uv.getX(i)-1)<1e-7);
 const material=helmetLogoMaterial(texture,'#ffffff'),shader={uniforms:{},fragmentShader:'#include <map_fragment>'};material.onBeforeCompile(shader);
 assert.equal(material.map,texture);assert.equal(material.transparent,true);assert.ok(shader.fragmentShader.includes('diffuseColor.rgb = helmetLogoTint'));assert.equal(shader.uniforms.helmetLogoTint.value.getHexString(),'ffffff');
});
