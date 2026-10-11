import * as THREE from 'three';
import {fitLogo} from './logo-fit.js';

export const MASCOT_CHARACTERS = ['bear','cat','bird','bull','dog','wolf','cowboy','shark','dolphin','king','knight','buffalo','horse','tiger','jaguar','panther','lion','ram','raccoon','pig','eagle','falcon','raven','cardinal','hawk','viking','pirate','patriot','prospector','steelworker','elf'];
const humans = new Set(['cowboy','king','knight','viking','pirate','patriot','prospector','steelworker','elf']);
const birds = new Set(['bird','eagle','falcon','raven','cardinal','hawk']);
const cats = new Set(['cat','tiger','jaguar','panther','lion']);

/** A costume-style mascot, facing +Z, with its feet on the ground. */
export function buildMascot(profile = {}, logoUrl = null, textureFor = () => null) {
    const character = MASCOT_CHARACTERS.includes(profile.character) ? profile.character : 'bear';
    const group = new THREE.Group(); group.name = `mascot-${character}`;
    group.userData.character = character; group.userData.mascotName = profile.name || '';
    const paint = color => new THREE.MeshStandardMaterial({color,roughness:.8});
    const body = paint(profile.fur || '#b9825b'), shirt = paint(profile.shirt || '#174880');
    const white = paint('#fff6e5'), dark = paint('#17202b'), gold = paint('#e8b441'), metal = paint('#9daab8');
    const ball = (name, size, position, material = body, parent = group) => {
        const mesh = new THREE.Mesh(new THREE.SphereGeometry(1,20,14),material);
        mesh.name = name; mesh.scale.set(...size); mesh.position.set(...position); parent.add(mesh); return mesh;
    };
    const box = (name,size,position,material = body) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(...size),material);
        mesh.name=name;mesh.position.set(...position);group.add(mesh);return mesh;
    };
    const cone = (name,radius,height,position,material = body,rotation = 0) => {
        const mesh = new THREE.Mesh(new THREE.ConeGeometry(radius,height,16),material);
        mesh.name=name;mesh.position.set(...position);mesh.rotation.z=rotation;group.add(mesh);return mesh;
    };
    const human = humans.has(character), bird = birds.has(character), aquatic = ['shark','dolphin'].includes(character);
    ball('jersey',[.43,.57,.29],[0,1.17,0],shirt);
    ball('hips',[.36,.22,.26],[0,.66,0],shirt);
    for(const sign of [-1,1]) {
        const leg=new THREE.Group();leg.name=`mascot-leg-${sign}`;leg.position.set(sign*.2,.66,0);group.add(leg);
        ball('leg',[.16,.32,.17],[0,-.3,0],body,leg);
        ball('foot',[.22,.12,.3],[0,-.54,.1],human?dark:body,leg);
        const arm=new THREE.Group();arm.name=`mascot-arm-${sign}`;arm.position.set(sign*.43,1.53,0);group.add(arm);
        ball('sleeve',[.19,.2,.21],[0,-.12,0],shirt,arm);
        ball(bird?'wing':'arm',[bird?.24:.14,.34,bird?.09:.15],[sign*.055,-.43,0],body,arm);
        ball('hand',[.18,.17,.18],[sign*.06,-.7,.02],human?white:body,arm);
        arm.rotation.z=sign*.15;
    }
    ball('head',[human?.32:.43,human?.37:.43,.34],[0,2.03,.045]);
    const longFace=['dog','wolf','bull','buffalo','horse','shark','dolphin'].includes(character);
    if(!human && !bird) {
        const length = character==='dolphin'?.5:character==='horse'?.36:longFace?.3:.15;
        ball('muzzle',[character==='pig'?.23:.27,.17,length],[0,1.92,.32],['wolf','dog','bear','lion','cat','raccoon'].includes(character)?white:body);
        ball('nose',[.095,.065,.04],[0,1.98,.32+length],dark);
        ball('smile',[.15,.025,.02],[0,1.84,.33+length],dark);
    }
    if(human) {ball('nose',[.075,.09,.09],[0,2,.365]);ball('smile',[.12,.025,.02],[0,1.87,.37],dark);}
    for(const sign of [-1,1]) {
        ball('eye-white',[.1,.12,.055],[sign*.16,2.13,.35],white);
        ball('pupil',[.044,.063,.025],[sign*.16,2.12,.4],dark);
        if(!bird && !aquatic) {
            if(['wolf','cat','tiger','jaguar','panther','lion','elf'].includes(character)) cone('pointed-ear',.14,.32,[sign*.33,2.43,0],body,sign*-.25);
            else ball('ear',[human?.08:.15,character==='dog'?.3:.15,.09],[sign*(human?.32:.36),character==='dog'?2.07:2.38,0]);
        }
    }
    if(bird) {
        cone('beak',.15,.38,[0,1.98,.5],gold).rotation.x=Math.PI/2;
        for(const sign of [-1,1]) cone('tail-feather',.1,.55,[sign*.12,.92,-.37],body,sign*.25).rotation.x=-.65;
        if(character==='cardinal')cone('crest',.16,.32,[0,2.52,0]);
    } else if(aquatic) {
        cone('dorsal-fin',.22,.6,[0,1.48,-.35],body).rotation.x=-Math.PI/2;
        for(const sign of [-1,1]) ball('tail-fluke',[.28,.065,.16],[sign*.2,.78,-.44]);
        if(character==='shark')for(let i=-2;i<=2;i++)cone('tooth',.035,.08,[i*.07,1.86,.61],white,Math.PI);
    } else if(!human) {
        const tail=ball('tail',[.11,.36,.12],[0,.8,-.38]);tail.rotation.x=-.8;
        if(character==='horse') {ball('mane',[.16,.45,.16],[0,2.04,-.24],dark);tail.material=dark;}
        if(character==='lion') {
            const mane=new THREE.Mesh(new THREE.TorusGeometry(.4,.13,8,28),gold);mane.name='mane';mane.position.set(0,2.04,-.04);group.add(mane);
        }
        if(['bull','buffalo'].includes(character))for(const sign of [-1,1])cone('horn',.09,.4,[sign*.43,2.42,0],white,sign*-.8);
        if(character==='ram')for(const sign of [-1,1]) {
            const horn=new THREE.Mesh(new THREE.TorusGeometry(.18,.065,8,20,Math.PI*1.7),gold);horn.name='curled-horn';horn.position.set(sign*.4,2.2,0);horn.rotation.y=Math.PI/2;group.add(horn);
        }
        if(character==='pig')for(const sign of [-1,1])ball('nostril',[.025,.035,.018],[sign*.08,1.93,.48],dark);
        if(character==='raccoon')for(const sign of [-1,1])ball('eye-mask',[.16,.16,.035],[sign*.17,2.12,.326],dark);
        if(character==='tiger')for(const sign of [-1,1])for(let i=0;i<3;i++)box('stripe',[.1,.035,.03],[sign*(.27+i*.025),2.05-i*.07,.305],dark).rotation.z=sign*.3;
        if(character==='jaguar')for(const sign of [-1,1])for(let i=0;i<4;i++)ball('spot',[.04,.045,.025],[sign*(.27+(i%2)*.05),2.03-Math.floor(i/2)*.1,.32],dark);
    }
    if(human) {
        if(['cowboy','prospector','patriot'].includes(character)) {
            const hat=ball('hat-crown',[.35,.22,.3],[0,2.4,0],character==='patriot'?shirt:gold);
            if(character==='patriot')hat.scale.x=.45;
            ball('hat-brim',[.5,.035,.37],[0,2.29,.02],hat.material);
        }
        if(character==='steelworker') {ball('hard-hat',[.36,.2,.34],[0,2.37,0],gold);box('hard-hat-brim',[.76,.05,.66],[0,2.3,0],gold);}
        if(character==='king') {
            const crown=new THREE.Mesh(new THREE.CylinderGeometry(.34,.32,.16,20,true),gold);crown.name='crown';crown.position.y=2.35;group.add(crown);
            for(let i=0;i<7;i++){const a=i*Math.PI*2/7;cone('crown-point',.065,.2,[Math.cos(a)*.3,2.5,Math.sin(a)*.3],gold);}
        }
        if(character==='knight') {ball('helmet',[.35,.4,.36],[0,2.07,.02],metal);box('visor',[.55,.075,.02],[0,2.13,.385],dark);for(let i=-2;i<=2;i++)box('visor-bar',[.018,.11,.025],[i*.09,2.13,.405],metal);ball('shoulder-armor',[.25,.15,.27],[-.43,1.57,0],metal);ball('shoulder-armor',[.25,.15,.27],[.43,1.57,0],metal);}
        if(character==='viking') {ball('helmet',[.35,.23,.32],[0,2.33,0],metal);for(const sign of [-1,1])cone('helmet-horn',.1,.45,[sign*.4,2.47,0],white,sign*-.8);ball('beard',[.25,.27,.12],[0,1.82,.29],gold);}
        if(character==='pirate') {ball('pirate-hat',[.47,.23,.26],[0,2.38,0],dark);ball('eyepatch',[.11,.12,.035],[-.16,2.13,.423],dark);}
        if(character==='elf')cone('elf-hat',.33,.52,[0,2.55,0],shirt,-.2);
    }
    const texture=logoUrl && textureFor(logoUrl);
    if(texture) {
        const geometry=new THREE.PlaneGeometry(.32,.32,8,8),points=geometry.attributes.position;
        for(let i=0;i<points.count;i++){const x=points.getX(i),y=points.getY(i)+.13;points.setZ(i,.29*Math.sqrt(Math.max(0,1-(x/.43)**2-(y/.57)**2))+.007);}
        geometry.computeVertexNormals();
        const logo=new THREE.Mesh(geometry,new THREE.MeshBasicMaterial({map:texture,transparent:true,depthWrite:false}));logo.name='mascot-shirt-logo';logo.position.y=1.3;group.add(logo);
        logo.onBeforeRender=()=>{if(!texture.image)return;const fit=fitLogo(texture.image.width,texture.image.height,.32,.32);logo.scale.set(fit.width/.32,fit.height/.32,1);};
    }
    return group;
}
