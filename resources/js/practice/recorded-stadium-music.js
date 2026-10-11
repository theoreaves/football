// Stream long music independently of the short Web Audio effects.
export function recordedStadiumMusic(environment, url, fallbackUrl = url) {
    let usedFallback=false;
    let media, level=0, wanted=false, started=false, finished=false, disposed=false;
    let pending=false, generation=0, timer, finishing, resolveFinish, fade=1;
    let onStarted=()=>{}, onState=()=>{};
    const document=environment.document;
    const schedule=(fn,ms)=>(environment.setTimeout ?? globalThis.setTimeout)(fn,ms);
    const unschedule=id=>(environment.clearTimeout ?? globalThis.clearTimeout)(id);
    const volume=()=>{if(media)media.volume=Math.max(0,Math.min(1,level*fade));};
    const pause=()=>{generation++;pending=false;media?.pause();if(started && wanted)onState('paused');};
    const completeFinish=()=>{
        if(timer!==undefined)unschedule(timer);timer=undefined;
        pause();fade=0;volume();resolveFinish?.();resolveFinish=undefined;
    };
    const attempt=()=>{
        if (!wanted || finished || disposed || pending || level<=0 || document?.hidden) return;
        if (!environment.Audio) {onState('unavailable');return;}
        if (!media) {
            try {media=new environment.Audio(url);} catch {onState('unavailable');return;}
            media.preload='auto';media.loop=false;volume();
            media.addEventListener('error',()=>{
                if(disposed || finished)return;
                if(!usedFallback && fallbackUrl!==url){usedFallback=true;generation++;pending=false;media.src=fallbackUrl;media.load();attempt();}
                else onState('unavailable');
            });
            media.addEventListener('ended',()=>{finished=true;wanted=false;onState('ended');completeFinish();});
        }
        if (!media.paused) return;
        pending=true;const token=++generation;
        try {
            Promise.resolve(media.play()).then(()=>{
                if (token!==generation || !wanted || finished || disposed || document?.hidden || level<=0) {
                    if (!wanted || finished || disposed || document?.hidden || level<=0) media.pause();
                    return;
                }
                pending=false;
                if(!started){started=true;onStarted();}
                onState('playing');
            }).catch(error=>{
                if(token!==generation || !wanted || finished || disposed)return;
                pending=false;onState(error?.name==='NotAllowedError' ? 'gesture':'unavailable');
            });
        } catch {pending=false;onState('unavailable');}
    };
    const visibility=()=>{
        if(document.hidden){if(finishing)completeFinish();else pause();}
        else if(started)attempt();
    };
    document?.addEventListener('visibilitychange',visibility);
    return {
        request(callbacks={}) {
            if(finished || disposed)return;
            onStarted=callbacks.onStarted ?? onStarted;onState=callbacks.onState ?? onState;
            wanted=true;attempt();
        },
        unlock:attempt,
        setLevel(value){const previous=level;level=value;volume();if(level<=0){if(finishing)completeFinish();else pause();}else if(previous<=0)attempt();},
        finish(seconds=.6){
            if(finishing)return finishing;
            wanted=false;finished=true;generation++;pending=false;
            if(!media || media.paused || level<=0 || document?.hidden || seconds<=0){completeFinish();return Promise.resolve();}
            finishing=new Promise(resolve=>{resolveFinish=resolve;});
            let step=0;
            const tick=()=>{fade=1-(++step/12);volume();if(step===12)completeFinish();else timer=schedule(tick,seconds*1000/12);};
            timer=schedule(tick,seconds*1000/12);return finishing;
        },
        dispose(){disposed=true;wanted=false;finished=true;completeFinish();document?.removeEventListener('visibilitychange',visibility);if(media){media.removeAttribute('src');media.load();}}
    };
}
