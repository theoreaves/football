export function samplePreSnapMotion(frame, animation, progress) {
    const clamped = Math.max(0, Math.min(1, progress));
    const eased = clamped * clamped * (3 - 2 * clamped);
    return { ...frame, players: frame.players.map(player => {
        const prefix = player.team === 'offense' && player.role === animation?.motion ? 'motion'
            : player.team === 'defense' && player.role === animation?.motion_defender ? 'motion_defender' : null;
        if (!prefix || animation[`${prefix}_start`] == null || animation[`${prefix}_end`] == null) return player;
        const start = animation[`${prefix}_start`];
        return { ...player, z: start + (animation[`${prefix}_end`] - start) * eased };
    }) };
}
