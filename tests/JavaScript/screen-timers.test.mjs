import test from 'node:test';
import assert from 'node:assert/strict';
import {manualGameScreens,screenTimerExpired} from '../../resources/js/practice/screen-timers.js';
import {autoNotices} from '../../resources/js/practice/auto-notices.js';

test('either human side disables screen timers regardless of which side has possession',()=>{
    for(const home of ['human','cpu'])for(const away of ['human','cpu']){
        const cpuOnly=home==='cpu' && away==='cpu'?'true':'false';
        const manual=manualGameScreens({exhibition:true,cpuOnly});
        assert.equal(manual,home==='human' || away==='human');
        for(const seconds of [4.5,5,7,10,12,18,80])assert.equal(screenTimerExpired({manual,elapsed:600,seconds}),cpuOnly==='true');
    }
    assert.equal(manualGameScreens({exhibition:false,cpuOnly:undefined}),false);
    assert.equal(manualGameScreens({exhibition:true,cpuOnly:undefined}),true);
});
test('automatic referee timing always waits for a pending decision and ignores hidden tabs',()=>{
    assert.equal(screenTimerExpired({manual:false,elapsed:4,seconds:4.5}),false);
    assert.equal(screenTimerExpired({manual:false,elapsed:4.5,seconds:4.5}),true);
    assert.equal(screenTimerExpired({manual:false,elapsed:100,seconds:4.5,pending:true}),false);
    assert.equal(screenTimerExpired({manual:false,elapsed:100,seconds:4.5,visible:false}),false);
    assert.equal(screenTimerExpired({manual:false,elapsed:NaN,seconds:4.5}),false);
});
test('notice dialogs remain open without CPU automatic play, then use their normal timers',()=>{
    let submits=0;
    const dialog={open:true,querySelector:()=>({requestSubmit(){submits++;}})};
    const tick=autoNotices([{dialog,seconds:7}]);
    for(let i=0;i<600;i++)tick({delta:.25,enabled:false,visible:true});assert.equal(submits,0);
    for(let i=0;i<28;i++)tick({delta:.25,enabled:true,visible:true});assert.equal(submits,1);
    for(let i=0;i<50;i++)tick({delta:.25,enabled:true,visible:true});assert.equal(submits,1);
});
