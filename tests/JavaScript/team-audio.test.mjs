import test from 'node:test';
import assert from 'node:assert/strict';
import {teamAudioUrls} from '../../resources/js/practice/team-audio.js';
import {recordedStadiumReactions} from '../../resources/js/practice/recorded-stadium-reactions.js';
import {recordedStadiumMusic} from '../../resources/js/practice/recorded-stadium-music.js';
test('home profile overrides selected events while all others retain bundled defaults',()=>{
 const urls=teamAudioUrls({dataset:{stadiumAudioBase:'/sounds/',teamAudio:JSON.stringify({touchdown:'/teams/1/audio/a.mp3',entrance:'/teams/1/audio/b.ogg'})}});
 assert.equal(urls.touchdown.url,'/teams/1/audio/a.mp3');assert.equal(urls.touchdown.fallback,'/sounds/touchdown.mp3');assert.equal(urls.entrance.url,'/teams/1/audio/b.ogg');assert.equal(urls.halftime.url,'/sounds/halftime-break.mp3');assert.equal(Object.keys(urls).length,8);
 assert.equal(teamAudioUrls({dataset:{teamAudio:'broken'}}).anthem.url,'/audio/stadium/v1/anthem.mp3');
});
test('unavailable or undecodable custom reactions fall back without queuing old events',async()=>{
 const requests=[],sources=[];
 const context={state:'running',currentTime:0,decodeAudioData:async bytes=>{if(bytes==='bad')throw Error();return {duration:2};},createGain:()=>({gain:{},connect(){},disconnect(){}}),createBufferSource:()=>{const s={connect(){},disconnect(){},start(){},stop(){}};sources.push(s);return s;}};
 const environment={fetch:async url=>{requests.push(url);return {ok:!url.includes('missing'),arrayBuffer:async()=>url.includes('bad')?'bad':new ArrayBuffer(1)};}};
 const audio=recordedStadiumReactions(context,{},environment,'/bundle',{touchdown:{url:'/missing.mp3'},third_down:{url:'/bad.mp3'}});
 audio.setActive(true);audio.play('touchdown');await audio.load();assert.equal(sources.length,0);
 assert.ok(requests.includes('/bundle/touchdown.mp3'));assert.ok(requests.includes('/bundle/third-down.mp3'));
 audio.play('touchdown');assert.equal(sources.length,1);audio.dispose();
});
test('streamed music retries the bundled track once on a media error and respects mute',async()=>{
 let media;const environment={Audio:class {constructor(url){this.src=url;this.paused=true;this.events={};media=this;}addEventListener(name,fn){this.events[name]=fn;}play(){this.paused=false;return Promise.resolve();}pause(){this.paused=true;}load(){this.paused=true;}removeAttribute(){}}};
 const music=recordedStadiumMusic(environment,'/custom.mp3','/default.mp3');music.setLevel(.25);music.request();await Promise.resolve();
 media.events.error();await Promise.resolve();assert.equal(media.src,'/default.mp3');assert.equal(media.volume,.25);assert.equal(media.paused,false);
 music.setLevel(0);assert.equal(media.paused,true);music.dispose();
});
