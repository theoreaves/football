import * as THREE from 'three';
import {fitLogo} from './practice/logo-fit.js';
import {buildStadium} from './practice/stadium.js';

export function stadiumCopyFields(source) {
    const data=source?.stadium || {},fields={};
    for(const key of ['stadium_style','stadium_seat_color','stadium_wall_color','stadium_roof_color'])fields[key]=source?.[key] || (key==='stadium_style'?'classic_oval':key==='stadium_seat_color'?'#315b85':key==='stadium_wall_color'?'#657587':'#cbd5e1');
    for(const key of ['tiers','rows','row_height','scoreboards','scoreboard_size','lighting'])fields[`team_settings[stadium][${key}]`]=data[key] ?? '';
    for(const [key,value] of Object.entries({scoreboards:'both',scoreboard_size:'medium',lighting:'corners'}))fields[`team_settings[stadium][${key}]`] ||= value;
    fields['team_settings[stadium][tunnels]']=!(data.tunnels===false || data.tunnels===0 || data.tunnels==='0');
    fields['team_settings[stadium][one_color]']=!(data.one_color===false || data.one_color===0 || data.one_color==='0');
    for(const key of ['lower','middle','upper'])fields[`team_settings[stadium][tier_colors][${key}]`]=data.tier_colors?.[key] || fields.stadium_seat_color;
    fields['team_settings[stadium][wall_one_color]']=!(data.wall_one_color===false || data.wall_one_color===0 || data.wall_one_color==='0');
    for(const key of ['lower','middle','upper'])fields[`team_settings[stadium][tier_wall_colors][${key}]`]=data.tier_wall_colors?.[key] || fields.stadium_wall_color;
    return fields;
}

export function stadiumFromForm(form) {
    const data=new FormData(form),appearance={stadium:{tier_colors:{},tier_wall_colors:{}}};
    for(const key of ['stadium_style','stadium_seat_color','stadium_wall_color','stadium_roof_color','endzone_background','endzone_text','endzone_text_color'])appearance[key]=data.get(key);
    for(const key of ['name','tiers','rows','row_height','scoreboards','scoreboard_size','lighting'])appearance.stadium[key]=data.get(`team_settings[stadium][${key}]`);
    appearance.primary_color=data.get('team_color1');
    appearance.stadium.tunnels=data.getAll('team_settings[stadium][tunnels]').at(-1)==='1';
    appearance.stadium.one_color=data.getAll('team_settings[stadium][one_color]').at(-1)==='1';
    for(const key of ['lower','middle','upper'])appearance.stadium.tier_colors[key]=data.get(`team_settings[stadium][tier_colors][${key}]`);
    appearance.stadium.wall_one_color=data.getAll('team_settings[stadium][wall_one_color]').at(-1)==='1';
    for(const key of ['lower','middle','upper'])appearance.stadium.tier_wall_colors[key]=data.get(`team_settings[stadium][tier_wall_colors][${key}]`);
    appearance.endzone_transparent=data.getAll('endzone_transparent').at(-1)==='1';
    appearance.name=data.get('name') || 'HOME';return appearance;
}

export function mountStadiumBuilder(form) {
    const single=form.querySelector('[data-stadium-one-color]'),colors=[...form.querySelectorAll('[data-tier-color]')];
    const sync=()=>colors.forEach(input=>input.disabled=single.checked);single.addEventListener('change',sync);sync();
    const singleWalls=form.querySelector('[data-stadium-one-wall-color]'),wallColors=[...form.querySelectorAll('[data-tier-wall-color]')];
    const syncWalls=()=>wallColors.forEach(input=>input.disabled=singleWalls.checked);singleWalls.addEventListener('change',syncWalls);syncWalls();
    const choices=JSON.parse(form.querySelector('[data-stadium-copy-data]').textContent);
    form.querySelector('[data-stadium-copy]').onclick=()=>{
        const source=choices.find(team=>String(team.id)===form.querySelector('[data-stadium-copy-team]').value);
        const status=form.querySelector('[data-stadium-copy-status]');
        if(!source){status.textContent='Choose a team first.';return;}
        for(const [name,value] of Object.entries(stadiumCopyFields(source))) {
            if(name==='team_settings[stadium][tunnels]')form.querySelector('[data-stadium-tunnels]').checked=value;
            else if(name==='team_settings[stadium][one_color]')single.checked=value;
            else if(name==='team_settings[stadium][wall_one_color]')singleWalls.checked=value;
            else form.elements.namedItem(name).value=value;
        }
        sync();syncWalls();status.textContent=`Loaded ${source.label} stadium design. Preview or adjust it, then save the team.`;
    };
}

