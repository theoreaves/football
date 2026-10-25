// Presentation-only tackle assistance. Deterministic and derived from the
// recorded frame: never changes animation tracks, ball position, stats, or RNG.
const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
const ease = v => { const t = clamp(v, 0, 1); return t * t * (3 - 2 * t); };

export function tackleAssistPose(frame, animation, elapsed, player) {
    if (!animation || animation.contact_at == null || !animation.tackle_style
        || elapsed < animation.contact_at || player.team !== (animation.contact_tackler_team || 'defense')
        || player.role === animation.tackler_role) return null;
    const carrier = frame.players.find(p => p.team === (animation.contact_carrier_team || 'offense') && p.role === animation.carrier);
    if (!carrier) return null;
    const defenders = frame.players.filter(p => p.visible !== false
        && p.team === (animation.contact_tackler_team || 'defense')
        && p.role !== animation.tackler_role)
        .map(p => ({ p, distance: Math.hypot(p.x - carrier.x, p.z - carrier.z) }))
        .filter(x => x.distance <= 3.5)
        .sort((a, b) => a.distance - b.distance || String(a.p.role).localeCompare(String(b.p.role)));
    // Some tackles stay solo; on others, one or two helpers arrive in sequence.
    const variant = Math.abs(Number(animation.animation_variant) || 0);
    const count = variant % 4 === 0 ? 0 : variant % 3 === 0 ? 2 : 1;
    const rank = defenders.findIndex(x => x.p.role === player.role);
    if (rank < 0 || rank >= count) return null;
    const delay = .10 + rank * .20 + (variant % 3) * .025;
    const t = elapsed - animation.contact_at - delay;
    if (t <= 0) return null;
    const blend = ease(t / .22) * (1 - ease((t - .50) / .20));
    return {
        blend,
        towardX: carrier.x - player.x,
        towardZ: carrier.z - player.z,
        // Never pull support defenders unnaturally into the ball carrier.
        displacement: Math.min(.12, defenders[rank].distance * .05) * blend,
    };
}
