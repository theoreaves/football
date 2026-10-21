// Keep the selected tempo and formations together; server enforces personnel identities.
export function hurryUpCalling(root,form) {
    const saved=JSON.parse(root.dataset.noHuddle || 'null');
    if(!saved)return ()=>false;
    const tempo=form.elements.namedItem('tempo');
    const active=()=>tempo?tempo.value==='hurry':root.dataset.cpuSpecial!=='true' && (root.dataset.cpuHurry==='true' || Boolean(JSON.parse(root.dataset.afterState || '{}').no_huddle));
    const update=()=>{
        for(const [name,value] of [['offense_formation',saved.offense_formation],['defense_formation',saved.defense_formation]]){
            const select=form.elements.namedItem(name);if(!select)continue;
            if(active() && select.value!==value){select.value=value;select.dispatchEvent(new Event('change',{bubbles:true}));}
            for(const option of select.options)option.disabled=active() && option.value!==value;
        }
        form.dataset.noHuddle=String(active());
        for(const panel of root.querySelectorAll('.one-play-personnel'))panel.hidden=active();
    };
    tempo?.addEventListener('change',update);update();
    return ()=>{update();return active();};
}
