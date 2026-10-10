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
        // Offense retains its circle, with QB at its center. The defense
        // loosely gathers between the hashes facing the offensive formation.
        // This arrangement is interpolated from the previous play, so nobody
        // teleports into a different formation at the end of the play.
        const offenseQB = attackSide && player.role === 'QB';
        const defensiveFront = !attackSide && /^(DE\d?|DT\d?|NT|EDGE\d?|DL\d?)$/.test(player.role ?? '');
        const defensiveBack = !attackSide && /^(CB\d?|FS|SS|S\d?|NB)$/.test(player.role ?? '');
        const defensiveLB = !attackSide && /^(LB\d?|MLB|LOLB|ROLB)$/.test(player.role ?? '');
        const x = offenseQB ? center
            : attackSide ? center + Math.cos(angle) * 2.4
            : center + (defensiveFront ? -2 : defensiveLB ? 0 : defensiveBack ? 3.0 : 1) * homeDirection;
        const z = offenseQB ? 26.7
            : attackSide ? 26.7 + Math.sin(angle) * 2.4
            : 26.7 + (number - 5) * (defensiveBack ? 2.6 : 1.7);
        return { ...player, x: player.x + (x - player.x) * blend, z: player.z + (z - player.z) * blend, facingX: center, facingZ: 26.7 };
    });
    // The officials spot the football at the next line of scrimmage.
    // It must not travel to (or follow a player into) the offensive huddle.
    // Keep this placement independent of the huddle movement interpolation.
    const spot = Math.max(1, Math.min(119, nextLine));
    return { ...finalFrame, players, ball: { x: spot, y: .25, z: 26.7 },
        event: fraction < 1 ? 'Teams returning to their huddles' : 'Between plays · Choose formations and a play', huddle: fraction === 1 };
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
