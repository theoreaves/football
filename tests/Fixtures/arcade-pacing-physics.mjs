// Test-only headless input profiles, not recordings of human play. One request per snap.
import readline from 'node:readline';
import {createDecisionPolicy} from './arcade-decision-policy.mjs';
import {createHash} from 'node:crypto';
import {createDrill, startDrill, stepDrill} from '../../resources/js/arcade/simulation.js';
import {createPassingDrill, stepPassingDrill} from '../../resources/js/arcade/passing.js';
import {createDrillRecording} from '../../resources/js/arcade/recording.js';
for await (const line of readline.createInterface({input: process.stdin})) {
    const p = JSON.parse(line), {direction, startX, seed, players, defense, pass} = p;
    const defenders = p.profile === 'decisions'
        ? (pass ? [defense.CB1, defense.CB2] : [defense.LB1, defense.CB1]) : [defense.LB1, defense.S1];
    const setup = {direction, seed, speedScale: .925, defenders, defenderCount: 2,
        exhibition: {startX, goalX: direction === 1 ? 110 : 10, rearX: direction === 1 ? 10 : 110, firstDownX: startX + direction * p.distance}};
    const state = startDrill(pass ? createPassingDrill({...setup, routeSet: p.routeSet ?? 'slants', quarterback: players.QB, receivers: [players.WR1, players.WR2]})
        : createDrill({...setup, runner: players.RB}));
    const recorder = createDrillRecording(state);
    const policy = createDecisionPolicy({distance: p.distance, seed});
    for (let tick = 0; tick < 1800 && state.status === 'running'; tick++) {
        let input = {x: pass && state.controlledKey === 'qb' ? 0 : direction,
            z: !pass && tick < 35 ? (seed % 3 - 1) * .25 : 0, juke: !pass && tick === 38 ? (seed % 2 ? -1 : 1) : 0};
        if (pass && p.profile === 'decisions') input = policy.input(state);
        if (pass && p.profile !== 'decisions' && tick === (p.profile === 'lead' ? 30 : 55)) {
            let target = state.receivers[seed % state.receivers.length];
            if (p.profile === 'lead') {
                const separation = r => Math.min(...state.defenders.map(d => Math.hypot(r.x + r.vx * .7 - d.x, r.z + r.vz * .7 - d.z)));
                target = [...state.receivers].sort((a, b) => separation(b) - separation(a))[0];
            }
            input.pass = p.profile === 'lead'
                ? {x: Math.max(10, Math.min(110, target.x + target.vx * .7)), z: Math.max(1, Math.min(52, target.z + target.vz * .7))}
                : {x: Math.max(10, Math.min(110, target.x + direction * 4)), z: target.z};
        }
        (pass ? stepPassingDrill : stepDrill)(state, input); recorder.capture(state);
    }
    if (!state.result) throw new Error('Scripted input exceeded bounded live ticks');
    console.log(JSON.stringify({result: state.result, ticks: state.tick, passOutcome: state.passOutcome ?? null,
        attemptedPass: state.events.some(e => e.type === 'throw'), decision: policy.decision, routeSet: state.routeSet ?? null, catchAttempts: state.events.filter(e => e.type === 'catch_attempt').map(e => ({actorKey: e.actorKey, success: e.success, chance: e.chance})),
        runnerX: state.result.reason === 'interception' ? state.ball.x : state.runner.x, recordingHash: createHash('sha256').update(JSON.stringify(recorder.export())).digest('hex')}));
}
