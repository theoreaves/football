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
    // The visiting championship end zone and newly branded tunnel are LEFT.
    // Form a short greeting lane beside that tunnel instead of marching all
    // the way to the far sideline through the home team's end zone.
    const slots = cheerleaders.map((_,i) => ({x:12+(i%3)*3,z:9+(Math.floor(i/3)*3)}));
    const mascotTarget = {x:17,z:16};
    const entranceStart = {x:-13,z:16.665};
    const greetingRoute = (i,target) => [
        {x:entranceStart.x,z:entranceStart.z+(i%2 ? .9 : -.9)},
        {x:2,z:entranceStart.z+(i%2 ? .9 : -.9)},
        {x:target.x,z:target.z},
    ];
    const update = (delta, seconds = null) => {
        for (let i=0;i<cheerleaders.length;i++) {
            const model = cheerleaders[i];
            const t = seconds === null ? null : seconds-3;
            const pose = t === null ? null : entrancePose('home',i,slots[i],t,greetingRoute(i,slots[i]));
            model.visible = pose ? pose.visible : true;
            if (pose) model.position.set(pose.x,pose.bob,pose.z);
            else model.position.set(slots[i].x,0,slots[i].z);
            model.rotation.y = pose?.yaw ?? 0;
            animateCheerleader(model, Math.max(0,seconds ?? 0),i, seconds !== null && seconds < 35);
        }
        if (mascot) {
            const pose = seconds === null ? null : entrancePose('home',0,mascotTarget,seconds-10,greetingRoute(0,mascotTarget));
            mascot.visible = pose ? pose.visible : true;
            mascot.position.set(pose?.x ?? mascotTarget.x,pose?.bob ?? 0,pose?.z ?? mascotTarget.z);
            mascot.rotation.y = pose?.yaw ?? 0;
        }
    };
    update(0,null);
    return {update,available:Boolean(cheerleaders.length || mascot)};
}
