import {playerKey} from './unit-exchange.js';
export function turnoverCelebrants(animation, frame) {
    if (animation?.no_snap || !['interception','fumble'].includes(animation?.outcome)) return new Set();
    const recovery = [...(animation.ballHolders || [])].reverse().find(h => h[1] === 'defense' && h[2]);
    const role = recovery?.[2] || (animation.outcome === 'interception' ? 'CB1' : 'LB2');
    const lead = frame.players.find(p => p.team === 'defense' && p.role === role);
    if (!lead) return new Set();
    const nearby = frame.players.filter(p => p.team === 'defense' && p.role !== role)
        .sort((a,b)=>Math.hypot(a.x-lead.x,a.z-lead.z)-Math.hypot(b.x-lead.x,b.z-lead.z) || a.role.localeCompare(b.role)).slice(0,4);
    return new Set([lead,...nearby].map(playerKey));
}
