const clips = {ambience:'ambience.mp3',cheer:'cheer.mp3',groan:'boo.mp3'};

// Local, licensed recordings only. A missing clip stays silent rather than
// recreating the old synthetic static or delaying a gameplay event.
export function recordedCrowd(context, destination, environment, base = '/audio/crowd/v1') {
    const buffers = new Map(), reactions = new Set();
    const abort = environment.AbortController ? new environment.AbortController() : null;
    let loading, active = false, disposed = false, ambience, ambienceGain;
    let loopOffset = 0, loopStarted = 0;
    const stopAmbience = () => {
        if (!ambience) return;
        loopOffset = (loopOffset + Math.max(0,context.currentTime-loopStarted)) % ambience.buffer.duration;
        const source=ambience; ambience=null;
        try { source.stop(); } catch { /* Already stopped. */ }
    };
    const startAmbience = () => {
        if (!active || disposed || ambience || context.state !== 'running' || !buffers.has('ambience')) return;
        const source=context.createBufferSource(), gain=context.createGain();
        source.buffer=buffers.get('ambience'); source.loop=true;
        gain.gain.setValueAtTime(0,context.currentTime);
        gain.gain.linearRampToValueAtTime(.55,context.currentTime+.3);
        source.connect(gain); gain.connect(destination);
        source.onended=()=>{source.disconnect();gain.disconnect();};
        ambience=source; ambienceGain=gain; loopStarted=context.currentTime;
        source.start(context.currentTime,loopOffset % source.buffer.duration);
    };
    const load = () => {
        if (loading || disposed || !environment.fetch) return loading ?? Promise.resolve();
        loading=Promise.all(Object.entries(clips).map(async ([name,file])=>{
            try {
                const response=await environment.fetch(`${base.replace(/\/$/,'')}/${file}`,abort ? {signal:abort.signal} : undefined);
                if (!response.ok) return;
                const buffer=await context.decodeAudioData(await response.arrayBuffer());
                if (!disposed) { buffers.set(name,buffer); startAmbience(); }
            } catch { /* Partial loads and network failures never stop gameplay. */ }
        }));
        return loading;
    };
    const setActive = value => {
        active=Boolean(value) && !disposed;
        if (active) startAmbience();
        else {
            stopAmbience();
            for (const source of reactions) { try { source.stop(); } catch { /* Already stopped. */ } }
            reactions.clear();
        }
    };
    const play = (mood, {seconds = mood === 'groan' ? 3.4 : 5, level = mood === 'groan' ? .7 : 1, variant = 0} = {}) => {
        if (!active || disposed || context.state !== 'running') return;
        const buffer=buffers.get(mood === 'groan' ? 'groan' : 'cheer');
        if (!buffer) return; // Never replay a stale cheer after an async load.
        const duration=Math.min(seconds,buffer.duration), start=context.currentTime;
        const slots=Math.max(1,Math.floor(buffer.duration / duration));
        const index=((Math.trunc(variant)%slots)+slots)%slots, offset=index*duration;
        const source=context.createBufferSource(), gain=context.createGain();
        source.buffer=buffer;
        gain.gain.setValueAtTime(0,start);
        gain.gain.linearRampToValueAtTime(level,start+.12);
        gain.gain.setValueAtTime(level,start+Math.max(.12,duration-.65));
        gain.gain.linearRampToValueAtTime(0,start+duration);
        source.connect(gain); gain.connect(destination); reactions.add(source);
        source.onended=()=>{reactions.delete(source);source.disconnect();gain.disconnect();};
        // Let the reaction emerge from the crowd bed instead of doubling its volume.
        if (ambienceGain && ambience) {
            ambienceGain.gain.cancelScheduledValues(start);
            ambienceGain.gain.setTargetAtTime(.3,start,.08);
            ambienceGain.gain.setTargetAtTime(.55,start+duration-.3,.35);
        }
        source.start(start,offset,duration);
    };
    return {load,setActive,play,dispose(){disposed=true;abort?.abort();setActive(false);buffers.clear();}};
}
