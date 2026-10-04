export function ballCarrier(frame, phase = 'play') {
    if (phase === 'huddle' || phase === 'liningup') return null;
    if (frame.ballHolder !== undefined) {
        return frame.ballHolder ? frame.players.find(player => player.team === frame.ballHolder.team && player.role === frame.ballHolder.role) || null : null;
    }
    // Saved replays from before possession tracks were added still show a marker.
    if (frame.ball.y < .65 || frame.ball.y > 1.5) return null;
    const preferredTeam = frame.event?.startsWith('Intercepted') ? 'defense' : 'offense';
    return frame.players.filter(player => Math.hypot(player.x - frame.ball.x, player.z - frame.ball.z) < 1.1)
        .sort((a, b) => Math.hypot(a.x - frame.ball.x, a.z - frame.ball.z) - Math.hypot(b.x - frame.ball.x, b.z - frame.ball.z) || (a.team === preferredTeam ? -1 : 1))[0] || null;
}

export function carrierLabel(player) {
    return `Ball: ${player.number != null ? `#${player.number} ` : ''}${player.name || player.role}`;
}
