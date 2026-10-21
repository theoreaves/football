const choices=[['now','Call timeout now'],['at_one','Wait until 1 second, then timeout'],['after_lineup','Line up, then timeout']];
export function playTimeouts(root,form,onSubmit) {
    const sources=[...root.querySelectorAll('[data-timeout-form]')];
    let pending=false;
    sources.forEach(source=>source.hidden=true);
    return host=>{
        if(!sources.length)return;
        const menu=document.createElement('details');menu.className='wizard-timeouts';menu.style.gridColumn='1 / -1';
        const heading=document.createElement('summary');heading.textContent='Timeouts';menu.append(heading);
        for(const source of sources){
            const label=document.createElement('p');label.textContent=source.querySelector('button').textContent;menu.append(label);
            for(const [value,text] of choices){
                const button=document.createElement('button');button.type='button';button.textContent=text;button.className='border border-gray-500 rounded px-3 py-2 m-1';button.disabled=pending;
                button.addEventListener('click',()=>{
                    if(pending)return;pending=true;
                    const data=new FormData(form);
                    data.set('action','timeout');data.set('timeout_timing',value);
                    data.set('timeout_team',source.elements.namedItem('timeout_team').value);
                    data.set('version',source.elements.namedItem('version').value);
                    const proxy=document.createElement('form');proxy.hidden=true;proxy.method='POST';proxy.setAttribute('action',source.getAttribute('action'));
                    for(const [name,value] of data){const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;proxy.append(input);}
                    root.append(proxy);form.dataset.playPending='true';form.inert=true;
                    const notice=document.createElement('p');notice.className='game-call-pending';notice.setAttribute('role','status');notice.textContent='Calling timeout…';root.append(notice);
                    onSubmit();try{proxy.requestSubmit();}finally{proxy.remove();}
                });menu.append(button);
            }
        }
        host.append(menu);
    };
}
