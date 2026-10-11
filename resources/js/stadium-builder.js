import * as THREE from 'three';
import {buildStadium} from './practice/stadium.js';

export function stadiumCopyFields(source) {
    const data=source?.stadium || {},fields={};
    for(const key of ['stadium_style','stadium_seat_color','stadium_wall_color','stadium_roof_color'])fields[key]=source?.[key] || (key==='stadium_style'?'classic_oval':key==='stadium_seat_color'?'#315b85':key==='stadium_wall_color'?'#657587':'#cbd5e1');
    for(const key of ['tiers','rows','row_height'])fields[`team_settings[stadium][${key}]`]=data[key] ?? '';
    fields['team_settings[stadium][one_color]']=!(data.one_color===false || data.one_color===0 || data.one_color==='0');
    for(const key of ['lower','middle','upper'])fields[`team_settings[stadium][tier_colors][${key}]`]=data.tier_colors?.[key] || fields.stadium_seat_color;
    fields['team_settings[stadium][wall_one_color]']=!(data.wall_one_color===false || data.wall_one_color===0 || data.wall_one_color==='0');
    for(const key of ['lower','middle','upper'])fields[`team_settings[stadium][tier_wall_colors][${key}]`]=data.tier_wall_colors?.[key] || fields.stadium_wall_color;
    return fields;
}

export function stadiumFromForm(form) {
    const data=new FormData(form),appearance={stadium:{tier_colors:{},tier_wall_colors:{}}};
    for(const key of ['stadium_style','stadium_seat_color','stadium_wall_color','stadium_roof_color','endzone_background'])appearance[key]=data.get(key);
    for(const key of ['name','tiers','rows','row_height'])appearance.stadium[key]=data.get(`team_settings[stadium][${key}]`);
    appearance.stadium.one_color=data.getAll('team_settings[stadium][one_color]').at(-1)==='1';
    for(const key of ['lower','middle','upper'])appearance.stadium.tier_colors[key]=data.get(`team_settings[stadium][tier_colors][${key}]`);
    appearance.stadium.wall_one_color=data.getAll('team_settings[stadium][wall_one_color]').at(-1)==='1';
    for(const key of ['lower','middle','upper'])appearance.stadium.tier_wall_colors[key]=data.get(`team_settings[stadium][tier_wall_colors][${key}]`);
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
            if(name==='team_settings[stadium][one_color]')single.checked=value;
            else if(name==='team_settings[stadium][wall_one_color]')singleWalls.checked=value;
            else form.elements.namedItem(name).value=value;
        }
        sync();syncWalls();status.textContent=`Loaded ${source.label} stadium design. Preview or adjust it, then save the team.`;
    };
}

export function buildStadiumPreview(home,document) {
    const stadium=buildStadium(home,document,{}, {fullness:0,visitors:0,seed:1});
    const sceneGroup=new THREE.Group();sceneGroup.add(stadium.group);
    const add=(width,length,x,z,color)=>{
        const mesh=new THREE.Mesh(new THREE.PlaneGeometry(width,length),new THREE.MeshStandardMaterial({color,roughness:1}));
        mesh.rotation.x=-Math.PI/2;mesh.position.set(x,.015,z);sceneGroup.add(mesh);return mesh;
    };
    add(120,53.33,60,26.665,'#347344');
    for(const x of [5,115])add(10,53.33,x,26.665,home.endzone_background || '#174880').position.y=.025;
    for(let x=10;x<=110;x+=5)add(.12,53.33,x,26.665,'#eeeeee').position.y=.03;
    for(const z of [0,53.33])add(120,.15,60,z,'#eeeeee').position.y=.03;
    sceneGroup.name='stadium-preview';return sceneGroup;
}
