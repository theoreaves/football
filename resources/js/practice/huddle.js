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
        // Defense forms a loose circular discussion group, not a marching line.
        // The radial offset distinguishes individual players while keeping the
        // group between the hashes. Identical player order gives stable replay.
        const defenseAngle = (number / 11) * Math.PI * 2 + Math.PI / 11;
        const defenseRadius = 2.0 + (number % 3) * .27;
        const x = offenseQB ? center
            : attackSide ? center + Math.cos(angle) * 2.4
            : center + Math.cos(defenseAngle) * defenseRadius * homeDirection;
        const z = offenseQB ? 26.7
            : attackSide ? 26.7 + Math.sin(angle) * 2.4
            : 26.7 + Math.sin(defenseAngle) * defenseRadius;
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
