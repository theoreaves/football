// Named event assets keep room for future stadium/team sound packs.
const assets = {'kick-1':'kick-1.mp3','catch-1':'catch-1.mp3','catch-2':'catch-2.mp3',
    'catch-3':'catch-3.mp3','ground-1':'ground-1.mp3','ground-2':'ground-2.mp3'};
export function recordedBallEffects(context, destination, environment, base='/audio/ball/v1') {
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
        const prefix=type==='kick' ? 'kick':type==='catch' ? 'catch':type==='ball_ground' ? 'ground':null;
        const count=prefix==='catch' ? 3:prefix==='ground' ? 2:1;
        const index=Math.abs(Math.trunc(Number(variant)||0))%count+1;
        if (!prefix) return;
        const name=`${prefix}-${index}`;
        const buffer=buffers.get(name) ?? buffers.get(`${prefix}-1`) ?? buffers.get(`${prefix}-2`) ?? buffers.get(`${prefix}-3`);
        if (!buffer) return;
        // Keep collisions bounded even when ball and contact events coincide.
        if (voices.size >= 6) return;
        const source=context.createBufferSource(),gain=context.createGain();
        source.buffer=buffer;gain.gain.value=type==='kick' ? .7:type==='catch' ? .4:.3;
        source.connect(gain);gain.connect(destination);voices.add(source);
        source.onended=()=>{voices.delete(source);source.disconnect();gain.disconnect();};
        source.start(context.currentTime);
    };
    return {load,play,setActive(value){active=Boolean(value)&&!disposed;if(!active)stop();},
        dispose(){disposed=true;active=false;abort?.abort();stop();buffers.clear();}};
}
