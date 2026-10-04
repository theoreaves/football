import test from 'node:test';
import assert from 'node:assert/strict';
import {soundCues,crossedCues,soundSettings} from '../../resources/js/practice/sound-cues.js';
import {stadiumAudio} from '../../resources/js/practice/stadium-audio.js';
const state = {phase:'scrimmage',possession:'home',down:2,quarter:1,home_score:0,away_score:0,status:'playing',stats:{home:{turnovers:0},away:{turnovers:0}}};
const play = {call:'short_pass',passing:true,events:[[5.3,'Complete pass · TOUCHDOWN']]};
test('crowd favors the home team for scoring and turnover results',()=>{
    const home=soundCues(play,state,{...state,home_score:6,phase:'extra_point'});
    assert.equal(home.find(c=>c.type==='crowd').mood,'cheer');
    assert.ok(home.some(c=>c.type==='touchdown'));
    const away=soundCues(play,{...state,possession:'away'},{...state,possession:'away',away_score:6,phase:'extra_point'});
    assert.equal(away.find(c=>c.type==='crowd').mood,'groan');
    assert.ok(!away.some(c=>c.type==='touchdown'));
    const turnover={...play,events:[[5.3,'Intercepted']]};
    assert.equal(soundCues(turnover,{...state,possession:'away'}, {...state,stats:{home:{turnovers:0},away:{turnovers:1}}}).find(c=>c.type==='crowd').mood,'cheer');
});
test('home first downs and home defensive third downs have stadium cues',()=>{
    assert.ok(soundCues(play,state,{...state,down:1}).some(c=>c.type==='first_down'));
    assert.ok(!soundCues(play,{...state,possession:'away'},{...state,possession:'away',down:1}).some(c=>c.type==='first_down'));
    assert.ok(soundCues(play,{...state,possession:'away',down:3},state).some(c=>c.type==='third_down' && c.time < 0));
});
test('timing follows snap throw and result without cues on seek or duplicate frames',()=>{
    const cues=soundCues(play,state,{...state,home_score:6});
    assert.deepEqual(crossedCues(cues,-.1,0).map(c=>c.type),['snap']);
    assert.ok(!crossedCues(cues,0,2).some(c=>c.type==='crowd'));
    assert.equal(crossedCues(cues,2,2.3)[0].type,'throw');
    assert.deepEqual(crossedCues(cues,5.3,5.3),[]);
    assert.deepEqual(crossedCues(cues,5,1),[]);
    assert.ok(crossedCues(cues,-1,6).some(c=>c.type==='touchdown'));
});
test('pending penalty choices do not announce a provisional score or final result',()=>{
    const cues=soundCues(play,state,{...state,home_score:6,status:'final',penalty_pending:true});
    assert.ok(!cues.some(c=>['crowd','touchdown','final'].includes(c.type)));
    const stop=soundCues({call:'clock_event',no_snap:true,events:[]},state,{...state,quarter:2});
    assert.ok(!stop.some(c=>['snap','tackle','throw'].includes(c.type)));
    assert.ok(stop.some(c=>c.type==='quarter'));
});
test('audio settings clamp levels and persist mute without requiring audio support',()=>{
    assert.deepEqual(soundSettings({master:9,crowd:-1,effects:'bad',enabled:false}),{master:1,crowd:0,effects:.7,enabled:false});
    const saved = [];
    const root={querySelector:()=>null,querySelectorAll:()=>[],addEventListener(){},removeEventListener(){}};
    const audio=stadiumAudio(root,{localStorage:{getItem:()=>'{invalid',setItem:(...v)=>saved.push(v)}});
    audio.setActive(true);audio.play({type:'whistle'});audio.setActive(false);audio.dispose();
});

test('pausing stops effects and mute is saved for the next game',()=>{
    let instance, stored, stops=0;
    const node=()=>({connect(){},disconnect(){},gain:{value:0,setTargetAtTime(v){this.value=v;},setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},frequency:{value:0},start(){},stop(){stops++;}});
    class Context {
        constructor(){instance=this;this.sampleRate=100;this.currentTime=0;this.state='running';}
        createGain(){const gain=node();this.gains ??=[];this.gains.push(gain);return gain;}
        createBuffer(c,length){return {getChannelData:()=>new Float32Array(length)};}
        createBufferSource(){return node();}
        createOscillator(){return node();}
        createBiquadFilter(){return node();}
        close(){return Promise.resolve();}
    }
    const callbacks={};
    const button={addEventListener:(type,callback)=>callbacks[type]=callback,setAttribute(){}};
    const root={querySelector:()=>button,querySelectorAll:()=>[],addEventListener(){},removeEventListener(){}};
    const audio=stadiumAudio(root,{AudioContext:Context,localStorage:{getItem:()=>null,setItem:(key,value)=>stored=JSON.parse(value)}});
    audio.setActive(true);audio.play({type:'whistle'});
    const scheduledStops=stops;audio.setActive(false);
    assert.ok(stops > scheduledStops);
    assert.equal(instance.gains[0].gain.value,0);
    callbacks.click();assert.equal(stored.enabled,false);
    audio.dispose();
});
