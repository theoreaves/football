// Named event assets keep room for future stadium/team sound packs.
const assets = {'pad-1':'pad-1.mp3','pad-2':'pad-2.mp3','pad-3':'pad-3.mp3',
    'hit-1':'hit-1.mp3','hit-2':'hit-2.mp3','hit-3':'hit-3.mp3','grass':'grass.mp3'};
export function recordedContactEffects(context, destination, environment, base='/audio/contact/v1') {
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
    const play=(type,variant=0)=>{
        if (!active || disposed || context.state!=='running') return;
        const index=Math.abs(Math.trunc(Number(variant)||0))%3+1;
        const prefix=type==='pad' ? 'pad':type==='tackle' ? 'hit':type==='landing' ? 'grass':null;
        if (!prefix) return;
        const name=prefix==='grass' ? 'grass':`${prefix}-${index}`;
        const buffer=buffers.get(name) ?? buffers.get(`${prefix}-1`) ?? buffers.get(`${prefix}-2`) ?? buffers.get(`${prefix}-3`);
        if (!buffer) return;
        // Keep collisions bounded even when several pairs engage together.
        if (voices.size >= 6) return;
        const source=context.createBufferSource(),gain=context.createGain();
        source.buffer=buffer;gain.gain.value=type==='pad' ? .3:type==='tackle' ? .8:.45;
        source.connect(gain);gain.connect(destination);voices.add(source);
        source.onended=()=>{voices.delete(source);source.disconnect();gain.disconnect();};
        source.start(context.currentTime);
    };
    return {load,play,setActive(value){active=Boolean(value)&&!disposed;if(!active)stop();},
        dispose(){disposed=true;active=false;abort?.abort();stop();buffers.clear();}};
}
