// Versioned live-play assignments, not Coach outcome definitions or new player ratings.
export const FULL_TEAM_VERSION = 'arcade-fullteam-1';
export const FULL_TEAM_CATALOG_VERSION = 'arcade-fullteam-2';
// Historical calls remain readable for outstanding reservations and recordings.
export const FULL_TEAM_CALLS = Object.freeze(['inside_run', 'outside_run', 'short_pass', 'medium_pass', 'deep_pass']);
export const OFFENSIVE_ROLES = Object.freeze(['QB', 'RB', 'WR1', 'WR2', 'WR3', 'TE', 'C', 'LG', 'RG', 'LT', 'RT']);
export const DEFENSIVE_ROLES = Object.freeze(['DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3', 'CB1', 'CB2', 'S1', 'S2']);
export const ELIGIBLE_ROLES = Object.freeze(['RB', 'WR1', 'WR2', 'WR3', 'TE']);

// Coordinates are yards ahead of scrimmage and absolute lateral yards. Every pass
// includes a 3–6 yard outlet; routes settle at their final point rather than drift.
const ROUTES = {
    quick_slants: { WR1: [[3,9],[7,19]], WR2: [[3,43],[7,33]], WR3: [[2,16],[5,26]], TE: [[4,34],[4,38]], RB: [[-2,25],[2,23]] },
    te_stick: { WR1: [[15,9]], WR2: [[12,43]], WR3: [[3,16],[3,8]], TE: [[5,34],[5,30]], RB: [[-2,36],[2,40]] },
    hb_swing: { WR1: [[12,9],[16,14]], WR2: [[12,43],[16,38]], WR3: [[6,16],[6,24]], TE: [[7,34],[7,29]], RB: [[-4,36],[0,43],[4,45]] },
    hb_screen: { WR1: [[16,9]], WR2: [[18,43]], WR3: [[9,16],[12,22]], TE: [[8,34],[12,40]], RB: [[-5,29],[-3,38],[-1,40]] },
    mesh_crossers: { WR1: [[5,9],[6,38]], WR2: [[6,43],[5,14]], WR3: [[12,16]], TE: [[9,34],[9,29]], RB: [[-2,35],[3,41]] },
    curl_flat: { WR1: [[12,9],[9,11]], WR2: [[12,43],[9,41]], WR3: [[4,16],[4,7]], TE: [[5,34],[5,43]], RB: [[-2,25],[3,23]] },
    play_action_boot: { WR1: [[10,9],[13,32]], WR2: [[16,43],[19,35]], WR3: [[6,16],[8,35]], TE: [[2,34],[3,43]], RB: [[-4,29],[2,22],[5,20]] },
    four_verticals: { WR1: [[12,9],[32,9]], WR2: [[12,43],[32,43]], WR3: [[10,16],[30,20]], TE: [[10,34],[30,33]], RB: [[-2,35],[4,39]] },
    post_corner: { WR1: [[12,9],[28,25]], WR2: [[12,43],[25,49]], WR3: [[8,16],[10,26]], TE: [[7,34],[7,40]], RB: [[-2,25],[3,22]] },
    short_pass: { WR1: [[4, 9], [4, 13]], WR2: [[5, 43]], WR3: [[3, 19], [5, 25]], TE: [[4, 34]], RB: [[-2, 35], [3, 39]] },
    medium_pass: { WR1: [[10, 9], [10, 17]], WR2: [[12, 43], [12, 35]], WR3: [[6, 16], [9, 24]], TE: [[5, 34]], RB: [[-2, 35], [3, 39]] },
    deep_pass: { WR1: [[12, 9], [25, 13]], WR2: [[14, 43], [27, 39]], WR3: [[10, 16], [16, 24]], TE: [[6, 34]], RB: [[-2, 35], [3, 39]] },
};

export function fullTeamRoute(call, role, startX, direction) {
    return (ROUTES[call]?.[role] ?? []).map(([yards, z]) => ({ x: Math.max(0.5, Math.min(119.5, startX + direction * yards)), z }));
}

export function defensiveAssignment(role) {
    if (/^(DE|DT)/.test(role)) return 'rush';
    if (/^LB/.test(role)) return 'short_zone';
    if (/^CB/.test(role)) return 'man';
    return 'deep_zone';
}

