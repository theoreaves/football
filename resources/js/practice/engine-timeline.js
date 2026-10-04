export function sampleTrack(path, elapsed) {
    if (elapsed <= path[0][0]) return { x: path[0][1], y: path[0][2], z: path[0][3] };
    for (let i = 1; i < path.length; i++) {
        const end = path[i], start = path[i - 1];
        if (elapsed <= end[0]) {
            const fraction = (elapsed - start[0]) / (end[0] - start[0]);
            return { x: start[1] + (end[1] - start[1]) * fraction, y: start[2] + (end[2] - start[2]) * fraction, z: start[3] + (end[3] - start[3]) * fraction };
        }
    }
    const end = path.at(-1);
    return { x: end[1], y: end[2], z: end[3] };
}

export function sampleEnginePlay(animation, elapsed) {
    const time = Math.max(0, Math.min(animation.duration, elapsed));
    return {
        time,
        players: animation.players.map(player => ({ ...player, ...sampleTrack(player.path, time) })),
        ballHolder: animation.ballHolders ? (() => {
            const holder = animation.ballHolders.filter(entry => entry[0] <= time).at(-1);
            return holder?.[1] ? { team: holder[1], role: holder[2] } : null;
        })() : undefined,
        ball: sampleTrack(animation.ball, time),
        event: animation.events.filter(event => event[0] <= time).at(-1)?.[1] || 'Ready',
    };
}
