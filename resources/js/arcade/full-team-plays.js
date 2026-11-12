// Versioned live-play assignments, not Coach outcome definitions or new player ratings.
export const FULL_TEAM_VERSION = 'arcade-fullteam-1';
export const FULL_TEAM_CALLS = Object.freeze(['inside_run', 'outside_run', 'short_pass', 'medium_pass', 'deep_pass']);
export const OFFENSIVE_ROLES = Object.freeze(['QB', 'RB', 'WR1', 'WR2', 'WR3', 'TE', 'C', 'LG', 'RG', 'LT', 'RT']);
export const DEFENSIVE_ROLES = Object.freeze(['DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3', 'CB1', 'CB2', 'S1', 'S2']);
export const ELIGIBLE_ROLES = Object.freeze(['RB', 'WR1', 'WR2', 'WR3', 'TE']);

// Coordinates are yards ahead of scrimmage and absolute lateral yards. Every pass
// includes a 3–6 yard outlet; routes settle at their final point rather than drift.
const ROUTES = {
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
