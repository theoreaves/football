import test from 'node:test';
import assert from 'node:assert/strict';
import {acceptedPlaybackUrl} from '../../resources/js/arcade/presentation-playback.js';
const snapshot={latest_play:{number:8},state:{version:8},flow:{state_hash:'hash8'},urls:{playback_base:'/exhibitions/3/arcade/playback'}};
const result={receipt:{watch:true},play:{number:8},state:{version:8},flow:{state_hash:'hash8'}};
test('watched playback binds the accepted command number, not an unrelated latest snapshot',()=>{
 assert.equal(acceptedPlaybackUrl(snapshot,result),'/exhibitions/3/arcade/playback/8');
 assert.equal(acceptedPlaybackUrl({...snapshot,latest_play:{number:9},state:{version:9}},result),null);
 assert.equal(acceptedPlaybackUrl({...snapshot,flow:{state_hash:'changed-by-other-tab'}},result),null);
 assert.equal(acceptedPlaybackUrl(snapshot,{...result,receipt:{watch:false}}),null);
 assert.equal(acceptedPlaybackUrl(snapshot,{receipt:{watch:true}}),null);
});
