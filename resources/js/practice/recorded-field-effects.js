// Named event assets keep room for future stadium/team sound packs.
const assets = {'whistle-short':'whistle-short.mp3','whistle-alt':'whistle-alt.mp3'};
export function recordedFieldEffects(context, destination, environment, base='/audio/referee/v1') {
    const buffers=new Map(), voices=new Set();
    const abort=environment.AbortController ? new environment.AbortController() : null;
    let loading, active=false, disposed=false;
    const load=()=>{
        if (loading || disposed || !environment.fetch) return loading ?? Promise.resolve();
        loading=Promise.all(Object.entries(assets).map(async([name,file])=>{
            try {
                const response=await environment.fetch(`${base.replace(/\/$/,'')}/${file}`,abort ? {signal:abort.signal}:undefined);
                if (!response.ok) return;
                const buffer=await context.decodeAudioData(await response.arrayBuffer());
                if (!disposed) buffers.set(name,buffer);
            } catch { /* Stay silent; never delay gameplay or queue stale cues. */ }
        }));
        return loading;
    };
    const stop=()=>{for(const source of voices){try{source.stop();}catch{/* Already ended. */}}voices.clear();};
    const playWhistle=(style='short',variant=0)=>{
        if (!active || disposed || context.state!=='running') return;
        const name=style==='stop' || Math.abs(Math.trunc(variant))%2===0 ? 'whistle-short':'whistle-alt';
        const buffer=buffers.get(name) ?? buffers.get('whistle-short') ?? buffers.get('whistle-alt');
        if (!buffer) return;
        for(const delay of style==='stop' ? [0,buffer.duration+.1]:[0]){
            const source=context.createBufferSource(),gain=context.createGain(),start=context.currentTime+delay;
            source.buffer=buffer;gain.gain.value=.55;
            source.connect(gain);gain.connect(destination);voices.add(source);
            source.onended=()=>{voices.delete(source);source.disconnect();gain.disconnect();};
            source.start(start);
        }
    };
    return {load,playWhistle,setActive(value){active=Boolean(value)&&!disposed;if(!active)stop();},
        dispose(){disposed=true;active=false;abort?.abort();stop();buffers.clear();}};
}
