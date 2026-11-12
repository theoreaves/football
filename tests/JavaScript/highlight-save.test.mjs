import test from 'node:test';
import assert from 'node:assert/strict';
import {saveExhibitionHighlight, highlightRequiresPresentation} from '../../resources/js/arcade/highlight-save.js';
const base={url:'/exhibitions/3/highlights',gameUrl:'/exhibitions/3?arcade=1',origin:'http://test',body:{_token:'csrf',number:'8'}};
test('existing highlight POST retains CSRF/body and reconciles history hash without a football action or navigation',async()=>{
 const calls=[],snapshot={flow:{state_hash:'new'}};
 await saveExhibitionHighlight({...base,request:async(url,options)=>{calls.push(['post',url,options]);return {ok:true,url:'http://test/exhibitions/3'};},read:async()=>{calls.push(['read']);return snapshot;},reconcile:next=>calls.push(['reconcile',next])});
 assert.equal(calls[0][1],'http://test/exhibitions/3/highlights');assert.equal(calls[0][2].body,base.body);assert.equal(calls[0][2].credentials,'same-origin');assert.equal(calls[0][2].headers.Accept,'application/json');assert.deepEqual(calls.slice(1),[['read'],['reconcile',snapshot]]);
});
test('auth failure, login redirect, wrong destination and lost response never claim success or issue another POST',async()=>{
 for(const response of [{ok:false,status:419,json:async()=>({message:'CSRF token mismatch.'})},{ok:true,url:'http://test/login'},new Error('Connection lost')]){
 let posts=0;await assert.rejects(saveExhibitionHighlight({...base,request:async()=>{posts++;if(response instanceof Error)throw response;return response;},read:()=>assert.fail('no read after unconfirmed POST'),reconcile:()=>assert.fail('no success')}));assert.equal(posts,1);
 }
 await assert.rejects(saveExhibitionHighlight({...base,url:'https://elsewhere.test/exhibitions/3/highlights',request:()=>assert.fail('no external POST')}),/Invalid/);
});
test('read reconciliation failure does not retry a successful highlight POST',async()=>{
 let posts=0;await assert.rejects(saveExhibitionHighlight({...base,request:async()=>{posts++;return {ok:true,url:'http://test/exhibitions/3'};},read:async()=>{throw new Error('Read unavailable');},reconcile:()=>assert.fail('no stale success')}),/Read unavailable/);assert.equal(posts,1);
});

test('highlight-only hash update preserves scene, while concurrent football/flow/session changes require coherent controls',()=>{
 const before={state:{version:8,clock:42},flow:{revision:2,state_hash:'old'}};
 assert.equal(highlightRequiresPresentation(before,{...before,flow:{revision:2,state_hash:'highlight'}}),false);
 assert.equal(highlightRequiresPresentation(before,{...before,state:{version:9,clock:38}}),true);
 assert.equal(highlightRequiresPresentation(before,{...before,state:{version:8,clock:42,coin_toss:{pending:false}}}),true);
 assert.equal(highlightRequiresPresentation(before,{...before,flow:{revision:3}}),true);
 assert.equal(highlightRequiresPresentation(before,{...before,pending_session:{envelope:{session_id:'new'}}}),true);
});
