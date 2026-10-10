const assets = {ready:'ready.mp3',set:'set.mp3',hut:'hut.mp3'};

export function recordedPlayerVoices(context, destination, environment, base='/audio/players/v1') {
    const buffers=new Map(), voices=new Set();
    const abort=environment.AbortController ? new environment.AbortController() : null;
    let loading, active=false, disposed=false;
    const stop=()=>{for(const source of voices){try{source.stop();}catch{/* Already ended. */}}voices.clear();};
    const load=()=>{
        if (loading || disposed || !environment.fetch) return loading ?? Promise.resolve();
        loading=Promise.all(Object.entries(assets).map(async([name,file])=>{
            try {
                const response=await environment.fetch(`${base.replace(/\/$/,'')}/${file}`,abort ? {signal:abort.signal}:undefined);
                if (!response.ok) return;
                const buffer=await context.decodeAudioData(await response.arrayBuffer());
                if (!disposed) buffers.set(name,buffer);
            } catch { /* Missing audio never delays the snap or queues an old call. */ }
        }));
        return loading;
    };
    const play=word=>{
        if (!active || disposed || context.state!=='running') return;
        const buffer=buffers.get(word);
        if (!buffer) return;
        // Cut any interrupted word rather than overlapping quarterback calls.
        stop();
        const source=context.createBufferSource(), gain=context.createGain();
        source.buffer=buffer;gain.gain.value=.8;
        source.connect(gain);gain.connect(destination);voices.add(source);
        source.onended=()=>{voices.delete(source);source.disconnect();gain.disconnect();};
        source.start(context.currentTime);
    };
    return {load,play,stop,setActive(value){active=Boolean(value)&&!disposed;if(!active)stop();},
        dispose(){disposed=true;active=false;abort?.abort();stop();buffers.clear();}};
}
