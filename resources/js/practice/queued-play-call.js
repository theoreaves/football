const queuedAcceptance = Symbol('queued-play-acceptance');
const queuedRejection = Symbol('queued-play-rejection');
export function rejectQueuedPlay(form,error) {form[queuedRejection]?.(error);}
export function acceptQueuedPlay(form) {form[queuedAcceptance]?.();}

// A queued choice is presentation-only. The game is posted once the visible exchange ends.
export function queuedPlayCall(form, root, {ready, onQueued=()=>{}, onRelease=()=>{}, requireAcceptance=false, onFailure=()=>{}}) {
    let payload=null,disposed=false,released=false,failed=false;
    const capture=event=>{
        if(disposed)return;
        if(payload || released){event.preventDefault();event.stopImmediatePropagation();return;}
        if(ready() && !requireAcceptance)return;
        event.preventDefault();event.stopImmediatePropagation();
        payload=new FormData(form);
        if(event.submitter?.name)payload.append(event.submitter.name,event.submitter.value);
        form.dataset.playPending='true';form.inert=true;
        onQueued();
    };
    form.addEventListener('submit',capture,true);
    return {
        tick(){
            if(disposed || released || failed || !payload || !root.isConnected || !ready())return;
            const data=payload;
            if(!requireAcceptance){released=true;payload=null;}
            const doc=form.ownerDocument || document;
            const proxy=doc.createElement('form');
            proxy.method='POST';proxy.setAttribute('action',form.getAttribute('action'));proxy.hidden=true;
            for(const [name,value] of data){
                const input=doc.createElement('input');input.type='hidden';input.name=name;input.value=value;proxy.append(input);
            }
            if(requireAcceptance){proxy[queuedAcceptance]=()=>{if(disposed || released)return;released=true;payload=null;onRelease();};proxy[queuedRejection]=error=>{if(disposed || released)return;failed=true;onFailure(error);};}
            root.append(proxy);
            try {if(!requireAcceptance)onRelease();proxy.requestSubmit();} finally {proxy.remove();}
        },
        dispose(){disposed=true;payload=null;form.removeEventListener('submit',capture,true);},
    };
}
