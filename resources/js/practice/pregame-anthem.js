export function mountPregameAnthem(root, dialog, audio, opening, storage) {
    const panel=dialog?.querySelector('[data-anthem-panel]');
    const button=dialog?.querySelector('[data-anthem-play]');
    const status=dialog?.querySelector('[data-anthem-status]');
    const key=root.dataset.cameraKey ? `${root.dataset.cameraKey}:anthem-played` : null;
    let used=false, closing;
    try {used=key && (storage ?? window.localStorage).getItem(key)==='yes';}catch{/* Optional persistence. */}
    if(panel)panel.hidden=!opening;
    const mark=()=>{used=true;if(button)button.hidden=true;try{if(key)(storage ?? window.localStorage).setItem(key,'yes');}catch{/* Optional persistence. */}};
    const labels={playing:'National anthem playing',paused:'National anthem paused',gesture:'Press Play anthem to start',unavailable:'Anthem unavailable — you can continue',ended:'National anthem complete'};
    const start=()=>{
        if(!opening || used || closing)return;
        audio.startAnthem({onStarted:mark,onState:state=>{if(status)status.textContent=labels[state] ?? 'National anthem';}});
    };
    if(used){if(button)button.hidden=true;if(status)status.textContent='National anthem already played';}
    button?.addEventListener('click',start);
    if(opening && !used)start();
    return {
        finish(seconds=.6){
            if(closing)return closing;
            if(opening)mark();opening=false;
            closing=audio.finishAnthem(seconds);return closing;
        },
        closed(){opening=false;if(panel)panel.hidden=true;audio.finishAnthem(0);}
    };
}
