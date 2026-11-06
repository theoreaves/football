export function matchingCoachingPreset(values,presets) {
    return Object.keys(presets).find(key=>{
        const expected=presets[key].values;
        return Object.keys(values).length===Object.keys(expected).length
            && Object.entries(expected).every(([name,value])=>Number(values[name])===value);
    }) || '';
}

export function mountCoachingEditor(root) {
    if(!root || root.dataset.coachingMounted)return;
    const presets=JSON.parse(root.dataset.coachingPresets || '{}');
    const saved=JSON.parse(root.dataset.coachingSaved || '{}');
    const inputs=[...root.querySelectorAll('[data-coaching-key]')];
    const select=root.querySelector('[data-coaching-preset]');
    const apply=root.querySelector('[data-coaching-apply]');
    const restore=root.querySelector('[data-coaching-restore]');
    if(!inputs.length || !select || !apply || !restore)return;
    root.dataset.coachingMounted='true';
    const values=()=>Object.fromEntries(inputs.map(input=>[input.dataset.coachingKey,Number(input.value)]));
    const describe=()=>{
        apply.disabled=!presets[select.value];
        root.querySelector('[data-coaching-description]').textContent=presets[select.value]?.description || 'Adjust the individual tendencies below.';
    };
    const refresh=()=>{
        const current=values();
        select.value=matchingCoachingPreset(current,presets);
        describe();
        const dirty=Object.keys(current).some(key=>current[key]!==Number(saved[key]));
        root.querySelector('[data-coaching-status]').textContent=`${presets[select.value]?.label || 'Custom'} tendencies. ${dirty?'Unsaved changes — save the team to use these in new games.':'Matches saved tendencies.'}`;
        restore.disabled=!dirty;
        inputs.forEach(input=>{input.closest('label').querySelector('output').value=input.value;});
    };
    const set=profile=>{
        if(!profile)return;
        inputs.forEach(input=>{
            const value=profile[input.dataset.coachingKey];
            if(Number.isInteger(value)&&value>=0&&value<=100)input.value=String(value);
        });
        refresh();
        // Notify existing form/preview listeners once, after every value is set.
        root.dispatchEvent(new Event('change',{bubbles:true}));
    };
    inputs.forEach(input=>input.addEventListener('input',refresh));
    select.addEventListener('change',describe);
    apply.addEventListener('click',()=>set(presets[select.value]?.values));
    restore.addEventListener('click',()=>set(saved));
    root.querySelector('[data-coaching-tools]').hidden=false;
    refresh();
}
