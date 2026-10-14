import {sampleTrack} from './engine-timeline.js';

const special = ['kickoff','onside_kick','punt','field_goal','extra_point','spike','kneel','clock_event'];
const validPath = player => Array.isArray(player.path) && player.path.length > 0
    && player.path.every(point => Array.isArray(point) && point.length >= 4 && point.every(Number.isFinite));

// Audio-only inspection of the recorded paths; never changes animation data.
export function contactSoundCues(animation) {
    if (!animation || animation.no_snap || special.includes(animation.call)) return [];
    const players = (animation.players ?? []).filter(validPath);
    const duration = Number.isFinite(animation.duration) ? animation.duration : 6;
    const end = Math.min(duration, animation.result_at ?? animation.contact_at ?? duration);
    const cues = [];
    const offense = players.filter(p => p.team === 'offense' && ['C','LG','RG','LT','RT'].includes(p.role));
    const defense = players.filter(p => p.team === 'defense' && /^(DE\d?|DT\d?|NT|EDGE\d?|DL\d?)$/.test(p.role));
    const usedOffense = new Set(), usedDefense = new Set();
    let lastHit = -1;
    // At most three audible engagements, spread apart; no continuous hit loop.
    for (let step = 5; step <= Math.floor(Math.min(2.8, end - .5) * 20); step++) {
        const time = step / 20;
        if (time - lastHit < .18) continue;
        let closest;
        for (const blocker of offense) for (const rusher of defense) {
            if (usedOffense.has(blocker) || usedDefense.has(rusher)) continue;
            const a = sampleTrack(blocker.path,time), b = sampleTrack(rusher.path,time);
            const distance = Math.hypot(a.x-b.x,a.z-b.z);
            if (distance <= 1.6 && (!closest || distance < closest.distance)) closest = {blocker,rusher,distance};
        }
        if (!closest) continue;
        cues.push({time,type:'pad',mood:null});
        usedOffense.add(closest.blocker);usedDefense.add(closest.rusher);lastHit=time;
        if (cues.length === 3) break;
    }
    const at = animation.contact_at;
    const carrierTeam = animation.contact_carrier_team || 'offense';
    const tacklerTeam = animation.contact_tackler_team || 'defense';
    const hasPair = players.some(p => p.team === carrierTeam && p.role === animation.carrier)
        && players.some(p => p.team === tacklerTeam && p.role === animation.tackler_role);
    if (Number.isFinite(at) && at >= 0 && at <= duration && animation.tackle_style && hasPair) {
        cues.push({time:at,type:'tackle',mood:animation.tackle_style});
        const fall = animation.tackle_style === 'wrap' ? .65 : animation.tackle_style === 'lunge' ? .38 : .55;
        // Near the end of the carrier's visible fall (matching field.js).
        const landed = at + .08 + fall * .9;
        if (landed <= duration) cues.push({time:landed,type:'landing',mood:null});
    }
    return cues.sort((a,b) => a.time-b.time);
}
