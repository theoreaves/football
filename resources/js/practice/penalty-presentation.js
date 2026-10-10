import * as THREE from 'three';
import { sampleEnginePlay } from './engine-timeline.js';
import { buildReferees, refereeFormation } from './referees.js';

const labels = {false_start: 'False start', encroachment: 'Encroachment', holding: 'Holding',
    defensive_pass_interference: 'Defensive pass interference', face_mask: 'Face mask'};
export function penaltyMoment(animation, penalty, paths) {
    if (!animation || !penalty || !labels[penalty.type]) return null;
    const dead = ['false_start', 'encroachment'].includes(penalty.type);
    const at = dead ? .48 : penalty.type === 'holding' ? Math.min(1.25, animation.duration * .3)
        : penalty.type === 'defensive_pass_interference' ? Math.max(.3, (animation.catch_at ?? 3.8) - .2)
        : animation.contact_at ?? animation.result_at ?? animation.reveal_at ?? 5.3;
    const frame = sampleEnginePlay(animation, at);
    const team = ['false_start', 'holding'].includes(penalty.type) ? 'offense' : 'defense';
    const role = penalty.type === 'false_start' ? 'LG' : penalty.type === 'encroachment' ? 'DT1'
        : penalty.type === 'holding' ? 'LT' : penalty.type === 'face_mask' ? animation.tackler_role : null;
    const receiver = frame.players.find(p => p.team === 'offense' && p.role === animation.receiver_role);
    const target = receiver ?? frame.ball;
    const culprit = frame.players.find(p => p.team === team && p.role === role)
        ?? frame.players.filter(p => p.team === team).sort((a,b) => Math.hypot(a.x-target.x,a.z-target.z)-Math.hypot(b.x-target.x,b.z-target.z))[0];
    const point = culprit ?? frame.ball;
    const poses = paths.sample(at);
    const official = poses.map((pose, index) => ({index, distance: Math.hypot(pose.x-point.x,pose.z-point.z)}))
        .sort((a,b) => a.distance-b.distance || a.index-b.index)[0].index;
    const source = poses[official];
    return {at, dead, team, role: culprit?.role, official, point: {x: point.x, z: point.z},
        origin: {x: source.x, y: 1.55, z: source.z}, type: penalty.type};
}

export function flagPosition(moment, time) {
    if (!moment || time < moment.at) return null;
    const t = Math.max(0, Math.min(1, (time - moment.at) / .8));
    // Short, weighted-cloth throw from the reporting official toward the foul.
    const dx = moment.point.x - moment.origin.x, dz = moment.point.z - moment.origin.z;
    const distance = Math.hypot(dx,dz), scale = Math.min(1, 7 / Math.max(.001,distance));
    return {x: moment.origin.x + dx * scale * t, z: moment.origin.z + dz * scale * t,
        y: .08 + (moment.origin.y - .08) * (1-t) + 2.1 * 4*t*(1-t), landed: t === 1};
}

export function foulMovement(moment, time, direction) {
    if (!moment?.dead || time < .12) return {x: 0, y: 0, lean: 0};
    const t = Math.max(0, Math.min(1, (time - .12) / .36)), ease = t*t*(3-2*t);
    return moment.type === 'false_start'
        ? {x: direction * .48 * ease, y: Math.sin(Math.PI*t) * .16, lean: -.18 * Math.sin(Math.PI*t)}
        : {x: -direction * 2.1 * ease, y: Math.sin(Math.PI*t) * .09, lean: -.28 * Math.sin(Math.PI*t)};
}

export function penaltyText(penalty, names, pending = false) {
    const team = names[penalty.team] ?? penalty.team;
    const ruling = pending ? 'Awaiting accept / decline decision' : penalty.accepted
        ? `${penalty.yards} yards · ${['holding','false_start'].includes(penalty.type) ? 'Repeat down' : penalty.type === 'encroachment' ? 'Enforced' : 'Automatic first down'}`
        : 'Declined · ' + (['false_start','encroachment'].includes(penalty.type) ? 'No snap; down unchanged' : 'Play stands');
    return `${labels[penalty.type] ?? 'Penalty'} — ${team}. ${ruling}.`;
}

