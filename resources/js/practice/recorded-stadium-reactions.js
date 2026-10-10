// Event names are stable so future team sound packs can replace the files.
const assets={touchdown:'touchdown.mp3',home_turnover:'turnover.mp3',third_down:'third-down.mp3'};
export function recordedStadiumReactions(context,destination,environment,base='/audio/stadium/v1') {
    const buffers=new Map();
    const abort=environment.AbortController ? new environment.AbortController():null;
    let loading,active=false,disposed=false,voice,finished=Promise.resolve(),resolveFinished;
    const stop=()=>{if(voice){try{voice.stop();}catch{/* Already ended. */}voice=undefined;}resolveFinished?.();resolveFinished=undefined;};
    const load=()=>{
        if(loading || disposed || !environment.fetch)return loading ?? Promise.resolve();
        loading=Promise.all(Object.entries(assets).map(async([type,file])=>{
            try {
                const response=await environment.fetch(`${base.replace(/\/$/,'')}/${file}`,abort ? {signal:abort.signal}:undefined);
                if(!response.ok)return;
                const buffer=await context.decodeAudioData(await response.arrayBuffer());
                if(!disposed)buffers.set(type,buffer);
            }catch{/* No queued or synthesized fallback when a recording is unavailable. */}
        }));return loading;
    };
    const play=type=>{
        if(!active || disposed || context.state!=='running' || !buffers.has(type))return;
        stop();
        const source=context.createBufferSource(),gain=context.createGain();
        source.buffer=buffers.get(type);gain.gain.value=.65;
        source.connect(gain);gain.connect(destination);voice=source;finished=new Promise(resolve=>{resolveFinished=resolve;});
        source.onended=()=>{if(voice===source){voice=undefined;resolveFinished?.();resolveFinished=undefined;}source.disconnect();gain.disconnect();};
        source.start(context.currentTime);
    };
    return {load,play,whenFinished:()=>finished,setActive(value){active=Boolean(value)&&!disposed;if(!active)stop();},dispose(){disposed=true;active=false;abort?.abort();stop();buffers.clear();}};
}
