import {entrancePose} from './tunnel-entrance.js';
import * as THREE from 'three';
import {sidelineNumbers} from './sideline-numbers.js';
import {fitLogo} from './logo-fit.js';
import {helmetLogoGeometry,helmetStripeGeometry,helmetShellGeometry} from './helmet-shell.js';
import {playerKey,sidelineSlot} from './unit-exchange.js';
// Shared geometry and instanced parts keep a full bench inexpensive to draw.
export function buildSidelinePlayers(scene, roster, appearance, document = globalThis.document, textureFor = () => null) {
    const groups = [], dummy = new THREE.Object3D();
    for (const side of ['home','away']) {
        const people = roster[side] || [], kit = appearance[side]?.uniform || {};
        if (!people.length) continue;
        const teamArea = new THREE.Group();teamArea.name = `${side}-team-area`;scene.add(teamArea);
        const centerZ = side === 'home' ? -8.9 : 62.23;
        const addBox = (size,position,color) => {
            const mesh = new THREE.Mesh(new THREE.BoxGeometry(...size),new THREE.MeshStandardMaterial({color,roughness:.9}));
            mesh.position.set(...position);teamArea.add(mesh);return mesh;
        };
        // The boundary/officials' strip stays clear in front of the team area.
        addBox([86,.04,8.4],[60,-.03,centerZ],'#244b35');
        for (const z of [centerZ-4.2,centerZ+4.2]) addBox([86,.03,.12],[60,.015,z],'#f3f1d9');
        for (const x of [17,103]) addBox([.12,.03,8.4],[x,.015,centerZ],'#f3f1d9');
        const benchZ = side === 'home' ? -12.3 : 65.63;
        for (const x of [35,60,85]) {
            addBox([8.5,.14,.9],[x,.55,benchZ],kit.helmet || '#eeeeee');
            addBox([8.5,.65,.12],[x,1,benchZ + (side === 'home' ? -.4 : .4)],kit.shirt || '#3997ff');
            for (const dx of [-3,3]) addBox([.12,.55,.65],[x+dx,.27,benchZ],'#657587');
        }
        const addDetail = (geometry,material,offset,name,hideMissingNumber=false) => {
            const mesh=new THREE.InstancedMesh(geometry,material,people.length);mesh.name=name;mesh.frustumCulled=false;scene.add(mesh);
            groups.push({mesh,people,side,offset,hideMissingNumber});return mesh;
        };
        if (document && people.some(p=>p.number!=null)) {
            const {geometry,material}=sidelineNumbers(people,kit,document);
            addDetail(geometry,material,[0,0,0],`${side}-sideline-numbers`,true);
        }
        if (kit.helmet_stripe_enabled) addDetail(helmetStripeGeometry(),new THREE.MeshStandardMaterial({color:kit.helmet_stripe || '#ffffff',
            polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1}),[0,2.03,0],`${side}-sideline-helmet-stripe`);
        for (const [sign,url] of [[-1,appearance[side]?.helmet_logo_left],[1,appearance[side]?.helmet_logo_right]]) {
            const texture=url && textureFor(url);if(!texture)continue;
            const mesh=addDetail(helmetLogoGeometry(sign),new THREE.MeshBasicMaterial({map:texture,transparent:true,depthWrite:false,side:THREE.DoubleSide}),
                [0,2.03,0],`${side}-sideline-helmet-logo-${sign}`);
            let dimensions='';mesh.onBeforeRender=()=>{
                if(!texture.image)return;const key=`${texture.image.width}:${texture.image.height}`;if(key===dimensions)return;
                const size=fitLogo(texture.image.width,texture.image.height,.36,.29);
                mesh.geometry.dispose();mesh.geometry=helmetLogoGeometry(sign,size.width,size.height);dimensions=key;
            };
        }
        const parts = [
            [[.88,.66,.5],[0,1.25,0],kit.shirt || '#3997ff'],
            [[.69,.25,.44],[0,.83,0],kit.pants || '#eeeeee'],
            [[.26,.62,.28],[-.23,.47,0],kit.pants || '#eeeeee'],
            [[.26,.62,.28],[.23,.47,0],kit.pants || '#eeeeee'],
            [[.24,.21,.25],[-.23,.22,0],kit.socks || '#ffffff'],
            [[.24,.21,.25],[.23,.22,0],kit.socks || '#ffffff'],
            [[.27,.14,.42],[-.23,.08,.06],'#17202b'],
            [[.27,.14,.42],[.23,.08,.06],'#17202b'],
            [[.25,.5,.3],[-.55,1.25,0],kit.shirt || '#3997ff'],
            [[.25,.5,.3],[.55,1.25,0],kit.shirt || '#3997ff'],
            [[.45,.42,.36],[0,1.97,.08],'#c78e61','head'],
            [[1,1,1],[0,2.03,0],kit.helmet || '#eeeeee','helmet'],
            [[.5,.07,.06],[0,1.87,.3],kit.facemask || '#17202b'],
        ];
        for (const [size,offset,color,shape] of parts) {
            const geometry = shape === 'helmet' ? helmetShellGeometry()
                : shape === 'head' ? new THREE.SphereGeometry(.5,12,8).scale(...size) : new THREE.BoxGeometry(...size);
            const mesh = new THREE.InstancedMesh(geometry,new THREE.MeshStandardMaterial({color:shape === 'head' ? '#ffffff' : color,roughness:.75}),people.length);
            if (shape === 'head') people.forEach((person,i)=>mesh.setColorAt(i,new THREE.Color(person.skin_tone || '#c78e61')));
            mesh.name = `${side}-sideline-players`; mesh.frustumCulled = false; scene.add(mesh);
            groups.push({mesh,people,side,offset});
        }
    }
    let previous = '';
    return {update(active,entranceSeconds=null) {
        const signature = [...active].sort().join('|');
        if (entranceSeconds===null && signature === previous && previous !== '') return;
        previous = entranceSeconds===null?signature:'';
        for (const {mesh,people,side,offset,hideMissingNumber} of groups) {
            people.forEach((person,i) => {
                const slot = sidelineSlot(side,i), height = (person.height_inches || 73)/73;
                const sign = side === 'home' ? 1 : -1;
                dummy.position.set(slot.x + offset[0]*sign,offset[1]*height,slot.z + offset[2]*sign);
                dummy.rotation.set(0,side === 'home' ? 0 : Math.PI,0);
                dummy.scale.setScalar((active.has(playerKey({...person,side})) || (hideMissingNumber && person.number == null)) ? 0 : height);
                if(entranceSeconds!==null){
                    const seconds=typeof entranceSeconds==='object'?entranceSeconds[side]:entranceSeconds;
                    const pose=entrancePose(side,i,slot,seconds),angle=pose.yaw;
                    dummy.position.set(pose.x+offset[0]*Math.cos(angle)+offset[2]*Math.sin(angle),offset[1]*height+(pose.moving?pose.bob:0),pose.z-offset[0]*Math.sin(angle)+offset[2]*Math.cos(angle));
                    if(pose.moving && offset[1]<.8)dummy.position.z+=Math.sin(seconds*12+(offset[0]<0?0:Math.PI))*.12;
                    dummy.rotation.y=angle;dummy.scale.setScalar(!pose.visible || (hideMissingNumber && person.number==null)?0:height);
                }
                dummy.updateMatrix();mesh.setMatrixAt(i,dummy.matrix);
            });
            mesh.instanceMatrix.needsUpdate = true;
        }
    }};
}
