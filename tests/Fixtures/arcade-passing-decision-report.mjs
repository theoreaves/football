// Reproduce with: node tests/Fixtures/arcade-passing-decision-report.mjs > /tmp/decision-report.json
// No success-rate assertions: this is observed sensitivity, not a balance target.
import {spawnSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
const make = value => ({ratings: Object.fromEntries(['speed','acceleration','awareness','throwing','catching','coverage','strength','tackling'].map(k => [k, value]))});
const requests = [], groups = [];
for (const profile of ['stress','lead','decisions']) for (const routeSet of ['slants','comeback']) {
    for (const [offense, defense] of [[40,40],[70,70],[95,95],[95,40],[40,95]]) for (const direction of [1,-1]) {
        const group = {profile,routeSet,offense,defense,direction,n:50,outcomes:{},decisions:{},catchAttempts:0,successfulCatches:0,meanDecisionSeconds:0};
        groups.push(group);
        for (let i=0;i<50;i++) requests.push({group,request:{profile,routeSet,direction,startX:direction===1?35:85,distance:10,seed:42+i*7919,
            players:{QB:make(offense),WR1:make(offense),WR2:make(offense),RB:make(offense)},defense:{LB1:make(defense),S1:make(defense),CB1:make(defense),CB2:make(defense)},pass:true}});
    }
}
const run = spawnSync(process.execPath,['tests/Fixtures/arcade-pacing-physics.mjs'],{input:requests.map(r=>JSON.stringify(r.request)).join('\n')+'\n',encoding:'utf8',maxBuffer:16*1024*1024});
if(run.status!==0) throw new Error(run.stderr);
const lines=run.stdout.trim().split('\n');
if(lines.length!==requests.length) throw new Error('Missing physics responses');
for(let i=0;i<lines.length;i++) {
    const r=JSON.parse(lines[i]),g=requests[i].group,o=r.passOutcome??r.result.reason;
    g.outcomes[o]=(g.outcomes[o]??0)+1;
    const kind=r.decision?.kind??'fixed_throw'; g.decisions[kind]=(g.decisions[kind]??0)+1;
    g.catchAttempts+=r.catchAttempts.filter(e=>e.actorKey.startsWith('receiver:')).length;
    g.successfulCatches+=r.catchAttempts.filter(e=>e.actorKey.startsWith('receiver:')&&e.success).length;
    g.meanDecisionSeconds+=(r.decision?.tick??(g.profile==='lead'?30:55))/60/g.n;
}
for(const g of groups) g.meanDecisionSeconds=Number(g.meanDecisionSeconds.toFixed(3));
const sources=['tests/Fixtures/arcade-decision-policy.mjs','tests/Fixtures/arcade-pacing-physics.mjs','tests/Fixtures/arcade-passing-decision-report.mjs',
    'resources/js/arcade/passing.js','resources/js/arcade/simulation.js','resources/js/arcade/tuning.js'];
console.log(JSON.stringify({description:'3000 independent passing snaps; visible-state policy vs retained stress profiles. All at home25/equivalent reverse LOS; two defenders, speed .925; no human balance claim. Slants match current exhibition routes; comeback is drill-only sensitivity, not an official exhibition choice. Seeds identify independent physics snaps, not an official multi-play session.',
    seeds:'42 + i * 7919, i=0..49',policy:'Route-aware nominal flight-time projection, current defenders extrapolated at most .35sec, reads every .2sec starting .8sec, separation threshold2.5yd, descending-lane clearance2yd, read deadline2.4sec then scramble. No hidden RNG or future-state queries.',
    sourceHashes:Object.fromEntries(sources.map(p=>[p,createHash('sha256').update(readFileSync(p)).digest('hex')])),groups},null,2));
