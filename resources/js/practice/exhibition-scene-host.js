import * as THREE from 'three';
import {buildFieldSurface} from './field-scene.js';
import {buildStadium} from './stadium.js';
import {buildFootballPlayer,animateFootballPlayer} from './player-model.js';
import {buildSidelinePlayers} from './sideline-players.js';
import {buildSidelineCoaches} from './coach-model.js';
import {buildSidelineMascot} from './sideline-mascot.js';
import {buildSidelineCheerleaders} from './sideline-cheerleaders.js';
import {buildChampionshipAwayEntourage} from './championship-entourage.js';
import {stadiumAudio} from './stadium-audio.js';
import {createExhibitionUnitStaging} from './exhibition-unit-staging.js';
import {createExhibitionCameraDirector} from './exhibition-camera-director.js';

export const exhibitionActorKey=player=>`${player.side}:${player.id ?? player.role}`;
export function createPlayerRegistry(scene,build) {
    const models=new Map();
    return {
        models,
        get(player,kit){
            if(!['home','away'].includes(player.side) || (player.id==null && !player.role))throw new Error('A saved player identity is required.');
            const key=exhibitionActorKey(player);
            if(!models.has(key)){const model=build(player,kit);model.visible=false;model.userData.exhibitionActorKey=key;models.set(key,model);scene.add(model);}
            return models.get(key);
        },
        hide(){models.forEach(model=>{model.visible=false;});},
    };
}
function disposeTree(group,sharedTextures=new Set()) {
    const geometries=new Set(),materials=new Set(),textures=new Set();
    group.traverse(object=>{if(object.geometry)geometries.add(object.geometry);for(const material of Array.isArray(object.material)?object.material:object.material?[object.material]:[]){materials.add(material);for(const value of Object.values(material))if(value?.isTexture && !sharedTextures.has(value))textures.add(value);}});
    geometries.forEach(value=>value.dispose());materials.forEach(value=>value.dispose());textures.forEach(value=>value.dispose());
}

