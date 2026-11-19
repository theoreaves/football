import test from 'node:test';
import assert from 'node:assert/strict';
import {createFourthDownActions} from '../../resources/js/arcade/fourth-down.js';
const fourth={state:{down:4},flow:{phase:'offense'}};
for(const call of ['punt','field_goal'])test(`${call} remains callable after hidden Coach formation filters the dropdown`,()=>{
 const calls=['inside_run','punt','field_goal'],sent=[];
 const action=createFourthDownActions({blocked:()=>false,tempo:()=> 'hurry',send:(...args)=>sent.push(args)});
 action.update(fourth,calls);
 // mountPractice refreshFormation removes both kicks from the shotgun dropdown.
 calls.splice(0,calls.length,'inside_run');
 assert.deepEqual(action.offered,['punt','field_goal']);
 action.execute(call);assert.deepEqual(sent,[['play',{call,tempo:'hurry'}]]);
});
test('fourth-down actions preserve block gates and explain why no request was sent',()=>{
 const sent=[],notices=[];let blocked=true;
 const action=createFourthDownActions({blocked:()=>blocked,tempo:()=> 'normal',send:(...x)=>sent.push(x),notice:t=>notices.push(t)});
 action.update(fourth,['punt']);assert.equal(action.execute('punt'),false);assert.equal(sent.length,0);assert.equal(notices.length,1);
 blocked=false;action.execute('punt');assert.equal(sent.length,1);
});
test('new snapshots replace eligibility; unavailable calls, defense and other downs never submit',()=>{
 const sent=[],action=createFourthDownActions({blocked:()=>false,tempo:()=> 'normal',send:(...x)=>sent.push(x)});
 action.update(fourth,['punt','field_goal']);action.update(fourth,['punt']);action.execute('field_goal');
 action.update({...fourth,state:{down:3}},['punt']);action.execute('punt');
 action.update({...fourth,flow:{phase:'defense'}},['punt']);action.execute('punt');
 assert.equal(sent.length,0);
});

test('persistent integration captures canonical calls before mounting Coach formation filtering',async()=>{
 const {readFileSync}=await import('node:fs');
 const source=readFileSync(new URL('../../resources/js/arcade/persistent-exhibition.js',import.meta.url),'utf8');
 assert.ok(source.indexOf('fourthDown.update(next,')<source.indexOf('coach=mountPractice(phaseRoot'));
 assert.match(source,/onSpecial:call=>fourthDown\.execute\(call\)/);
 assert.match(source,/compact\.setSpecialCalls\(fourthDown\.offered\)/);
});