const entries = [
    ['inside_run', 'Inside Run', 'Run', 'run', 'handoff', 'Immediate mesh and interior blocking; RB reads the live lane.'],
    ['outside_run', 'Outside Run', 'Run', 'run', 'handoff', 'Wide mesh and edge leverage; RB reads pursuit.'],
    ['hb_draw', 'HB Draw', 'Run', 'run', 'draw', 'Pass-set protection, delayed physical handoff, then an interior run.'],
    ['qb_sneak', 'QB Sneak', 'Run', 'run', 'sneak', 'Under-center QB carry behind interior blocking. No handoff.'],
    ['quick_slants', 'Quick Slants', 'Short Pass', 'pass', 'pass', 'Quick inward breaks with a short flat outlet.'],
    ['te_stick', 'TE Stick / Outlet', 'Short Pass', 'pass', 'pass', 'TE settles inside; wide clear-outs and short outlets.'],
    ['hb_swing', 'HB Swing', 'Short Pass', 'pass', 'pass', 'RB widens into the flat beneath downfield clear-outs.'],
    ['hb_screen', 'HB Screen', 'Short Pass', 'pass', 'screen', 'Delayed RB outlet with guards releasing to lead the screen.'],
    ['mesh_crossers', 'Mesh Crossers', 'Medium Pass', 'pass', 'pass', 'Opposing shallow crossers with a middle settling route.'],
    ['curl_flat', 'Curl / Flat', 'Medium Pass', 'pass', 'pass', 'Outside curls return toward the QB above flat outlets.'],
    ['play_action_boot', 'Play-Action Boot', 'Medium Pass', 'pass', 'play_action', 'Physical fake mesh, then QB rollout with crossing and flat routes.'],
    ['four_verticals', 'Four Verticals', 'Deep Pass', 'pass', 'pass', 'Four distinct vertical lanes with a short RB outlet.'],
    ['post_corner', 'Post / Corner', 'Deep Pass', 'pass', 'pass', 'Deep post and corner breaks above intermediate outlets.'],
];
export const FULL_TEAM_PLAYBOOK = Object.freeze(entries.map(([id,label,group,kind,mechanic,description]) => Object.freeze({id,label,group,kind,mechanic,description})));
export const ALL_FULL_TEAM_CALLS = Object.freeze([...new Set([...FULL_TEAM_CALLS,...FULL_TEAM_PLAYBOOK.map(play=>play.id)])]);
export function fullTeamPlay(call) {
    return FULL_TEAM_PLAYBOOK.find(play=>play.id===call) ?? (FULL_TEAM_CALLS.includes(call) ? {id:call,label:call.replaceAll('_',' '),kind:'pass',mechanic:'pass'} : null);
}
const FORMATION = { QB: [-5,26.7], C: [-1,26.7], LG: [-1,24.5], RG: [-1,28.9], LT: [-1,22.3], RT: [-1,31.1], RB: [-7,29], TE: [-1,34], WR1: [-1,9], WR2: [-1,43], WR3: [-3,16] };
export function fullTeamFormation(call) {
    return Object.fromEntries(Object.entries(FORMATION).map(([role,p])=>[role, role==='QB' && call==='qb_sneak' ? [-1.8,26.7] : [...p]]));
}
export function fullTeamMesh(call, qb, rb) {
    return {x:(qb.x+rb.x)/2,z:(qb.z+rb.z)/2+(call==='outside_run'?1.5:0)};
}
export function fullTeamBlocking(call) {
    const result={LT:'DE1',LG:'DT1',C:'DT2',RG:'LB2',RT:'DE2',TE:'LB3',WR1:'CB1',WR2:'CB2',WR3:'LB1',QB:'LB2'};
    if(call==='outside_run') { result.C='LB2'; result.RG='DT2'; }
    return result;
}
export function fullTeamDiagram(call) {
    const formation=fullTeamFormation(call), play=fullTeamPlay(call);
    const routes=Object.fromEntries(ELIGIBLE_ROLES.map(role=>[role,fullTeamRoute(call,role,50,1).map(p=>[p.x-50,p.z])]));
    const mesh=play?.kind==='run' && play.mechanic!=='sneak' || play?.mechanic==='play_action'
        ? fullTeamMesh(call,{x:formation.QB[0],z:formation.QB[1]},{x:formation.RB[0],z:formation.RB[1]}) : null;
    const defense={DE1:[1,22],DT1:[1,25],DT2:[1,28],DE2:[1,31],LB1:[5,22],LB2:[5,27],LB3:[5,33],CB1:[3,9],CB2:[3,43]};
    const blocking=Object.fromEntries(Object.entries(fullTeamBlocking(call)).filter(([role])=>play?.kind==='run'||['LT','LG','C','RG','RT'].includes(role)).map(([role,target])=>[role,[formation[role],defense[target]]]));
    return {formation,routes,mesh:mesh ? [formation.QB,[mesh.x,mesh.z],formation.RB] : [],blocking,lane:play?.kind==='run' ? [play.mechanic==='sneak'?formation.QB:[mesh.x,mesh.z],[3,call==='outside_run'?33:26.7]]:[]};
}