// This owner outlives per-play adapters. Only exit destroys the configured scene.
export function createExhibitionSceneHost(host,{appearance={},crowd={},sidelineRoster={},audioRoot=host}={},deps={}) {
    const document=host.ownerDocument,win=document.defaultView || globalThis.window;
    const renderer=(deps.rendererFactory || (options=>new THREE.WebGLRenderer(options)))({antialias:true});
    renderer.setPixelRatio(Math.min(win.devicePixelRatio || 1,2));renderer.setClearColor(0x101a2b);
    renderer.shadowMap.enabled=true;renderer.shadowMap.type=THREE.PCFSoftShadowMap;
    const canvas=renderer.domElement;canvas.style.cssText='display:block;width:100%;height:100%';canvas.setAttribute('aria-label','WebSports exhibition field');host.append(canvas);
    const rendererId=THREE.MathUtils.generateUUID(),canvasId=THREE.MathUtils.generateUUID();canvas.setAttribute('data-presentation-canvas',canvasId);
    const scene=new THREE.Scene();scene.fog=new THREE.Fog(0x101a2b,160,280);
    const cameras={broadcast:new THREE.PerspectiveCamera(42,1,.1,350),arcade:new THREE.OrthographicCamera(-24,24,19,-19,.1,300),replay:new THREE.PerspectiveCamera(48,1,.1,500)};
    cameras.broadcast.position.set(60,48,91.665);cameras.broadcast.lookAt(60,0,26.665);
    const cameraDirector=createExhibitionCameraDirector(cameras.broadcast);
    const textures=new Map(),loader=deps.textureLoader || new THREE.TextureLoader(),cache=new Map(),layers=new Set(),resizeListeners=new Set();
    let disposed=false,adapter=null,frameId=null,lastTime=null,frames=0;
    const timings=[], frameIntervals=[];
    let lastActors=[],pendingStage=false,staging=null,stageElapsed=0,adapterTime=null;
    const capturePlayers=()=>[...registry.models.values()].filter(model=>model.visible).map(model=>({key:model.userData.exhibitionActorKey,x:model.position.x,y:model.position.y,z:model.position.z}));
    const textureFor=(url,onLoad)=>{
        if(!url)return null;
        if(textures.has(url)){const texture=textures.get(url);if(texture.image)onLoad?.(texture);else if(onLoad)texture.userData.callbacks.push(onLoad);return texture;}
        const texture=loader.load(url,loaded=>{if(disposed){loaded.dispose();return;}loaded.userData.callbacks?.forEach(callback=>callback?.(loaded));loaded.userData.callbacks=[];});
        texture.colorSpace=THREE.SRGBColorSpace;texture.userData.callbacks=onLoad?[onLoad]:[];textures.set(url,texture);return texture;
    };
    const box=(w,h,d,color,x,y,z)=>{const mesh=new THREE.Mesh(new THREE.BoxGeometry(w,h,d),new THREE.MeshStandardMaterial({color,roughness:.8}));mesh.position.set(x,y,z);mesh.receiveShadow=true;scene.add(mesh);return mesh;};
    const home=appearance.home || {},away=appearance.away || {};
    scene.add(new THREE.HemisphereLight(0xbad7ff,0x314b27,2.2));
    const sun=new THREE.DirectionalLight(0xfff5dc,3);sun.position.set(45,70,15);sun.castShadow=true;Object.assign(sun.shadow.camera,{left:-90,right:90,top:80,bottom:-80});sun.shadow.mapSize.set(2048,2048);scene.add(sun);
    buildFieldSurface(scene,home,away,document,textureFor,box,{load:textureFor});
    const stadium=buildStadium(home,document,away,crowd,textureFor);scene.add(stadium.group);
    for(const x of [0,120]){box(.18,3.5,.18,0xffcc33,x,1.75,26.7);box(.18,.18,6.2,0xffcc33,x,3.5,26.7);for(const z of [23.6,29.8])box(.18,5,.18,0xffcc33,x,6,z);}
    const registry=createPlayerRegistry(scene,(player,kit)=>buildFootballPlayer(player,kit,document,textureFor));
    const ball=new THREE.Mesh(new THREE.SphereGeometry(.25,12,8),new THREE.MeshStandardMaterial({color:0x9d5829,roughness:.8}));ball.name='exhibition-football';ball.scale.set(1.6,.85,.85);ball.castShadow=true;scene.add(ball);
    const sideline=buildSidelinePlayers(scene,sidelineRoster,appearance,document,textureFor);
    const sidelineCoaches=buildSidelineCoaches(scene,appearance,textureFor);
    const sidelineMascot=buildSidelineMascot(scene,appearance,textureFor,audioRoot.dataset.cameraKey || 'exhibition');
    const sidelineCheerleaders=buildSidelineCheerleaders(scene,appearance,textureFor);
    const championshipAwayEntourage=buildChampionshipAwayEntourage(scene,appearance,textureFor);
    const audio=(deps.audioFactory || stadiumAudio)(audioRoot,win);
    const resize=()=>{if(disposed)return;const w=Math.max(1,host.clientWidth),h=Math.max(1,host.clientHeight);renderer.setSize(w,h,false);for(const camera of [cameras.broadcast,cameras.replay]){camera.aspect=w/h;camera.updateProjectionMatrix();}resizeListeners.forEach(listener=>listener(w,h));};
    const Observer=deps.ResizeObserver || win.ResizeObserver;const observer=new Observer(resize);observer.observe(host);
    const raf=deps.requestAnimationFrame || win.requestAnimationFrame.bind(win),cancel=deps.cancelAnimationFrame || win.cancelAnimationFrame.bind(win);
    const render=()=>{if(!disposed)renderer.render(scene,cameraDirector.camera);};
    let idleAtmosphereStepped=false;
    const tickIdleAtmosphere=delta=>{
        if(disposed || idleAtmosphereStepped)return;
        idleAtmosphereStepped=true;
        const step=document.hidden?0:Math.min(.1,Math.max(0,Number(delta)||0));
        stadium.updateCrowd(step);
        sidelineCoaches.update(step,null);
        if(!document.hidden){sidelineMascot.update(step,null);sidelineCheerleaders.update(step,null);championshipAwayEntourage.update(step,null);}
        // Preserve the existing ambient bed without triggering play cues/music.
        audio.setPlayback(false,!document.hidden);
    };
    const tick=now=>{
        if(disposed)return;
        if(lastTime!==null){frameIntervals.push({ms:Math.max(0,now-lastTime),visible:!document.hidden,staging:pendingStage || Boolean(staging)});if(frameIntervals.length>240)frameIntervals.shift();}
        const delta=lastTime===null || document.hidden?0:Math.min(.1,Math.max(0,(now-lastTime)/1000));lastTime=now;
        if(adapterTime===null)adapterTime=now;
        const start=win.performance.now();idleAtmosphereStepped=false;
        const adapterStepped=!staging && Boolean(adapter?.tick);
        if(!staging){adapterTime+=delta*1000;adapter?.tick?.(adapterTime,delta);}
        if(pendingStage){pendingStage=false;staging=lastActors.length?createExhibitionUnitStaging(lastActors,capturePlayers(),sidelineRoster):null;stageElapsed=0;if(!staging?.duration)staging=null;}
        if(staging){
            stageElapsed=Math.min(staging.duration,stageElapsed+delta);
            const active=new Set();
            staging.sample(stageElapsed).forEach((pose,index)=>{const model=registry.models.get(pose.key);if(!model)return;model.visible=pose.visible;if(!pose.visible)return;active.add(pose.key);model.position.set(pose.x,pose.y,pose.z);if(pose.moving){model.rotation.set(0,pose.heading,0);animateFootballPlayer(model,true,stageElapsed,index);}});
            sideline.update(active);ball.visible=false;if(!adapterStepped)tickIdleAtmosphere(delta);
            if(stageElapsed>=staging.duration)staging=null;
        }
        cameraDirector.tick(delta);render();lastActors=capturePlayers();frames++;
        timings.push(win.performance.now()-start);if(timings.length>240)timings.shift();frameId=raf(tick);
    };
    const api={host,scene,renderer,canvas,cameras,cameraDirector,textureFor,ball,stadium,audio,registry,sideline,sidelineCoaches,sidelineMascot,sidelineCheerleaders,championshipAwayEntourage,
        capturePlayers,tickIdleAtmosphere,
        get staging(){return pendingStage || Boolean(staging);},
        stageNextFrame(){pendingStage=true;staging=null;ball.removeFromParent();scene.add(ball);},
        player:(player,kit)=>registry.get(player,kit),hidePlayers:()=>registry.hide(),
        once(key,factory){if(!cache.has(key))cache.set(key,factory());return cache.get(key);},
        createLayer(name){const layer=new THREE.Group();layer.name=name;scene.add(layer);layers.add(layer);return layer;},
        releaseLayer(layer){if(!layers.delete(layer))return;
            // Borrowed registry actors and the ball are never layer-owned, even
            // if a pose adapter temporarily attached one below its overlay.
            for(const object of [...registry.models.values(),ball]){let parent=object.parent;while(parent && parent!==layer)parent=parent.parent;if(parent===layer)scene.attach(object);}
            layer.removeFromParent();disposeTree(layer,new Set(textures.values()));layer.clear();},
        activate(next,{stage=false}={}){adapter=next;lastTime=null;ball.removeFromParent();scene.add(ball);if(stage)pendingStage=true;},
        subscribeResize(listener){resizeListeners.add(listener);listener(Math.max(1,host.clientWidth),Math.max(1,host.clientHeight));return()=>resizeListeners.delete(listener);},
        resize,render,
        metrics(){return {sceneId:scene.uuid,canvasId,rendererId,canvas,renderer,stadiumId:stadium.group.uuid,ballId:ball.uuid,frames,frameMs:[...timings],cpuFrameMs:[...timings],frameIntervals:frameIntervals.map(sample=>({...sample})),resizeSubscriptions:resizeListeners.size,drawCalls:renderer.info?.render.calls ?? 0,geometries:renderer.info?.memory.geometries ?? 0,textures:renderer.info?.memory.textures ?? 0,actors:registry.models.size,visibleActors:[...registry.models.values()].filter(model=>model.visible).length,layers:layers.size,rafCount:disposed?0:1,audioOwners:disposed?0:1,activeAdapters:adapter?1:0,transitioning:cameraDirector.transitioning,staging:pendingStage || Boolean(staging),disposed};},
        dispose(){if(disposed)return;disposed=true;cancel(frameId);observer.disconnect();adapter?.dispose?.();adapter=null;audio.dispose();disposeTree(scene,new Set(textures.values()));textures.forEach(texture=>texture.dispose());sun.shadow.dispose();renderer.dispose();canvas.remove();scene.clear();layers.clear();registry.models.clear();cache.clear();resizeListeners.clear();},
    };
    resize();frameId=raf(tick);return api;
}