export function buildStadiumPreview(home,document,textureFor=(url,onLoad)=>new THREE.TextureLoader().load(url,onLoad)) {
    const stadium=buildStadium(home,document,{}, {fullness:0,visitors:0,seed:1},textureFor);
    const sceneGroup=new THREE.Group();sceneGroup.add(stadium.group);
    const add=(width,length,x,z,color)=>{
        const mesh=new THREE.Mesh(new THREE.PlaneGeometry(width,length),new THREE.MeshStandardMaterial({color,roughness:1}));
        mesh.rotation.x=-Math.PI/2;mesh.position.set(x,.015,z);sceneGroup.add(mesh);return mesh;
    };
    add(120,53.33,60,26.665,'#347344');
    if(!home.endzone_transparent)for(const x of [5,115])add(10,53.33,x,26.665,home.endzone_background || '#174880').position.y=.025;
    for(let x=10;x<=110;x+=5)add(.12,53.33,x,26.665,'#eeeeee').position.y=.03;
    for(const z of [0,53.33])add(120,.15,60,z,'#eeeeee').position.y=.03;
    const decal=(name,width,height,x,rotation,map)=>{
        const mesh=new THREE.Mesh(new THREE.PlaneGeometry(width,height),new THREE.MeshBasicMaterial({map,transparent:true,depthWrite:false}));
        mesh.name=name;mesh.rotation.set(-Math.PI/2,0,rotation);mesh.position.set(x,.08,26.665);sceneGroup.add(mesh);return mesh;
    };
    if(home.midfield_logo)decal('midfield-logo',14,14,60,0,textureFor(home.midfield_logo));
    const field25Position=home.field_25_position || 'both';
    if(home.field_25_logo && field25Position !== 'none') {
        for(const [side,x] of [['left',35],['right',85]]) {
            if(field25Position !== 'both' && field25Position !== side)continue;
            const logo=decal(`field-25-logo-${side}`,1,1,x,0,null);
            const fit=texture=>{
                if(!texture?.image?.width)return;
                const size=fitLogo(texture.image.width,texture.image.height,4.8,4.8);
                logo.scale.set(size.width,size.height,1);
            };
            const texture=textureFor(home.field_25_logo,fit);
            logo.material.map=texture;
            if(texture?.image?.width)fit(texture);
        }
    }

    for(const x of [5,115]){
        const side=x===5?'left':'right',url=home[`endzone_logo_${side}`],rotation=x===5?Math.PI/2:-Math.PI/2;
        if(url){
            const mesh=decal(`endzone-art-${side}`,1,1,x,rotation,null);
            const fit=texture=>{const size=fitLogo(texture.image.width,texture.image.height,44,7);mesh.scale.set(size.width,size.height,1);};
            const texture=textureFor(url,fit);mesh.material.map=texture;
            if(texture.image?.width)fit(texture);
        }else{
            const canvas=document.createElement('canvas');canvas.width=1024;canvas.height=160;
            const context=canvas.getContext('2d');context.fillStyle=home.endzone_text_color || '#ffffff';
            context.textAlign='center';context.textBaseline='middle';context.font='bold 100px sans-serif';
            context.fillText(home.endzone_text || home.name || 'FOOTBALL',512,80,960);
            decal(`endzone-text-${side}`,44,7,x,rotation,new THREE.CanvasTexture(canvas));
        }
    }
    sceneGroup.name='stadium-preview';return sceneGroup;
}
