import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {mountExhibitionHistory} from '../../resources/js/arcade/exhibition-history.js';

test('historical replay uses exact saved evidence without touching live storage, input, simulation or network',()=>{
    const node = () => ({disabled:false,listeners:new Map(),addEventListener(name,fn){this.listeners.set(name,fn);},removeEventListener(name){this.listeners.delete(name);}});
    const nodes=Object.fromEntries(['reserve','start','pause','cancel','submit','kind','tempo','score','state','live','status','coach','replay'].map(name=>[name,node()]));
    const window=node(),document=node();
    Object.defineProperty(window,'localStorage',{get(){assert.fail('History must not read live storage');}});
    window.fetch=()=>assert.fail('History must not issue HTTP writes');
    let nextFrame,disposed=0,opened=null,advanced=0;
    const saved={version:1,frames:[[1,2]],actors:[],events:[],result:{reason:'tackle'}};
    const root={dataset:{mounted:'true'},isConnected:true,querySelector:selector=>nodes[selector.match(/data-exhibition-([^\]]+)/)[1]]};
    const panel={active:false,open(recording){opened=recording;this.active=true;},advance(){advanced++;},dispose(){disposed++;}};
    const dispose=mountExhibitionHistory(root,{replayOnly:true,lastRecording:saved,selectedPlay:3,coachUrl:'/exhibitions/12',state:{quarter:1,clock:200,down:2,distance:4,spot:30},pendingSession:{malicious:'ignored'}},{window,document,createReplayPanel:()=>panel,requestAnimationFrame:fn=>{nextFrame=fn;return 1;},cancelAnimationFrame(){}});
    for(const key of ['reserve','start','pause','cancel','submit','kind','tempo']){assert.equal(nodes[key].disabled,true);assert.equal(nodes[key].listeners.size,0);}
    assert.equal(nodes.replay.disabled,false);nodes.replay.listeners.get('click')();assert.equal(opened,saved);
    nextFrame(0);nextFrame(16);assert.equal(advanced,2);assert.equal(nodes.live.textContent,'Recorded play #3');
    dispose();assert.equal(disposed,1);assert.equal(nodes.replay.listeners.size,0);
});
test('history branches before accessing current live-session recovery',()=>{
    const source=readFileSync(new URL('../../resources/js/arcade/exhibition.js',import.meta.url),'utf8');
    assert.ok(source.indexOf('if (data.replayOnly === true) return mountExhibitionHistory') < source.indexOf('storage = window.localStorage'));
    const history=readFileSync(new URL('../../resources/js/arcade/exhibition-history.js',import.meta.url),'utf8');
    assert.equal(/import .*?(exhibition-session|simulation|passing|input)\.js/.test(history),false);
});
