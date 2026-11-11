// Full HTTP-flow acceptance fixture: actual fixed-tick recording, visible-state inputs only.
import readline from 'node:readline';
import {createDecisionPolicy} from './arcade-decision-policy.mjs';
import {createDrill, startDrill, stepDrill} from '../../resources/js/arcade/simulation.js';
import {createPassingDrill, stepPassingDrill} from '../../resources/js/arcade/passing.js';
import {createDrillRecording} from '../../resources/js/arcade/recording.js';
for await (const line of readline.createInterface({input: process.stdin})) {
    const {setup, distance = 10} = JSON.parse(line);
    const passing = setup.kind === 'pass';
    const state = passing ? createPassingDrill(setup) : createDrill(setup);
    const recorder = createDrillRecording(state);
    const policy = createDecisionPolicy({distance, seed: setup.seed});
    startDrill(state); recorder.capture(state);
    for (let tick = 0; tick < 1800 && state.status === 'running'; tick++) {
        const input = passing ? policy.input(state) : {
            x: state.direction, z: tick < 35 ? (setup.seed % 3 - 1) * .25 : 0,
            juke: tick === 38 ? (setup.seed % 2 ? -1 : 1) : 0,
        };
        (passing ? stepPassingDrill : stepDrill)(state, input); recorder.capture(state);
    }
    if (!state.result || state.result.reason === 'timeout') throw new Error('The visible input fixture did not finish a football play');
    console.log(JSON.stringify({recording: recorder.export(), decision: policy.decision}));
}
