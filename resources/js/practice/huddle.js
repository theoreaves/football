// Between plays, defenders wait on their side of the next line rather than huddling.
const defensiveSpots = {
    DE1: [.9, 21], DT1: [.9, 25], DT2: [.9, 28], DE2: [.9, 32],
    LB1: [3, 21], LB2: [3, 26.7], LB3: [3, 32], LB4: [3, 36],
    CB1: [3, 9], CB2: [3, 44], CB3: [4, 16], CB4: [4, 38],
    S1: [6, 21], S2: [6, 33], FS: [6, 21], SS: [6, 33],
};

export function sampleHuddle(finalFrame, nextLine, possession, progress, homeDirection = 1) {
    const fraction = Math.max(0, Math.min(1, progress));
    const blend = fraction * fraction * (3 - 2 * fraction);
    const slots = { home: 0, away: 0 };
    const players = finalFrame.players.map(player => {
        const side = player.side;
        const center = Math.max(5, Math.min(115, nextLine + (side === 'home' ? -8 : 8) * homeDirection));
        const number = slots[side]++;
        const attackSide = side === possession;
        const angle = number * 2 * Math.PI / 11;
        const offenseQB = attackSide && player.role === 'QB';
        const defenseDirection = possession === 'home' ? homeDirection : -homeDirection;
        // Unknown/legacy roles get distinct, stable waiting spots too.
        const [depth, width] = defensiveSpots[player.role] || [number < 4 ? .9 : number < 7 ? 3 : 6, 7 + number * 4];
        const x = attackSide ? (offenseQB ? center : center + Math.cos(angle) * 2.4)
            : Math.max(.5, Math.min(119.5, nextLine + defenseDirection * depth));
        const z = attackSide ? (offenseQB ? 26.7 : 26.7 + Math.sin(angle) * 2.4) : width;
        return { ...player, x: player.x + (x - player.x) * blend, z: player.z + (z - player.z) * blend,
            facingX: attackSide ? center : nextLine - defenseDirection * 8, facingZ: 26.7 };
    });
    // The officials spot the football at the next line of scrimmage.
    // It must not travel to (or follow a player into) the offensive huddle.
    // Keep this placement independent of the huddle movement interpolation.
    const spot = Math.max(1, Math.min(119, nextLine));
    return { ...finalFrame, players, ball: { x: spot, y: .25, z: 26.7 },
        event: fraction < 1 ? 'Offense huddling · Defense returning to the line' : 'Between plays · Choose formations and a play', huddle: fraction === 1 };
}

export function sampleBreakHuddle(formation, line, possession, progress, homeDirection = 1) {
    const fraction = Math.max(0, Math.min(1, progress));
    const blend = fraction * fraction * (3 - 2 * fraction);
    const huddle = sampleHuddle(formation, line, possession, 1, homeDirection);
    return { ...formation, players: formation.players.map((player, i) => ({ ...player,
        x: huddle.players[i].x + (player.x - huddle.players[i].x) * blend,
        z: huddle.players[i].z + (player.z - huddle.players[i].z) * blend })),
        ball: { ...formation.ball, y: .25 + (formation.ball.y - .25) * blend },
        event: fraction < 1 ? 'Breaking huddle · Moving into formation' : 'Set · Ready for the snap' };
}

// Return directly to the selected formation, matching identities rather than array order.
export function sampleNoHuddle(finalFrame, formation, progress) {
    // A smooth jog that retains velocity through the middle of the route,
    // with a gradual start/stop rather than a rapid teleport into formation.
    const t=Math.max(0,Math.min(1,progress)),blend=t*t*(3-2*t);
    const targets=new Map(formation.players.map(p=>[`${p.side}:${p.id ?? p.role}`,p]));
    return {...formation,players:finalFrame.players.map(p=>{
        const target=targets.get(`${p.side}:${p.id ?? p.role}`) || p;
        return {...p,x:p.x+(target.x-p.x)*blend,z:p.z+(target.z-p.z)*blend,facingX:target.x+1,facingZ:target.z};
    }),event:'Hurry-up · Returning straight to the line',huddle:t===1};
}
