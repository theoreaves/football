// Extend presentation tracks without rewriting saved animations or play outcomes.
export function goalKickBallTrack(animation) {
    const path = animation.ball;
    if (!['field_goal', 'extra_point'].includes(animation.call)
        || !['field_goal_good', 'field_goal_missed', 'extra_point_good', 'extra_point_missed'].includes(animation.outcome)) return path;
    const goal = path?.[3];
    if (!goal || ![0, 120].includes(goal[1]) || goal[0] >= animation.duration) return path;
    const direction = goal[1] === 120 ? 1 : -1;
    const remaining = animation.duration - goal[0];
    return [...path.slice(0, 4),
        [goal[0] + remaining * .28, goal[1] + direction * 5, Math.max(.25, goal[2] * .7), goal[3]],
        [goal[0] + remaining * .72, goal[1] + direction * 11, .25, goal[3]],
        [animation.duration, goal[1] + direction * 12, .25, goal[3]]];
}
