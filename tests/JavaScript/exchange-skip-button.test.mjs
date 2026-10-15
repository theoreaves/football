import test from 'node:test';
import assert from 'node:assert/strict';
import {mountExchangeSkipButton} from '../../resources/js/practice/exchange-skip-button.js';

test('play-call skip button fast-forwards exactly once and disappears when players arrive',()=>{
    let active=true,fastForwarding=false,skips=0;
    const button={};
    const update=mountExchangeSkipButton(button,()=>({active,fastForwarding}),()=>{skips++;fastForwarding=true;});
    assert.equal(button.type,'button');assert.equal(button.hidden,false);assert.equal(button.disabled,false);
    assert.equal(button.textContent,'Skip player change');
    button.onclick();assert.equal(skips,1);assert.equal(button.disabled,true);assert.equal(button.textContent,'Fast-forwarding…');
    button.onclick();assert.equal(skips,1);
    active=false;update();assert.equal(button.hidden,true);assert.equal(button.disabled,true);
});

test('rebuilding wizard steps preserves live skip state and supports later substitutions',()=>{
    let state={active:true,fastForwarding:false},skips=0;
    const action=()=>{skips++;state.fastForwarding=true;};
    const formationButton={};mountExchangeSkipButton(formationButton,()=>state,action);
    formationButton.onclick();assert.equal(skips,1);
    const reviewButton={};const update=mountExchangeSkipButton(reviewButton,()=>state,action);
    assert.equal(reviewButton.disabled,true);assert.equal(reviewButton.textContent,'Fast-forwarding…');
    state={active:false,fastForwarding:false};update();assert.equal(reviewButton.hidden,true);
    reviewButton.onclick();assert.equal(skips,1);
    state={active:true,fastForwarding:false};update();assert.equal(reviewButton.hidden,false);assert.equal(reviewButton.disabled,false);
    reviewButton.onclick();assert.equal(skips,2);
});
