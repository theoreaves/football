// One home-side music cue per opening, independent of game clocks and play RNG.
export function entranceMusic(audio,startAt) {
    let started=false,finished=false;
    return {
        tick(seconds,visible=true){if(!started && !finished && visible && seconds>=startAt){started=true;audio.startEntranceMusic();}},
        finish(){if(finished)return Promise.resolve();finished=true;return audio.finishEntranceMusic(.6);}
    };
}
