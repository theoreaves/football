export function sampleHuddle(finalFrame, nextLine, possession, progress) {
    const fraction = Math.max(0, Math.min(1, progress));
    const blend = fraction * fraction * (3 - 2 * fraction);
    const slots = { home: 0, away: 0 };
    const players = finalFrame.players.map(player => {
        const side = player.side;
        const center = Math.max(5, Math.min(115, nextLine + (side === 'home' ? -8 : 8)));
        const angle = slots[side]++ * 2 * Math.PI / 11;
        const x = center + Math.cos(angle) * 2.4, z = 26.7 + Math.sin(angle) * 2.4;
        return { ...player, x: player.x + (x - player.x) * blend, z: player.z + (z - player.z) * blend, facingX: center, facingZ: 26.7 };
    });
    const spot = nextLine + (possession === 'home' ? -1 : 1);
    return { ...finalFrame, players, ball: { x: finalFrame.ball.x + (spot - finalFrame.ball.x) * blend,
        y: finalFrame.ball.y + (.25 - finalFrame.ball.y) * blend, z: finalFrame.ball.z + (26.7 - finalFrame.ball.z) * blend },
        event: fraction < 1 ? 'Teams returning to their huddles' : 'Between plays · Choose formations and a play', huddle: fraction === 1 };
}

export function sampleBreakHuddle(formation, line, possession, progress) {
    const fraction = Math.max(0, Math.min(1, progress));
    const blend = fraction * fraction * (3 - 2 * fraction);
    const huddle = sampleHuddle(formation, line, possession, 1);
    return { ...formation, players: formation.players.map((player, i) => ({ ...player,
        x: huddle.players[i].x + (player.x - huddle.players[i].x) * blend,
        z: huddle.players[i].z + (player.z - huddle.players[i].z) * blend })),
        ball: { ...formation.ball, y: .25 + (formation.ball.y - .25) * blend },
        event: fraction < 1 ? 'Breaking huddle · Moving into formation' : 'Set · Ready for the snap' };
}
