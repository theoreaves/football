import * as THREE from 'three';
import {OrbitControls} from 'three/addons/controls/OrbitControls.js';
import {buildFootballPlayer} from './practice/player-model.js';
import {buildCoach} from './practice/coach-model.js';
export function uniformFromForm(form,venue,art={}) {
    const data=new FormData(form),uniform={};
    for(const part of ['helmet','facemask','shirt','pants','socks','number','number_outline','name_color','name_enabled','helmet_stripe','helmet_stripe_enabled','shoulder_stripe','shoulder_stripe_enabled','pants_stripe','pants_stripe_enabled']) {
        const value=data.getAll(`uniform_${venue}_${part}`).at(-1);
        uniform[part]=part.endsWith('_enabled')?value==='1':value;
    }
    return {...uniform,...art};
}
export function mountTeamSettings(form) {
    if(!form || form.dataset.mounted)return;form.dataset.mounted='true';
    const tabs=[...form.querySelectorAll('[data-team-tab]')],panels=[...form.querySelectorAll('[data-team-panel]')];
    const choose=key=>{tabs.forEach(tab=>{const selected=tab.dataset.teamTab===key;tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;tab.classList.toggle('bg-blue-100',selected);});panels.forEach(panel=>panel.hidden=panel.dataset.teamPanel!==key);try{sessionStorage.setItem('football-team-tab',key);}catch{}};
    tabs.forEach((tab,index)=>{tab.onclick=()=>choose(tab.dataset.teamTab);tab.onkeydown=event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;choose(tabs[next].dataset.teamTab);tabs[next].focus();};});
    let initial='team';try{const saved=sessionStorage.getItem('football-team-tab');if(tabs.some(tab=>tab.dataset.teamTab===saved))initial=saved;}catch{}
    const error=JSON.parse(form.dataset.errorFields || '[]')[0];
    if(error)initial=error.includes('staff')||error.includes('coaching')?'coaching':error.includes('cheerleaders')?'cheerleaders':error.includes('mascot')?'mascot':error.includes('uniform')||error.includes('helmet_logo')||error.includes('white_at_home')?'uniforms':error.includes('stadium')||error.includes('endzone')||error.includes('midfield')?'stadium':'team';
    choose(initial);form.addEventListener('invalid',event=>{const panel=event.target.closest('[data-team-panel]');if(panel)choose(panel.dataset.teamPanel);},true);
    form.querySelectorAll('input[type=range]').forEach(input=>input.addEventListener('input',()=>input.parentElement.querySelector('output').value=input.value));
    const dialog=form.querySelector('[data-team-preview]'),host=form.querySelector('[data-team-preview-host]');
    let view=null,frame=0,kind='home',role=null;
    const resources=new Set(),urls=new Set(),textures=new Map();
    const dispose=()=>{if(view?.model)retain(view.model);cancelAnimationFrame(frame);view?.controls.dispose();view?.renderer.dispose();resources.forEach(resource=>resource.dispose());resources.clear();textures.clear();urls.forEach(url=>URL.revokeObjectURL(url));urls.clear();host.replaceChildren();view=null;};
    const retain=model=>model.traverse(object=>{if(object.geometry)resources.add(object.geometry);for(const material of object.material?(Array.isArray(object.material)?object.material:[object.material]):[]){resources.add(material);if(material.map)resources.add(material.map);}});
    const art=()=>{
        const result={};
        for(const field of ['helmet_logo_left','helmet_logo_right']) {
            const input=form.elements.namedItem(field),clear=form.elements.namedItem(`clear_${field}`);
            let url=null;
            if(input.files[0]){url=URL.createObjectURL(input.files[0]);urls.add(url);}
            else if(!clear?.checked)url=input.closest('.min-w-0')?.querySelector('img')?.src;
            result[field]=url;
        }
        return result;
    };
    const rebuild=()=>{
        if(!view)return;
        if(view.model){retain(view.model);view.scene.remove(view.model);}
        // Drop all previous model resources, including asynchronous logo textures.
        resources.forEach(resource=>resource.dispose());resources.clear();textures.clear();urls.forEach(url=>URL.revokeObjectURL(url));urls.clear();
        if(role){const data=new FormData(form),profile={};for(const key of ['name','skin','hair','hair_color','build','shirt','pants'])profile[key]=data.get(`team_settings[staff][${role}][${key}]`);view.model=buildCoach(profile,role);}
        else {
            const kit=uniformFromForm(form,kind,art());
            const textureFor=url=>{if(!url)return null;let texture=textures.get(url);if(!texture){texture=new THREE.TextureLoader().load(url);texture.colorSpace=THREE.SRGBColorSpace;textures.set(url,texture);resources.add(texture);}return texture;};
            view.model=buildFootballPlayer({number:'00',lastname:'PLAYER',height_inches:72,weight_pounds:215,skin_tone:'#b9825b'},kit,document,textureFor);
        }
        retain(view.model);view.scene.add(view.model);
    };
    const open=(venue,coachRole=null)=>{
        dispose();kind=venue;role=coachRole;dialog.showModal();form.querySelector('[data-preview-title]').textContent=role?'Coach preview':`${kind==='home'?'Home':'Away'} uniform preview`;
        try {
            const renderer=new THREE.WebGLRenderer({antialias:true});renderer.setPixelRatio(Math.min(globalThis.devicePixelRatio || 1,2));renderer.setClearColor('#182537');host.append(renderer.domElement);renderer.domElement.setAttribute('aria-label','Rotatable 3D model preview');
            const scene=new THREE.Scene();scene.add(new THREE.HemisphereLight('#ffffff','#56667b',2));const light=new THREE.DirectionalLight('#ffffff',3);light.position.set(3,5,4);scene.add(light);
            const camera=new THREE.PerspectiveCamera(40,1,.01,100);camera.position.set(0,1.4,4.3);const controls=new OrbitControls(camera,renderer.domElement);controls.target.set(0,1.1,0);controls.enablePan=false;controls.minDistance=2;controls.maxDistance=7;
            view={renderer,scene,camera,controls,model:null};rebuild();
            const render=()=>{if(!view || !dialog.open)return;const width=Math.max(1,host.clientWidth),height=Math.max(1,host.clientHeight);renderer.setSize(width,height,false);camera.aspect=width/height;camera.updateProjectionMatrix();if(!document.hidden){controls.update();renderer.render(scene,camera);}frame=requestAnimationFrame(render);};render();
        }catch{dispose();host.textContent='3D preview unavailable. You can still edit and save these settings.';}
    };
    form.querySelectorAll('[data-uniform-preview]').forEach(button=>button.onclick=()=>open(button.dataset.uniformPreview));
    form.querySelectorAll('[data-coach-preview]').forEach(button=>button.onclick=()=>open('home',button.dataset.coachPreview));
    form.querySelector('[data-preview-close]').onclick=()=>dialog.close();dialog.addEventListener('close',dispose);
    window.addEventListener('pagehide',dispose,{once:true});document.addEventListener('livewire:navigating',dispose,{once:true});
}
