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
test('Rams horn broad root starts over the front and curls toward the ear on both sides',()=>{
 const geometry=helmetDesignGeometry('ram_horns'),p=geometry.attributes.position;
 const samples=145,sideSize=samples*2;
 assert.equal(p.count,sideSize*2);
 const sample=(side,index)=>{
  const i=side*sideSize+index*2;
  return {
   x:(p.getX(i)+p.getX(i+1))/2,
   y:(p.getY(i)+p.getY(i+1))/2,
   z:(p.getZ(i)+p.getZ(i+1))/2,
   width:new THREE.Vector3().fromBufferAttribute(p,i)
    .distanceTo(new THREE.Vector3().fromBufferAttribute(p,i+1)),
  };
 };
 for(const side of [0,1]){
  const root=sample(side,0),crown=sample(side,24),rear=sample(side,72),tip=sample(side,144);
  assert.ok(root.z>.13,`side ${side}: thick root should start toward facemask`);
  assert.ok(rear.z<-.14,`side ${side}: outer horn must sweep behind the ear`);
  assert.ok(tip.z>rear.z+.20,`side ${side}: horn must curl forward from rear`);
  assert.ok(crown.y>rear.y+.10,`side ${side}: broad band arches across crown`);
  assert.ok(root.width>tip.width*4,`side ${side}: horn must taper sharply`);
 }
 const left=sample(0,0),right=sample(1,0);
 assert.ok(left.x<0&&right.x>0,'the same front-facing horn must appear on opposite sides');
 assert.ok(Math.abs(left.z-right.z)<.001,'both horns must point toward the same front');
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
