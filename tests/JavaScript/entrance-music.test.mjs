import test from 'node:test';
import assert from 'node:assert/strict';
import {entranceMusic} from '../../resources/js/practice/entrance-music.js';
test('entrance track starts exactly once at the home cue and never during visiting entry or hidden tabs',()=>{
    let starts=0;const cue=entranceMusic({startEntranceMusic(){starts++;},finishEntranceMusic(){return Promise.resolve();}},35);
    cue.tick(0);cue.tick(34.99);assert.equal(starts,0);
    cue.tick(35,false);assert.equal(starts,0);
    cue.tick(35);cue.tick(44);cue.tick(78);assert.equal(starts,1);
});
test('skip before home prevents late music and completion waits for the fade',async()=>{
    let starts=0,finishes=0,resolveFade;
    const cue=entranceMusic({startEntranceMusic(){starts++;},finishEntranceMusic(seconds){finishes++;assert.equal(seconds,.6);return new Promise(resolve=>resolveFade=resolve);}},35);
    let settled=false;const result=cue.finish().then(()=>settled=true);
    cue.tick(80);assert.equal(starts,0);assert.equal(settled,false);
    resolveFade();await result;assert.equal(settled,true);
    await cue.finish();assert.equal(finishes,1);
});
