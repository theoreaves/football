import * as THREE from 'three';
import {buildReferees, refereeFormation, refereeSignal, applyResultSignal} from './referees.js';

export function resultRefereeSignal(animation, before, after) {
    if (!animation || !before || !after || animation.no_snap || after.penalty_pending) return null;
    const summary = animation.events?.at(-1)?.[1]?.toLowerCase() || '';
    if (summary.includes('nullified') || summary.includes('awaiting')) return null;
    if (['field_goal','extra_point'].includes(animation.call)) {
        const side = before.possession;
        const points = Number(after[`${side}_score`]) - Number(before[`${side}_score`]);
        const good = animation.call === 'field_goal' ? 'field_goal_good' : 'extra_point_good';
        if (animation.outcome === good && points === (animation.call === 'field_goal' ? 3 : 1)) return 'good';
        if (['field_goal_missed','field_goal_blocked','extra_point_missed','extra_point_blocked'].includes(animation.outcome) && points === 0) return 'no_good';
        return null;
    }
    const ruling = refereeSignal(animation,before,after);
    if (ruling) return ruling;
    if (resultTurnover(animation,before,after) && after.phase === 'scrimmage' && after.down === 1) return 'first_down';
    if (animation.passing && animation.outcome === 'incomplete') return 'incomplete';
    if (animation.passing && ['tackle','touchdown','fumble','complete'].includes(animation.outcome)) return 'complete';
    return null;
}

export function resultTurnover(animation, before, after) {
    return Boolean(animation && before && after && !animation.no_snap && !after.penalty_pending
        && ['interception','fumble'].includes(animation.outcome)
        && ['home','away'].includes(after.possession) && after.possession !== before.possession);
}

export function resultSignalDirection(animation, before, after) {
    const direction = animation?.direction === -1 ? -1 : 1;
    return resultTurnover(animation,before,after) ? -direction : direction;
}

export function resultRefereeSequence(animation, before, after) {
    const signal = resultRefereeSignal(animation,before,after);
    if (!signal) return [];
    const caught = !resultTurnover(animation,before,after) && animation.passing && ['tackle','touchdown','fumble','complete'].includes(animation.outcome);
    return caught && ['first_down','touchdown'].includes(signal) ? ['complete',signal] : [signal];
}

export function resultSignalAt(sequence, time) {
    return {signal: sequence[Math.min(sequence.length - 1, Math.floor(Math.max(0,time) / 2.4))],
        time: sequence.length > 1 && time >= 2.4 ? time - 2.4 : time};
}

export function resultSignalPose(refs, signal, time, direction = 1) {
    const poses = refereeFormation(0,1); poses[0] = {x:0,z:0,velocity:0,travel:0};
    refs.update({poses,active:false});
    const body = refs.group.children[0]; body.rotation.y = 0;
    const joints = body.children.filter(child => child.isGroup);
    const arms = joints.slice(2);
    applyResultSignal({arms,elbows:arms.map(arm => arm.children.at(-1))},signal,time,direction);
}

export function buildResultReferee(popup, animation, before, after) {
    const sequence = resultRefereeSequence(animation,before,after);
    const signal = sequence[0];
    if (!popup || !signal) return {update(){},dispose(){}};
    const document = popup.ownerDocument;
    const panel = document.createElement('div'); panel.dataset.resultReferee = signal;
    panel.style.cssText = 'margin:8px auto 0;max-width:220px;';
    const caption = document.createElement('p');
    const labels = {first_down:'FIRST DOWN',touchdown:'TOUCHDOWN',good:'KICK IS GOOD',no_good:'NO GOOD',complete:'PASS COMPLETE',incomplete:'INCOMPLETE PASS'};
    caption.textContent = labels[signal];
    caption.className = 'text-sm font-semibold text-blue-200';panel.append(caption);
    popup.querySelector('[data-result-ok]').before(panel);
    let renderer,scene,camera,refs,elapsed=0,visible=false;
    const setup = () => {
        try {
            renderer = new THREE.WebGLRenderer({alpha:true,antialias:true});
            renderer.setPixelRatio(Math.min(globalThis.devicePixelRatio || 1,2));renderer.setSize(240,180);
            renderer.domElement.style.cssText = 'display:block;width:100%;height:150px;object-fit:contain;';
            renderer.domElement.setAttribute('aria-hidden','true');panel.prepend(renderer.domElement);
            scene = new THREE.Scene(); scene.add(new THREE.HemisphereLight(0xffffff,0x596070,3));
            camera = new THREE.PerspectiveCamera(35,240/180,.1,20);camera.position.set(0,1.2,4.8);camera.lookAt(0,1.2,0);
            refs = buildReferees(scene);refs.group.children.slice(1).forEach(ref => ref.visible=false);
        } catch { renderer?.dispose(); renderer=null; /* The text signal remains readable. */ }
    };
    return {
        update(delta) {
            const shown = !popup.hidden;
            if (!shown) {visible=false;elapsed=0;return;}
            if (!visible) {visible=true;elapsed=0;if(!refs && !renderer)setup();}
            elapsed += Math.max(0,Math.min(delta,.1));
            const current = resultSignalAt(sequence,elapsed);
            caption.textContent = labels[current.signal];panel.dataset.resultReferee = current.signal;
            if(renderer && refs){resultSignalPose(refs,current.signal,current.time,resultSignalDirection(animation,before,after));renderer.render(scene,camera);}
        },
        dispose() {
            panel.remove();scene?.traverse(object => {object.geometry?.dispose();object.material?.dispose();});
            renderer?.dispose();renderer?.forceContextLoss();
        }
    };
}
