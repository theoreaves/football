import test from 'node:test';
import assert from 'node:assert/strict';
import {exchangeCallAvailability} from '../../resources/js/practice/unit-exchange.js';
import {canAutoOpenWizard,reviewCountdown} from '../../resources/js/practice/play-wizard.js';

test('calling can open during exchanges but final snap readiness waits for arriving players',()=>{
    const state={phase:'huddle',exchange:{duration:8},elapsed:2,calling:true,huddleReady:false};
    const calling=exchangeCallAvailability(state);
    assert.equal(calling.ready,true);assert.equal(calling.personnelReady,false);
    assert.equal(canAutoOpenWizard({ready:calling.ready,opened:false,visible:true,dialogOpen:false}),true);
    const finished=exchangeCallAvailability({...state,elapsed:8});
    assert.equal(finished.ready,true);assert.equal(finished.personnelReady,true);
    assert.equal(exchangeCallAvailability({...state,entrance:true}).ready,false);
    assert.equal(exchangeCallAvailability({...state,calling:false}).ready,false);
    assert.equal(exchangeCallAvailability({...state,phase:'result'}).ready,false);
});

test('automatic review countdown can pause while personnel are entering and resume when ready',()=>{
    const timer=reviewCountdown();timer.start();
    const pending=exchangeCallAvailability({phase:'huddle',exchange:{duration:8},elapsed:2,calling:true});
    assert.equal(timer.tick(10,pending.personnelReady),null);
    const ready=exchangeCallAvailability({phase:'huddle',exchange:{duration:8},elapsed:8,calling:true});
    assert.equal(timer.tick(1,ready.personnelReady),4);
});
