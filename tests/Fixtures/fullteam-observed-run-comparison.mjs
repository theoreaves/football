// Optional diagnostic, not a balance assertion or an exact replay of user input.
// Run from the repository: node tests/Fixtures/fullteam-observed-run-comparison.mjs
// The sanitized recording contains positions, not keypresses. We approximate
// cardinal/diagonal movement from successive recorded RB positions, with a 40ms
// lookahead and 1yd/s dead zone. No juke or outcome is inferred or forced.
// Baseline: actual committed full-team runtime at 790a27a. Shared physics modules
// must remain identical to that commit so the comparison isolates this fix.
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {fileURLToPath, pathToFileURL} from 'node:url';
import {resolve} from 'node:path';
import * as current from '../../resources/js/arcade/full-team.js';

const root = fileURLToPath(new URL('../../', import.meta.url));
const baselineRef = '790a27a';
const fixture = JSON.parse(readFileSync(new URL('./fullteam-observed-run.json', import.meta.url), 'utf8'));
const gitFile = path => execFileSync('git', ['show', `${baselineRef}:${path}`], {cwd:root, encoding:'utf8'});
for (const name of ['simulation.js', 'passing.js', 'tuning.js', 'full-team-plays.js']) {
    const path = `resources/js/arcade/${name}`;
    if (gitFile(path) !== readFileSync(resolve(root, path), 'utf8')) {
        throw new Error(`Shared dependency ${path} differs from ${baselineRef}; comparison would not isolate the pursuit fix.`);
    }
}
const runtimeUrl = pathToFileURL(resolve(root, 'resources/js/arcade/full-team.js'));
const baselineSource = gitFile('resources/js/arcade/full-team.js').replace(
    /from\s+(['"])(\.[^'"]+)\1/g,
    (_, quote, path) => `from ${quote}${new URL(path, runtimeUrl).href}${quote}`,
);
const baseline = await import(`data:text/javascript;base64,${Buffer.from(baselineSource).toString('base64')}`);

function approximateInput(time) {
    const path = fixture.runnerPath;
    let index = path.findIndex(point => point[0] >= time + .04);
    if (index < 1) index = path.length - 1;
    const previous = path[index - 1], next = path[index], duration = next[0] - previous[0];
    const vx = duration ? (next[1] - previous[1]) / duration : 0;
    const vz = duration ? (next[2] - previous[2]) / duration : 0;
    return {x:Math.abs(vx) < 1 ? 0 : Math.sign(vx), z:Math.abs(vz) < 1 ? 0 : Math.sign(vz)};
}
function compare(runtime) {
    const state = runtime.startFullTeamPlay(runtime.createFullTeamPlay(structuredClone(fixture.setup)));
    for (let tick = 0; tick < 1200 && !state.result; tick++) {
        runtime.stepFullTeamPlay(state, approximateInput(state.elapsed));
    }
    return {result:state.result, tackler:state.events.find(event => event.type === 'tackle')?.targetKey ?? null,
        blocks:state.events.filter(event => event.type === 'block').length};
}
console.log(JSON.stringify({
    caveat:'Approximate movement inferred from sampled positions, not exact original input. Historical result is unchanged. No expected yardage or balance threshold is asserted.',
    baselineRef,
    actualObservedResult:fixture.observedResult,
    approximateBaseline:compare(baseline),
    approximateCurrent:compare(current),
}, null, 2));
