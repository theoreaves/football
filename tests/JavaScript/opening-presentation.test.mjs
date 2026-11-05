import test from 'node:test';
import assert from 'node:assert/strict';
import {entranceComplete,hideOpeningUnits} from '../../resources/js/practice/opening-presentation.js';

test('opening completion advances independently of human decision timers',()=>{
    const state={active:true,finishing:false,seconds:79,end:79,visible:true};
    assert.equal(entranceComplete(state),true);
    assert.equal(entranceComplete({...state,seconds:78.9}),false);
    assert.equal(entranceComplete({...state,visible:false}),false);
    assert.equal(entranceComplete({...state,finishing:true}),false);
    assert.equal(entranceComplete({...state,active:false}),false);
});

test('all normal field and substitution models stay hidden during the tunnel sequence',()=>{
    const field=[{visible:true},{visible:false}],incoming=[{visible:true}],outgoing=[{visible:true}];
    hideOpeningUnits(field,incoming,outgoing);
    assert.ok([...field,...incoming,...outgoing].every(model=>model.visible===false));
});

test('entrance skip is actionable immediately and skips lineups only when requested',async()=>{
    const {finishOpeningEntrance}=await import('../../resources/js/practice/opening-presentation.js');
    for(const skipIntro of [false,true]){
        const entrance={active:true,seconds:0},buttons=[{disabled:false},{disabled:false}],panel={hidden:false,querySelectorAll:()=>buttons};
        let release,stops=0;const destinations=[];
        const args={entrance,panel,stopMusic:()=>{stops++;return new Promise(resolve=>{release=resolve;});},isConnected:()=>true,continueIntro:skip=>destinations.push(skip)};
        const finishing=finishOpeningEntrance(args,skipIntro);
        assert.ok(buttons.every(button=>button.disabled));assert.equal(entrance.finishing,true);
        await finishOpeningEntrance(args,!skipIntro);assert.equal(stops,1);
        release();await finishing;
        assert.equal(panel.hidden,true);assert.equal(entrance.active,false);
        assert.deepEqual(destinations,[skipIntro]);
    }
});
