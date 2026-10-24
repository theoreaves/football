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
        for (let i = 0; i < 12; i++) {
            const model = buildCheerleader({primary:away.primary_color,secondary:away.secondary_color,...cheerProfile},away.team_logo,textureFor);
            model.name = `championship-away-cheerleader-${i}`;
            scene.add(model);
            cheerleaders.push(model);
        }
    }
    const mascot = showMascot ? buildMascot(mascotProfile,away.team_logo,textureFor) : null;
    if (mascot) { mascot.name = 'championship-away-mascot'; scene.add(mascot); }
    // Championship visitor ceremony mirrors the *working regular-season home*
    // greeting: twelve cheerleaders in two rows, walking out of the right
    // tunnel in staggered pairs. Remain in the greeting corridor until the
    // away entrance completes (35 seconds), then retire around the perimeter.
    // The entire module is championship-only; normal games never construct it.
    const greeting = cheerleaders.map((_,i) => ({
        x:122-Math.floor(i/2)*3,
        z:36.665+(i%2?3:-3),
    }));
    const sideline = cheerleaders.map((_,i) => ({
        x:16+Math.floor(i/2)*17,
        z:63.5+(i%2)*2.5,
    }));
    const mascotGreeting = {x:124,z:36.665};
    const mascotSideline = {x:75,z:69};
    const entranceStart = i => ({x:133,z:36.665+(i%2?-.9:.9)});
    const greetingRoute = (i,to) => [
        entranceStart(i),
        {x:128,z:entranceStart(i).z},
        {x:to.x,z:to.z},
    ];
    const retireRoute = (from,to) => [
        from,{x:129,z:from.z},{x:129,z:60.5},
        {x:to.x,z:60.5},to,
    ];
    const visitingEntranceEnd = 35;
    const update = (delta,seconds=null) => {
        for (let i=0;i<cheerleaders.length;i++) {
            const model=cheerleaders[i],target=greeting[i];
            let pose=null;
            if(seconds!==null){
                if(seconds<visitingEntranceEnd){
                    pose=entrancePose('away',i,target,seconds,greetingRoute(i,target));
                    // The normal home introduction finishes in a stable
                    // two-row greeting, rather than a moving sideline chain.
                    if(pose.complete)pose.yaw=i%2?0:Math.PI;
                }else{
                    pose=entrancePose('away',i,sideline[i],seconds-visitingEntranceEnd,
                        retireRoute(target,sideline[i]),false);
                }
            }
            model.visible=pose?pose.visible:true;
            model.position.set(pose?.x??sideline[i].x,pose?.bob??0,pose?.z??sideline[i].z);
            model.rotation.y=pose?.yaw??Math.PI;
            animateCheerleader(model,Math.max(0,seconds??0),i,seconds!==null&&seconds<visitingEntranceEnd);
        }
        if (mascot) {
            let pose=null;
            if(seconds!==null){
                pose=seconds<visitingEntranceEnd
                    ? entrancePose('away',0,mascotGreeting,seconds-6,
                        greetingRoute(0,mascotGreeting),false)
                    : entrancePose('away',0,mascotSideline,seconds-visitingEntranceEnd,
                        retireRoute(mascotGreeting,mascotSideline),false);
            }
            mascot.visible=pose?pose.visible:true;
            mascot.position.set(pose?.x??mascotSideline.x,pose?.bob??0,pose?.z??mascotSideline.z);
            mascot.rotation.y=pose?.yaw??Math.PI;
        }
    };
    update(0,null);
    return {update,available:Boolean(cheerleaders.length||mascot)};
}
