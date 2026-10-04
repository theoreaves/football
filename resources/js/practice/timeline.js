export const DURATION = 6;
const offense = [
    ['QB', 35, 26.7], ['C', 39, 26.7], ['LG', 39, 24.5], ['RG', 39, 28.9],
    ['LT', 39, 22.3], ['RT', 39, 31.1], ['RB', 33, 29], ['TE', 39, 34],
    ['WR1', 39, 9], ['WR2', 39, 43], ['WR3', 37, 16],
];
const defense = [
    ['DE1', 41, 22], ['DT1', 41, 25], ['DT2', 41, 28], ['DE2', 41, 31],
    ['LB1', 45, 22], ['LB2', 45, 27], ['LB3', 45, 33],
    ['CB1', 43, 9], ['CB2', 43, 43], ['S1', 51, 20], ['S2', 52, 34],
];

export function interpolate(points, time) {
    if (time <= points[0][0]) return { x: points[0][1], z: points[0][2] };
    for (let i = 1; i < points.length; i++) {
        const [end, x, z] = points[i];
        const [start, sx, sz] = points[i - 1];
        if (time <= end) {
            const fraction = (time - start) / (end - start);
            return { x: sx + (x - sx) * fraction, z: sz + (z - sz) * fraction };
        }
    }
    return { x: points.at(-1)[1], z: points.at(-1)[2] };
}

export function samplePlay(type, elapsed) {
    const pass = type === 'pass';
    const time = Math.max(0, Math.min(DURATION, elapsed));
    const carrier = pass ? 'WR1' : 'RB';
    const routes = {};
    for (const [role, x, z] of offense) {
        let path;
        if (role === 'QB') path = [[0, x, z], [1, 33, 26.7], [3, 32, 26.7], [6, 33, 27]];
        else if (role === 'RB') path = pass
            ? [[0, x, z], [1, 36, 28], [6, 40, 29]]
            : [[0, x, z], [1, 34, 27], [2, 39, 27], [4, 49, 28], [5.3, 53, 27], [6, 53, 27]];
        else if (role === 'WR1') path = [[0, x, z], [2, 48, 9], [3.8, 57, 17], [5.3, 64, 20], [6, 64, 20]];
        else if (role.startsWith('WR') || role === 'TE') path = [[0, x, z], [3, x + 14, z], [6, x + 25, z + (z > 26 ? -3 : 3)]];
        else path = [[0, x, z], [1.5, x + 1, z], [6, x + (pass ? 0 : 6), z]];
        routes[role] = path;
    }
    const offensePlayers = offense.map(([role]) => ({ team: 'offense', role, ...interpolate(routes[role], time) }));
    const defensePlayers = defense.map(([role, x, z], i) => {
        let path;
        if (pass && role === 'CB1') path = [[0, x, z], [2, 49, 10], [3.8, 56, 16], [5.3, 64, 20], [6, 64, 20]];
        else if (pass && role === 'S1') path = [[0, x, z], [3.8, 59, 22], [5.3, 64.8, 20.5], [6, 64.8, 20.5]];
        else if (!pass && role === 'LB2') path = [[0, x, z], [2, 44, 26], [4, 50, 26], [5.3, 53, 27], [6, 53, 27]];
        else if (i < 4) path = [[0, x, z], [1.5, 40.8, z], [6, pass ? 36 : 46, z]];
        else path = [[0, x, z], [3, x + (pass ? 8 : 0), z], [6, pass ? x + 15 : 53 + i * 0.25, pass ? z : 27 + (i - 5)]];
        return { team: 'defense', role, ...interpolate(path, time) };
    });
    const qb = offensePlayers.find(p => p.role === 'QB');
    const runner = offensePlayers.find(p => p.role === carrier);
    let ball;
    if (time < 0.35) {
        const fraction = time / 0.35;
        ball = { x: 39 + (qb.x - 39) * fraction, z: 26.7, y: 1 };
    } else if (pass && time >= 2.2 && time < 3.8) {
        const from = interpolate(routes.QB, 2.2);
        const to = interpolate(routes.WR1, 3.8);
        const fraction = (time - 2.2) / 1.6;
        ball = { x: from.x + (to.x - from.x) * fraction, z: from.z + (to.z - from.z) * fraction, y: 1 + 6 * Math.sin(Math.PI * fraction) };
    } else if (!pass && time >= 0.6 && time < 1) {
        const fraction = (time - 0.6) / 0.4;
        ball = { x: qb.x + (runner.x - qb.x) * fraction, z: qb.z + (runner.z - qb.z) * fraction, y: 1 };
    } else {
        const holder = time < (pass ? 3.8 : 1) ? qb : runner;
        ball = { x: holder.x, z: holder.z, y: 1 };
    }
    const event = time < 0.35 ? 'Snap' : time >= 5.3 ? 'Tackle · play complete'
        : pass ? (time < 2.2 ? 'Dropback' : time < 3.8 ? 'Pass in flight' : 'Catch · run after catch')
            : time < 1 ? 'Handoff' : 'Run through the right-side gap';
    return { time, ball, players: [...offensePlayers, ...defensePlayers], event, carrier, ballHolder: time === 0 ? { team: 'offense', role: 'C' } : time < .35 || (pass && time >= 2.2 && time < 3.8) || (!pass && time >= .6 && time < 1) ? null : { team: 'offense', role: time < (pass ? 3.8 : 1) ? 'QB' : carrier } };
}
