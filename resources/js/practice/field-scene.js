import * as THREE from 'three';
import { fitLogo } from './logo-fit.js';

// Shared field artwork; Coach supplies its existing box factory and texture cache.
export function buildFieldSurface(scene, home, away, document, textureFor, addBox, textureLoader = new THREE.TextureLoader()) {
    addBox(144, 0.3, 78, 0x18392a, 60, -0.4, 26.7);
    addBox(120, 0.15, 53.33, 0x285d38, 60, -0.1, 26.665);
    for (let x = 10; x < 110; x += 10) addBox(10, 0.02, 53.33, x % 20 ? 0x31723f : 0x296638, x + 5, 0, 26.665);
    const championshipField = home.championship_field === true;
    const endzoneAt = x => championshipField && x === 115 ? away : home;
    for (const x of [5, 115]) {
        const design = endzoneAt(x);
        if (!design.endzone_transparent) addBox(10, 0.03, 53.33, design.endzone_background || 0x174880, x, 0.02, 26.665);
    }
    const field25Position = home.field_25_position || 'both';
    if(home.field_25_logo && field25Position !== 'none') {
        const placements = [
            ['left', 35, 39.8],
            ['right', 85, 13.5],
        ];
        textureLoader.load(home.field_25_logo, texture=>{
            texture.colorSpace=THREE.SRGBColorSpace;
            const size=fitLogo(texture.image.width,texture.image.height,2.35,2.35);
            for(const [side,x,z] of placements) {
                if(field25Position !== 'both' && field25Position !== side)continue;
                const mesh=new THREE.Mesh(
                    new THREE.PlaneGeometry(size.width,size.height),
                    new THREE.MeshBasicMaterial({map:texture,transparent:true,depthWrite:false})
                );
                mesh.name=`field-25-logo-${side}`;
                mesh.rotation.x=-Math.PI/2;
                mesh.position.set(x,.09,z);
                scene.add(mesh);
            }
        });
    }
    if (home.midfield_logo) {
        const logo = new THREE.Mesh(new THREE.PlaneGeometry(14, 14), new THREE.MeshBasicMaterial({map: textureFor(home.midfield_logo), transparent: true, depthWrite: false}));
        logo.rotation.x = -Math.PI / 2; logo.position.set(60, .095, 26.665); scene.add(logo);
    }
    for (const x of [5, 115]) {
        const design = endzoneAt(x);
        const url = championshipField ? design.championship_endzone_art : (x === 5 ? home.endzone_logo_left : home.endzone_logo_right);
        if (url) {
            const logo = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({transparent: true, depthWrite: false}));
            textureLoader.load(url, texture => {
                texture.colorSpace = THREE.SRGBColorSpace;
                const size = fitLogo(texture.image.width, texture.image.height, 44, 7);
                logo.scale.set(size.width, size.height, 1);
                logo.material.map = texture; logo.material.needsUpdate = true;
            });
            logo.rotation.set(-Math.PI / 2, 0, x === 5 ? Math.PI / 2 : -Math.PI / 2);
            logo.position.set(x, .08, 26.665); scene.add(logo); continue;
        }
        const canvas = document.createElement('canvas');
        canvas.width = 1024; canvas.height = 160;
        const context = canvas.getContext('2d');
        context.fillStyle = design.endzone_text_color || '#ffffff';
        context.textAlign = 'center'; context.textBaseline = 'middle';
        context.font = 'bold 100px sans-serif';
        context.fillText(design.endzone_text || design.name || 'FOOTBALL', 512, 80, 960);
        const sign = new THREE.Mesh(new THREE.PlaneGeometry(44, 7), new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(canvas), transparent: true, depthWrite: false }));
        sign.rotation.set(-Math.PI / 2, 0, x === 5 ? Math.PI / 2 : -Math.PI / 2);
        sign.position.set(x, 0.08, 26.665); scene.add(sign);
    }
    // Six-foot (two-yard) border sits outside the playable field.
    for (const z of [-1, 54.33]) addBox(124, 0.03, 2, 0xf3f1d9, 60, 0.05, z);
    for (const x of [-1, 121]) addBox(2, 0.03, 53.33, 0xf3f1d9, x, 0.05, 26.665);
    for (let x = 10; x <= 110; x += 5) {
        addBox(0.13, 0.03, 53.33, 0xf3f1d9, x, 0.05, 26.665);
        if (x % 10 === 0 && x > 10 && x < 110) {
            const canvas = document.createElement('canvas');
            canvas.width = 128; canvas.height = 128;
            const context = canvas.getContext('2d');
            context.fillStyle = '#ffffff'; context.textAlign = 'center'; context.font = 'bold 78px sans-serif';
            context.fillText(String(Math.min(x - 10, 110 - x)), 64, 92);
            const number = new THREE.Mesh(new THREE.PlaneGeometry(3, 3), new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(canvas), transparent: true, depthWrite: false }));
            number.rotation.x = -Math.PI / 2; number.position.set(x, 0.08, 6); scene.add(number);
            const opposite = number.clone(); opposite.position.z = 47; scene.add(opposite);
        }
    }
    for (let x = 11; x < 110; x++) for (const z of [0.6, 23.6, 29.7, 52.7]) addBox(0.1, 0.03, 0.55, 0xddddcb, x, 0.06, z);
}
