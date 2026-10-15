// A queued choice is presentation-only. The game is posted once the visible exchange ends.
export function queuedPlayCall(form, root, {ready, onQueued=()=>{}, onRelease=()=>{}}) {
    let payload=null,disposed=false,released=false;
    const capture=event=>{
        if(disposed)return;
        if(payload || released){event.preventDefault();event.stopImmediatePropagation();return;}
        if(ready())return;
        event.preventDefault();event.stopImmediatePropagation();
        payload=new FormData(form);
        if(event.submitter?.name)payload.append(event.submitter.name,event.submitter.value);
        form.dataset.playPending='true';form.inert=true;
        onQueued();
    };
    form.addEventListener('submit',capture,true);
    return {
        tick(){
            if(disposed || released || !payload || !root.isConnected || !ready())return;
            released=true;
            const data=payload;payload=null;
            const doc=form.ownerDocument || document;
            const proxy=doc.createElement('form');
            proxy.method='POST';proxy.setAttribute('action',form.getAttribute('action'));proxy.hidden=true;
            for(const [name,value] of data){
                const input=doc.createElement('input');input.type='hidden';input.name=name;input.value=value;proxy.append(input);
            }
            root.append(proxy);
            try {onRelease();proxy.requestSubmit();} finally {proxy.remove();}
        },
        dispose(){disposed=true;payload=null;form.removeEventListener('submit',capture,true);},
    };
}