export function buildPenaltyPresentation(root, scene, penalty, names, pending) {
    const flag = new THREE.Group(); scene.add(flag); flag.visible = false;
    const yellow = new THREE.MeshStandardMaterial({color: 0xffdf00, roughness: .85, side: THREE.DoubleSide});
    const cloth = new THREE.Mesh(new THREE.PlaneGeometry(.55, .45, 2, 2), yellow);
    cloth.rotation.x = -Math.PI / 2; flag.add(cloth);
    const weight = new THREE.Mesh(new THREE.SphereGeometry(.11, 8, 6), yellow); flag.add(weight);
    const badge = document.createElement('div'); badge.hidden = true;
    badge.setAttribute('role','status'); badge.textContent = 'FLAG';
    badge.style.cssText = 'position:fixed;z-index:45;right:16px;top:90px;max-width:80vw;padding:10px 16px;background:#ffdf00;color:#15191e;font-weight:700;border-radius:8px;';
    root.append(badge);
    const panel = document.createElement('section');
    panel.setAttribute('role','status'); panel.setAttribute('aria-live','polite'); panel.hidden = true;
    panel.style.cssText = 'position:fixed;z-index:55;left:50%;top:14%;transform:translateX(-50%);width:min(92vw,440px);padding:14px;border:2px solid #ffdf00;border-radius:14px;background:#101827;color:white;text-align:center;box-shadow:0 12px 40px #0009;';
    const title = document.createElement('strong'); title.textContent = 'REFEREE ANNOUNCEMENT'; panel.append(title);
    const caption = document.createElement('p'); caption.textContent = penalty ? penaltyText(penalty,names,pending) : ''; caption.style.margin = '10px 0';
    let renderer = null, insetScene, insetCamera, insetRefs;
    if (penalty) {
        try {
            renderer = new THREE.WebGLRenderer({alpha: true, antialias: true});
            renderer.setPixelRatio(Math.min(window.devicePixelRatio,2)); renderer.setSize(320,220);
            renderer.domElement.style.cssText = 'display:block;width:100%;height:220px;object-fit:contain;';
            panel.append(renderer.domElement);
            insetScene = new THREE.Scene(); insetScene.add(new THREE.HemisphereLight(0xffffff,0x596070,3));
            insetCamera = new THREE.PerspectiveCamera(35,320/220,.1,20); insetCamera.position.set(0,1.35,4.2); insetCamera.lookAt(0,1.3,0);
            insetRefs = buildReferees(insetScene); insetRefs.group.children.slice(1).forEach(ref => ref.visible = false);
        } catch { /* Keep the readable announcement if a second WebGL context is unavailable. */ }
    }
    panel.append(caption); root.append(panel);
    const update = ({moment, time, announcing, announcementTime}) => {
        const position = flagPosition(moment,time);
        flag.visible = Boolean(position);
        if (position) {
            flag.position.set(position.x,position.y,position.z);
            flag.rotation.set(position.landed ? 0 : (time-moment.at)*5, 0, position.landed ? 0 : (time-moment.at)*7);
        }
        panel.hidden = !announcing;
        badge.hidden = !position || announcing;
        if (announcing && renderer && insetRefs) {
            const poses = refereeFormation(0,1); poses[0] = {x:0,z:0,velocity:0,travel:0};
            insetRefs.update({poses, penalty: penalty?.type, penaltyTime: announcementTime, active: true});
            renderer.render(insetScene,insetCamera);
        }
    };
    const dispose = () => {
        panel.remove(); badge.remove();
        insetScene?.traverse(object => {object.geometry?.dispose(); object.material?.dispose();});
        renderer?.dispose(); renderer?.forceContextLoss();
    };
    return {update,dispose};
}
