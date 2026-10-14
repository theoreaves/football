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
