import * as THREE from 'three';
import {OrbitControls} from 'three/addons/controls/OrbitControls.js';
import {buildFootballPlayer} from './practice/player-model.js';
import {mountStadiumBuilder,stadiumFromForm,buildStadiumPreview} from './stadium-builder.js';
import {buildCheerleader,animateCheerleader} from './practice/cheerleader-model.js';
import {buildMascot} from './practice/mascot-model.js';
import {buildCoach} from './practice/coach-model.js';
export function uniformFromForm(form,venue,art={}) {
    const data=new FormData(form),uniform={};
    for(const part of ['helmet','facemask','shirt','pants','socks','number','number_outline','name_color','name_enabled','helmet_stripe','helmet_stripe_enabled','shoulder_stripe','shoulder_stripe_enabled','pants_stripe','pants_stripe_enabled']) {
        const value=data.getAll(`uniform_${venue}_${part}`).at(-1);
        uniform[part]=part.endsWith('_enabled')?value==='1':value;
    }
    uniform.helmet_design=data.getAll(`team_settings[helmets][${venue}][design]`).at(-1) || 'standard';
    for(const side of ['left','right'])uniform[`helmet_logo_${side}_flip`]=data.getAll(`team_settings[helmets][flip_${side}]`).at(-1)==='1';
    uniform.helmet_logo_color=data.getAll('team_settings[helmets][logo_color]').at(-1) || null;
    return {...uniform,...art};
}
export function mountTeamSettings(form) {
    if(!form || form.dataset.mounted)return;form.dataset.mounted='true';
    // Preview selected local audio without uploading or starting it automatically.
    form.addEventListener('change', event => {
        const input = event.target;
        if (!input.matches?.('[data-audio-preview]')) return;
        const audio = input.parentElement.querySelector('audio');
        if (!audio) return;
        audio.pause();
        if (input.dataset.previewUrl) { URL.revokeObjectURL(input.dataset.previewUrl); urls.delete(input.dataset.previewUrl); }
        if (!input.dataset.originalAudio) input.dataset.originalAudio = audio.getAttribute('src');
        const file = input.files?.[0];
        input.dataset.previewUrl = file ? URL.createObjectURL(file) : '';
        if (input.dataset.previewUrl) urls.add(input.dataset.previewUrl);
        audio.src = input.dataset.previewUrl || input.dataset.originalAudio;
        audio.load();
    });

    const tabs=[...form.querySelectorAll('[data-team-tab]')],panels=[...form.querySelectorAll('[data-team-panel]')];
    const choose=key=>{tabs.forEach(tab=>{const selected=tab.dataset.teamTab===key;tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;tab.classList.toggle('bg-blue-100',selected);});panels.forEach(panel=>panel.hidden=panel.dataset.teamPanel!==key);try{sessionStorage.setItem('football-team-tab',key);}catch{}};
    tabs.forEach((tab,index)=>{tab.onclick=()=>choose(tab.dataset.teamTab);tab.onkeydown=event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;choose(tabs[next].dataset.teamTab);tabs[next].focus();};});
    let initial='team';try{const saved=sessionStorage.getItem('football-team-tab');if(tabs.some(tab=>tab.dataset.teamTab===saved))initial=saved;}catch{}
    const error=JSON.parse(form.dataset.errorFields || '[]')[0];
    if(error)initial=error.startsWith('audio_')?'audio':error.includes('staff')||error.includes('coaching')?'coaching':error.includes('cheerleaders')?'cheerleaders':error.includes('mascot')?'mascot':error.includes('helmets')||error.includes('uniform')||error.includes('helmet_logo')||error.includes('white_at_home')?'uniforms':error.includes('stadium')||error.includes('endzone')||error.includes('midfield')?'stadium':'team';
    mountStadiumBuilder(form);
    choose(initial);form.addEventListener('invalid',event=>{const panel=event.target.closest('[data-team-panel]');if(panel)choose(panel.dataset.teamPanel);},true);
    form.querySelectorAll('input[type=range]').forEach(input=>input.addEventListener('input',()=>input.parentElement.querySelector('output').value=input.value));
    const dialog=form.querySelector('[data-team-preview]'),host=form.querySelector('[data-team-preview-host]');
    let view=null,frame=0,kind='home',role=null;
    const resources=new Set(),urls=new Set(),textures=new Map();
    const dispose=()=>{if(view?.model)retain(view.model);cancelAnimationFrame(frame);view?.controls.dispose();view?.renderer.dispose();resources.forEach(resource=>resource.dispose());resources.clear();textures.clear();urls.forEach(url=>URL.revokeObjectURL(url));urls.clear();host.replaceChildren();view=null;};
    const retain=model=>model.traverse(object=>{if(object.geometry)resources.add(object.geometry);for(const material of object.material?(Array.isArray(object.material)?object.material:[object.material]):[]){resources.add(material);if(material.map)resources.add(material.map);}});
    const art=()=>{
        const result={};
        for(const field of ['helmet_logo_left','helmet_logo_right','team_logo','midfield_logo','endzone_logo_left','endzone_logo_right']) {
            const input=form.elements.namedItem(field),clear=form.elements.namedItem(`clear_${field}`);
            let url=null;
            if(input?.files?.[0]){url=URL.createObjectURL(input.files[0]);urls.add(url);}
            else if(!clear?.checked)url=input?.closest('div.min-w-0')?.querySelector('img')?.src;
            result[field]=url;
        }
        return result;
    };
    const rebuild=()=>{
        if(!view)return;
        if(view.model){retain(view.model);view.scene.remove(view.model);}
        // Drop all previous model resources, including asynchronous logo textures.
        resources.forEach(resource=>resource.dispose());resources.clear();textures.clear();urls.forEach(url=>URL.revokeObjectURL(url));urls.clear();
        const textureFor=(url,onLoad)=>{if(!url)return null;let texture=textures.get(url);if(!texture){texture=new THREE.TextureLoader().load(url,onLoad);texture.colorSpace=THREE.SRGBColorSpace;textures.set(url,texture);resources.add(texture);}return texture;};
        const images=art();
        if(kind==='stadium'){const appearance=stadiumFromForm(form);Object.assign(appearance,images);view.model=buildStadiumPreview(appearance,document,textureFor);}
        else if(kind==='cheerleaders'){const data=new FormData(form),profile={};for(const key of ['primary','secondary','accent'])profile[key]=data.get(`team_settings[cheerleaders][${key}]`);view.model=buildCheerleader(profile,images.team_logo,textureFor);}
        else if(kind==='mascot'){const data=new FormData(form),profile={};for(const key of ['name','character','fur','shirt'])profile[key]=data.get(`team_settings[mascot][${key}]`);view.model=buildMascot(profile,images.team_logo,textureFor);}
        else if(role){const data=new FormData(form),profile={};for(const key of ['name','skin','hair','hair_color','build','shirt','pants','hat_color'])profile[key]=data.get(`team_settings[staff][${role}][${key}]`);profile.hat=data.getAll(`team_settings[staff][${role}][hat]`).at(-1)==='1';view.model=buildCoach(profile,role,images.team_logo,textureFor);}
        else {
            const kit=uniformFromForm(form,kind,images);
            view.model=buildFootballPlayer({number:'00',lastname:'PLAYER',height_inches:72,weight_pounds:215,skin_tone:'#b9825b'},kit,document,textureFor);
        }
        retain(view.model);view.scene.add(view.model);
    };
    const open=(venue,coachRole=null)=>{
        dispose();kind=venue;role=coachRole;dialog.showModal();form.querySelector('[data-preview-title]').textContent=kind==='stadium'?'Stadium preview':kind==='cheerleaders'?'Cheerleader preview':kind==='mascot'?`${form.elements.namedItem('team_settings[mascot][name]').value || 'Mascot'} preview`:role?'Coach preview':`${kind==='home'?'Home':'Away'} uniform preview`;
        try {
            const renderer=new THREE.WebGLRenderer({antialias:true});renderer.setPixelRatio(Math.min(globalThis.devicePixelRatio || 1,2));renderer.setClearColor('#182537');host.append(renderer.domElement);renderer.domElement.setAttribute('aria-label','Rotatable 3D model preview');
            const scene=new THREE.Scene();scene.add(new THREE.HemisphereLight('#ffffff','#56667b',2));const light=new THREE.DirectionalLight('#ffffff',3);light.position.set(3,5,4);scene.add(light);
            const camera=new THREE.PerspectiveCamera(40,1,.01,kind==='stadium'?2000:100);camera.position.set(0,kind==='mascot'?1.6:1.4,kind==='mascot'?5.7:4.3);const controls=new OrbitControls(camera,renderer.domElement);controls.target.set(0,kind==='mascot'?1.4:1.1,0);controls.enablePan=false;controls.minDistance=2;controls.maxDistance=7;
            view={renderer,scene,camera,controls,model:null};rebuild();
            if(kind==='stadium') {
                const bounds=new THREE.Box3().setFromObject(view.model),center=bounds.getCenter(new THREE.Vector3()),size=bounds.getSize(new THREE.Vector3());
                const aspect=Math.max(.4,host.clientWidth/Math.max(1,host.clientHeight));
                const distance=Math.max(size.y,size.x/aspect,size.z)/Math.tan(THREE.MathUtils.degToRad(20))* .85;
                controls.target.copy(center);camera.position.copy(center).add(new THREE.Vector3(1,.9,1.2).normalize().multiplyScalar(distance));
                controls.minDistance=80;controls.maxDistance=1200;controls.maxPolarAngle=Math.PI/2.05;
            }
            const render=()=>{if(!view || !dialog.open)return;const width=Math.max(1,host.clientWidth),height=Math.max(1,host.clientHeight);renderer.setSize(width,height);camera.aspect=width/height;camera.updateProjectionMatrix();if(!document.hidden){if(kind==='cheerleaders')animateCheerleader(view.model,performance.now()/1000);controls.update();renderer.render(scene,camera);}frame=requestAnimationFrame(render);};render();
        }catch{dispose();host.textContent='3D preview unavailable. You can still edit and save these settings.';}
    };
    form.querySelectorAll('[data-uniform-preview]').forEach(button=>button.onclick=()=>open(button.dataset.uniformPreview));
    form.querySelectorAll('[data-coach-preview]').forEach(button=>button.onclick=()=>open('home',button.dataset.coachPreview));
    form.querySelector('[data-stadium-preview]').onclick=()=>open('stadium');
    form.querySelector('[data-cheerleader-preview]').onclick=()=>open('cheerleaders');
    form.querySelector('[data-mascot-preview]').onclick=()=>open('mascot');
    form.querySelector('[data-preview-close]').onclick=()=>dialog.close();dialog.addEventListener('close',dispose);
    window.addEventListener('pagehide',dispose,{once:true});document.addEventListener('livewire:navigating',dispose,{once:true});
}
