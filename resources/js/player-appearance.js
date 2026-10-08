import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { buildFootballPlayer } from './practice/player-model.js';

export function featureGraphic(kind, value) {
 const eyes = '<circle cx="31" cy="31" r="2"/><circle cx="49" cy="31" r="2"/>';
 let detail = '';
 if(kind === 'hair') {
  detail = ({bald:'',buzz:'<path d="M16 25Q16 7 40 7Q64 7 64 25L59 19Q40 10 21 19Z" fill="#25201e"/>',short:'<path d="M15 28L17 13L29 4L43 7L54 3L65 17L64 28L55 17L25 19Z" fill="#25201e"/>',curly:'<path d="M15 25Q7 15 19 13Q16 1 30 8Q40 -2 49 8Q64 1 62 14Q75 17 64 28L55 18L24 19Z" fill="#25201e"/>',long:'<path d="M13 64V25Q12 4 40 5Q68 4 67 25V64L59 60V21L23 22V60Z" fill="#25201e"/>'}[value]);
 } else if(kind === 'brow') {
  const shapes = {straight:'M25 25H37M43 25H55',angled:'M25 23L37 26M43 26L55 23',thick:'M25 25H37M43 25H55',arched:'M25 26Q31 19 37 26M43 26Q49 19 55 26'};
  detail = `<path d="${shapes[value]}" stroke="#25201e" stroke-width="${value==='thick'?5:2.5}" fill="none"/>`;
 } else if(kind === 'nose') {
  const sizes = {standard:[4,7],small:[3,4],wide:[7,6],long:[4,10]}; const [x,y]=sizes[value];
  detail = `<ellipse cx="40" cy="40" rx="${x}" ry="${y}" fill="#b0754e"/>`;
 } else if(kind === 'mouth') {
  detail = value==='smile'?'<path d="M31 49Q40 59 49 49" fill="none" stroke="#7b4440" stroke-width="3"/>':`<rect x="${value==='wide'?28:32}" y="50" width="${value==='wide'?24:16}" height="${value==='thin'?1:3}" fill="#7b4440"/>`;
 } else if(kind === 'beard') {
  detail = ({none:'',stubble:'<ellipse cx="40" cy="56" rx="18" ry="7" fill="#555"/>',moustache:'<rect x="30" y="46" width="20" height="4" rx="2" fill="#25201e"/>',goatee:'<rect x="34" y="54" width="12" height="12" rx="3" fill="#25201e"/>',full:'<path d="M21 45Q19 73 40 75Q61 73 59 45L50 51H30Z" fill="#25201e"/>'}[value]);
 }
 return `<svg viewBox="0 0 80 80" aria-hidden="true"><ellipse cx="40" cy="40" rx="25" ry="31" fill="#c78e61"/>${eyes}${detail}</svg>`;
}

export function mountPlayerAppearance(root) {
 if(!root || root.dataset.mounted) return;
 root.dataset.mounted='true';
 const form=root.closest('form'), panel=root.querySelector('[data-appearance-panel]');
 const fields=[...root.querySelectorAll('input')];
 let snapshot=[];
 const views=[];
 const current=()=>{
  const value=name=>form.elements.namedItem(name)?.value;
  const appearance={};
  for(const feature of ['eye_color','hair_color','hair','brow','nose','mouth','beard']) appearance[feature]=value(`appearance[${feature}]`);
  return {...JSON.parse(root.dataset.profile),number:value('jersey_number'),lastname:value('lastname'),height_inches:value('height_inches'),weight_pounds:value('weight_pounds'),skin_tone:value('skin_tone'),appearance};
 };
 const disposeModel=model=>model?.traverse(object=>{
  object.geometry?.dispose();
  const materials=object.material ? (Array.isArray(object.material)?object.material:[object.material]) : [];
  materials.forEach(material=>{material.map?.dispose();material.dispose();});
 });
 const update=()=>{
  const player=current();
  views.forEach(view=>{
   if(view.model){view.scene.remove(view.model);disposeModel(view.model);}
   view.model=buildFootballPlayer(player,JSON.parse(root.dataset.kit || '{}'),document);
   view.model.getObjectByName('helmet-shell').visible=false;view.model.userData.faceMask.visible=false;
   view.scene.add(view.model);
   if(view.portrait){const head=1.97*view.model.scale.y;view.camera.position.set(0,head+.02,1.35);view.camera.lookAt(0,head,.1);}
   else {view.controls.target.set(0,1.1*view.model.scale.y,0);}
  });
 };
 root.querySelectorAll('[data-face-choice]').forEach(el=>el.innerHTML=featureGraphic(el.dataset.feature,el.dataset.choice));
 root.querySelector('[data-appearance-open]').onclick=()=>{
  snapshot=fields.map(input=>({input,value:input.value,checked:input.checked})); panel.hidden=false;
  root.querySelector('[data-appearance-open]').hidden=true;
 };
 const close=()=>{panel.hidden=true;root.querySelector('[data-appearance-open]').hidden=false;root.querySelector('[data-appearance-open]').focus();};
 root.querySelector('[data-appearance-apply]').onclick=close;
 root.querySelector('[data-appearance-cancel]').onclick=()=>{snapshot.forEach(({input,value,checked})=>{input.value=value;input.checked=checked;});update();close();};
 form.addEventListener('input',update);
 for(const [selector,portrait] of [['[data-player-portrait]',true],['[data-player-body]',false]]) {
  const host=root.querySelector(selector);
  try {
   const renderer=new THREE.WebGLRenderer({antialias:true});renderer.setPixelRatio(Math.min(devicePixelRatio,2));renderer.setClearColor('#182537');
   renderer.domElement.setAttribute('aria-label',portrait?'Helmet-free player portrait':'Helmet-free full-body player preview');host.append(renderer.domElement);
   const scene=new THREE.Scene();scene.add(new THREE.HemisphereLight('#ffffff','#56667b',2));
   const light=new THREE.DirectionalLight('#ffffff',3);light.position.set(3,5,4);scene.add(light);
   const camera=new THREE.PerspectiveCamera(portrait?25:40,1,.01,100);
   camera.position.set(0,1.5,4.6);
   const controls=portrait?null:new OrbitControls(camera,renderer.domElement);
   if(controls){controls.enablePan=false;controls.minDistance=2.5;controls.maxDistance=7;controls.maxPolarAngle=Math.PI*.65;controls.target.set(0,1.1,0);}
   const view={renderer,scene,camera,controls,portrait,model:null};
   const observer=new ResizeObserver(()=>{const width=Math.max(1,host.clientWidth),height=Math.max(1,host.clientHeight);renderer.setSize(width,height);camera.aspect=width/height;camera.updateProjectionMatrix();});observer.observe(host);view.observer=observer;views.push(view);
  } catch {host.textContent='3D preview unavailable. Appearance controls still work.';}
 }
 update();
 let frame;
 const render=()=>{
  if(root.isConnected && !document.hidden) views.forEach(view=>{if(!view.portrait && panel.hidden)return;view.controls?.update();view.renderer.render(view.scene,view.camera);});
  frame=requestAnimationFrame(render);
 };
 frame=requestAnimationFrame(render);
 const cleanup=()=>{cancelAnimationFrame(frame);form.removeEventListener('input',update);views.forEach(view=>{view.observer.disconnect();view.controls?.dispose();disposeModel(view.model);view.renderer.dispose();});};
 window.addEventListener('pagehide',cleanup,{once:true});
 document.addEventListener('livewire:navigating',cleanup,{once:true});
}
