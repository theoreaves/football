import test from 'node:test';
import assert from 'node:assert/strict';
import {playCallVisible,presentationAmbience} from '../../resources/js/practice/presentation-ambience.js';
test('pending penalty and final views without a call form cannot crash frame visibility checks',()=>{
 for(const form of [null,undefined]){
  assert.equal(playCallVisible(form),false);
  assert.equal(presentationAmbience({visible:true,callingPlay:playCallVisible(form),flagOpen:true,final:false,recapOpen:false,inspectionOpen:false}),true);
  assert.equal(presentationAmbience({visible:true,callingPlay:playCallVisible(form),flagOpen:false,final:true,recapOpen:false,inspectionOpen:false}),false);
 }
 assert.equal(playCallVisible({hidden:true}),false);
 assert.equal(playCallVisible({hidden:false}),true);
});
