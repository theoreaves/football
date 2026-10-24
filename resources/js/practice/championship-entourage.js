import * as THREE from 'three';
import {buildCheerleader,animateCheerleader} from './cheerleader-model.js';
import {buildMascot} from './mascot-model.js';
import {entrancePose} from './tunnel-entrance.js';

// Presentation-only visiting-team entourage. Never changes game state or game RNG.
// Existing home-team entourage remains responsible for the home entrance.
export function buildChampionshipAwayEntourage(scene, appearance, textureFor) {
    if (appearance.home?.championship_field !== true) return {update(){}, available:false};
    const away = appearance.away || {};
    const cheerProfile = away.cheerleaders || {};
    const showCheer = cheerProfile.enabled !== false && cheerProfile.enabled !== 0 && cheerProfile.enabled !== '0';
    const mascotProfile = away.mascot || {};
    const showMascot = mascotProfile.enabled !== false && mascotProfile.enabled !== 0 && mascotProfile.enabled !== '0'
        && Boolean(mascotProfile.character || mascotProfile.name);
    const cheerleaders = [];
    if (showCheer) {
        for (let i = 0; i < 6; i++) {
            const model = buildCheerleader({primary:away.primary_color,secondary:away.secondary_color,...cheerProfile},away.team_logo,textureFor);
            model.name = `championship-away-cheerleader-${i}`;
            scene.add(model);
            cheerleaders.push(model);
        }
    }
    const mascot = showMascot ? buildMascot(mascotProfile,away.team_logo,textureFor) : null;
    if (mascot) { mascot.name = 'championship-away-mascot'; scene.add(mascot); }
    // Keep the visiting entourage aligned to the EXISTING right-side away tunnel.
    // Its greeting corridor stays beyond the right end line; retire along the
    // outside perimeter to the away bench, never across the playing surface.
    const greet = cheerleaders.map((_,i) => ({x:125+(i%2)*2.2,z:16+Math.floor(i/2)*8}));
    const spots = cheerleaders.map((_,i) => ({x:80+(i%3)*7,z:65.63+(Math.floor(i/3)*2)}));
    const mascotGreet = {x:126,z:39};
    const mascotSpot = {x:75,z:69};
    const tunnelStart = i => ({x:133,z:36.665+(i%2?.9:-.9)});
    const greetingRoute = (i,to) => [tunnelStart(i),{x:130,z:tunnelStart(i).z},to];
    const retireRoute = (from,to) => [from,{x:129,z:63.5},{x:to.x,z:63.5},to];
    const update = (delta,seconds=null) => {
        for (let i=0;i<cheerleaders.length;i++) {
            const model=cheerleaders[i],destination=greet[i];
            const time=seconds===null?null:seconds-2;
            const pose=time===null?null:time<19
                ? entrancePose('away',i,destination,time,greetingRoute(i,destination))
                : entrancePose('away',i,spots[i],time-19,retireRoute(destination,spots[i]),false);
            model.visible=pose?pose.visible:true;
            model.position.set(pose?.x??spots[i].x,pose?.bob??0,pose?.z??spots[i].z);
            model.rotation.y=pose?.yaw??0;
            animateCheerleader(model,Math.max(0,seconds??0),i,seconds!==null&&seconds<35);
        }
        if (mascot) {
            const time=seconds===null?null:seconds-9;
            const pose=time===null?null:time<13
                ? entrancePose('away',0,mascotGreet,time,greetingRoute(0,mascotGreet),false)
                : entrancePose('away',0,mascotSpot,time-13,retireRoute(mascotGreet,mascotSpot),false);
            mascot.visible=pose?pose.visible:true;
            mascot.position.set(pose?.x??mascotSpot.x,pose?.bob??0,pose?.z??mascotSpot.z);
            mascot.rotation.y=pose?.yaw??0;
        }
    };
    update(0,null);
    return {update,available:Boolean(cheerleaders.length||mascot)};
}
