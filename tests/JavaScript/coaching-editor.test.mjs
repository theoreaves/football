import test from 'node:test';
import assert from 'node:assert/strict';
import {matchingCoachingPreset,mountCoachingEditor} from '../../resources/js/coaching-editor.js';
const presets={balanced:{label:'Balanced',description:'Neutral tendencies',values:{pass:50,blitz:50,tempo:50}},attack:{label:'Attack',description:'More passing',values:{pass:85,blitz:75,tempo:70}}};
function fixture(saved={pass:50,blitz:50,tempo:50},posted=saved){
    const inputs=Object.keys(saved).map(key=>{
        const input=new EventTarget(),output={value:''};
        return Object.assign(input,{dataset:{coachingKey:key},value:String(posted[key]),output,closest:()=>({querySelector:()=>output})});
    });
    const controls=Object.fromEntries(['preset','apply','restore','description','status','tools'].map(key=>[key,Object.assign(new EventTarget(),{value:'',disabled:false,hidden:true,textContent:''})]));
    const root=Object.assign(new EventTarget(),{dataset:{coachingPresets:JSON.stringify(presets),coachingSaved:JSON.stringify(saved)},querySelectorAll:()=>inputs,querySelector:selector=>controls[selector.match(/data-coaching-(.+)\]/)[1]]});
    const values=()=>Object.fromEntries(inputs.map(input=>[input.dataset.coachingKey,Number(input.value)]));
    return {root,inputs,controls,values};
}
test('presets apply every tendency update outputs and restore saved values without submitting',()=>{
    const {root,inputs,controls,values}=fixture();
    let changes=0;root.addEventListener('change',()=>changes++);
    mountCoachingEditor(root);mountCoachingEditor(root);
    assert.equal(controls.tools.hidden,false);
    assert.equal(controls.preset.value,'balanced');assert.equal(controls.restore.disabled,true);
    controls.preset.value='attack';controls.preset.dispatchEvent(new Event('change'));
    assert.equal(controls.description.textContent,'More passing');
    assert.deepEqual(values(),presets.balanced.values);
    controls.apply.dispatchEvent(new Event('click'));
    assert.deepEqual(values(),presets.attack.values);assert.equal(changes,1);
    inputs.forEach(input=>assert.equal(input.output.value,input.value));
    assert.match(controls.status.textContent,/Unsaved changes/);
    inputs[0].value='80';inputs[0].dispatchEvent(new Event('input'));
    assert.equal(controls.preset.value,'');assert.equal(controls.apply.disabled,true);
    assert.match(controls.status.textContent,/Custom/);
    controls.restore.dispatchEvent(new Event('click'));
    assert.deepEqual(values(),presets.balanced.values);assert.equal(changes,2);
    assert.equal(controls.restore.disabled,true);
});
test('validation redisplay retains posted values until explicitly restoring actual saved tendencies',()=>{
    const saved={pass:85,blitz:75,tempo:70},posted={pass:10,blitz:20,tempo:30};
    const {root,controls,values}=fixture(saved,posted);mountCoachingEditor(root);
    assert.deepEqual(values(),posted);assert.equal(controls.preset.value,'');
    controls.restore.dispatchEvent(new Event('click'));assert.deepEqual(values(),saved);
    assert.equal(controls.preset.value,'attack');
    controls.preset.value='balanced';controls.apply.dispatchEvent(new Event('click'));
    assert.deepEqual(values(),presets.balanced.values);assert.match(controls.status.textContent,/Unsaved/);
});
test('custom and partial profiles are not mislabeled as presets',()=>{
    assert.equal(matchingCoachingPreset({pass:'50',blitz:'50',tempo:'50'},presets),'balanced');
    assert.equal(matchingCoachingPreset({pass:50},presets),'');
    assert.equal(matchingCoachingPreset({pass:85,blitz:75,tempo:70,extra:1},presets),'');
    mountCoachingEditor(null);
});
