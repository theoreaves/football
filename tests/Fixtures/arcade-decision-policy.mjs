// Test-only visible-state policy. It cannot inspect RNG, future states, flight.actualTarget,
// or replay a candidate throw. Routes are visible to the human player too.
import {actorSpeed, rating} from '../../resources/js/arcade/tuning.js';
import {getPassTrajectory} from '../../resources/js/arcade/passing.js';
import {FIELD_WIDTH} from '../../resources/js/arcade/simulation.js';

const clamp = (n, lo, hi) => Math.max(lo, Math.min(hi, n));

export function projectRoute(receiver, seconds, speedScale) {
    let x = receiver.x, z = receiver.z, remaining = actorSpeed(receiver, speedScale) * seconds;
    for (let i = receiver.route.index; i < receiver.route.points.length; i++) {
        const point = receiver.route.points[i], distance = Math.hypot(point.x - x, point.z - z);
        if (distance > remaining) return {x: x + (point.x - x) * remaining / distance, z: z + (point.z - z) * remaining / distance};
        x = point.x; z = point.z; remaining -= distance;
    }
    return {x, z};
}

export function chooseVisiblePass(state, distance = 10) {
    const qb = state.quarterback;
    const candidates = state.receivers.map(receiver => {
        let duration = .5, target;
        for (let i = 0; i < 4; i++) {
            target = projectRoute(receiver, duration, state.speedScale);
            duration = clamp(Math.hypot(target.x - qb.x, target.z - qb.z) / (21 + rating(qb, 'throwing') * .1), .4, 1.8);
        }
        target = {x: clamp(target.x, 1, 119), z: clamp(target.z, 1, FIELD_WIDTH - 1)};
        const range = Math.hypot(target.x - qb.x, target.z - qb.z);
        // Short, bounded extrapolation, not knowledge of the defender's next decision.
        const separation = Math.min(...state.defenders.map(d => Math.hypot(
            target.x - d.x - d.vx * Math.min(duration, .35), target.z - d.z - d.vz * Math.min(duration, .35))));
        const trajectory = getPassTrajectory(state, target, {samples: 16});
        const catchable = trajectory.map((point, index) => ({point, seconds: duration * index / 15, index}))
            .filter(({point, index}) => index >= 8 && point.y <= 2.55);
        const laneClearance = Math.min(...catchable.flatMap(({point, seconds}) => {
            return state.defenders.map(d => Math.hypot(point.x - d.x - d.vx * Math.min(seconds, .35),
                point.z - d.z - d.vz * Math.min(seconds, .35)));
        }));
        const gain = (target.x - state.startX) * state.direction;
        return {key: receiver.key, target, duration, separation, laneClearance, gain,
            score: Math.min(separation, laneClearance) + Math.min(Math.max(gain, 0), distance) * .12,
            valid: range >= 2 && range <= 42 && (target.x - qb.x) * state.direction >= 1};
    }).filter(c => c.valid).sort((a, b) => b.score - a.score || a.key.localeCompare(b.key));
    return candidates[0] ?? null;
}

export function createDecisionPolicy({distance = 10, seed = 1} = {}) {
    let decision = null;
    return {
        input(state) {
            if (state.controlledKey !== 'qb') return {x: state.direction, z: 0};
            if (state.ball.phase !== 'held') return {x: 0, z: 0};
            // Read every 0.2 s, after routes have developed for 0.8 s. The deadline
            // is below the defense's rush transition; no unlimited waiting for an oracle window.
            if (state.tick >= 48 && state.tick <= 144 && state.tick % 12 === 0) {
                const candidate = chooseVisiblePass(state, distance);
                if (candidate && (candidate.laneClearance >= 2 && (candidate.separation >= 2.5 || state.tick >= 144))) {
                    decision = {kind: 'pass', tick: state.tick, ...candidate};
                    return {x: 0, z: 0, pass: candidate.target};
                }
            }
            // If no safe legal window exists by the read deadline, take a visible scramble.
            if (state.tick > 144) {
                decision ??= {kind: 'scramble', tick: state.tick};
                return {x: state.direction, z: seed % 2 ? .25 : -.25};
            }
            return {x: 0, z: 0};
        },
        get decision() { return decision; },
    };
}
