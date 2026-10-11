import {recordedStadiumReactions} from './recorded-stadium-reactions.js';
import {recordedStadiumMusic} from './recorded-stadium-music.js';
import {soundSettings} from './sound-cues.js';
import {recordedCrowd} from './recorded-crowd.js';
import {recordedPlayerVoices} from './recorded-player-voices.js';
import {recordedBallEffects} from './recorded-ball-effects.js';
import {recordedContactEffects} from './recorded-contact-effects.js';
import {recordedFieldEffects} from './recorded-field-effects.js';

// A same-game navigation adopts this graph without interrupting its crowd source.
const handoffs = new WeakMap();
function closeGraph(graph) {
    for (const name of ['stadiumRecordings','ballRecordings','contactRecordings','playerRecordings','fieldRecordings','recordings']) graph[name]?.dispose();
    graph.context?.close().catch(() => {});
}

// Recorded crowd ambience/reactions, with the existing synthesized field effects.
export function stadiumAudio(root, environment = window) {
    const key = 'football-sound-settings';
    let saved = {}; try { saved = JSON.parse(environment.localStorage.getItem(key) || '{}'); } catch { /* Defaults when storage is unavailable. */ }
    let settings = soundSettings(saved), context, master, crowd, effects, players, stadium, recordings, fieldRecordings, playerRecordings, contactRecordings, ballRecordings, stadiumRecordings, active = false, ambienceActive = false, disposed = false;
    const gameKey = root.dataset?.cameraKey;
    const previous = handoffs.get(environment);
    if (previous) {
        handoffs.delete(environment);
        environment.removeEventListener?.('pagehide', previous.release);
        if (gameKey && previous.gameKey === gameKey) {
            ({context, master, crowd, effects, players, stadium, recordings, fieldRecordings,
                playerRecordings, contactRecordings, ballRecordings, stadiumRecordings, ambienceActive} = previous);
        } else closeGraph(previous);
    }
    const voices = new Set();
    const supported = Boolean(environment.AudioContext || environment.webkitAudioContext);
    const controls = [...new Set([root.querySelector('[data-sound-toggle]'),root.querySelector('[data-intro-sound-toggle]')].filter(Boolean))];
    let breakMusic;
    const entranceTrack=recordedStadiumMusic(environment,`${(root.dataset?.stadiumAudioBase || '/audio/stadium/v1').replace(/\/$/,'')}/entrance.mp3`);
    const music = recordedStadiumMusic(environment,`${(root.dataset?.stadiumAudioBase || '/audio/stadium/v1').replace(/\/$/,'')}/anthem.mp3`);
    const label = () => { for (const control of controls) { control.textContent = !supported ? 'Sound unavailable' : settings.enabled ? 'Sound on' : 'Sound muted'; control.setAttribute('aria-pressed',String(settings.enabled)); control.disabled = !supported; } };
    const levels = () => {
        entranceTrack.setLevel(settings.enabled ? settings.master * settings.stadium : 0);
        music.setLevel(settings.enabled ? settings.master * settings.stadium : 0);
        breakMusic?.setLevel(settings.enabled ? settings.master * settings.stadium : 0);
        if (!context) return;
        master.gain.setTargetAtTime(settings.enabled && (active || ambienceActive) ? settings.master : 0,context.currentTime,.04);
        crowd.gain.value = settings.crowd; effects.gain.value = settings.effects; players.gain.value = settings.players; stadium.gain.value = settings.stadium;
        recordings?.setActive(settings.enabled && (active || ambienceActive));
        fieldRecordings?.setActive(settings.enabled && active);
        contactRecordings?.setActive(settings.enabled && active && settings.effects > 0);
        ballRecordings?.setActive(settings.enabled && active && settings.effects > 0);
        stadiumRecordings?.setActive(settings.enabled && (active || ambienceActive) && settings.stadium > 0);
        playerRecordings?.setActive(settings.enabled && active && settings.players > 0);
    };
    const track = (source, gain) => {
        voices.add(source); source.onended = () => { voices.delete(source); source.disconnect(); gain.disconnect(); };
    };
    const noise = (seconds, destination, intensity, cutoff = 1000) => {
        if (!context || disposed) return;
        const buffer = context.createBuffer(1,Math.ceil(context.sampleRate * seconds),context.sampleRate), data = buffer.getChannelData(0);
        let smooth = 0;
        for (let i=0;i<data.length;i++) { smooth = smooth * .7 + (Math.random() * 2 - 1) * .3; data[i] = smooth; }
        const source = context.createBufferSource(), filter = context.createBiquadFilter(), gain = context.createGain();
        source.buffer = buffer; filter.type = 'lowpass'; filter.frequency.value = cutoff;
        gain.gain.setValueAtTime(.001,context.currentTime); gain.gain.linearRampToValueAtTime(intensity,context.currentTime + Math.min(.15,seconds/4)); gain.gain.exponentialRampToValueAtTime(.001,context.currentTime + seconds);
        source.connect(filter); filter.connect(gain); gain.connect(destination); track(source,gain);
        source.onended = () => { voices.delete(source); source.disconnect(); filter.disconnect(); gain.disconnect(); };
        source.start(); source.stop(context.currentTime + seconds);
    };
    const tone = (frequency, seconds, delay = 0, intensity = .15, type = 'sine') => {
        const source = context.createOscillator(), gain = context.createGain(), start = context.currentTime + delay;
        source.type = type; source.frequency.value = frequency;
        gain.gain.setValueAtTime(.001,start); gain.gain.linearRampToValueAtTime(intensity,start+.015); gain.gain.exponentialRampToValueAtTime(.001,start+seconds);
        source.connect(gain); gain.connect(stadium); track(source,gain); source.start(start); source.stop(start+seconds);
    };
    const prepare = () => {
        if (!supported || disposed || !settings.enabled) return;
        try {
            if (!context) {
                context = new (environment.AudioContext || environment.webkitAudioContext)();
                master = context.createGain(); master.gain.value = 0; crowd = context.createGain(); effects = context.createGain(); players = context.createGain(); stadium = context.createGain();
                crowd.connect(master); effects.connect(master); players.connect(master); stadium.connect(master); master.connect(context.destination);
                recordings = recordedCrowd(context,crowd,environment,root.dataset?.crowdAudioBase);
                recordings.load();
                fieldRecordings = recordedFieldEffects(context,effects,environment,root.dataset?.refereeAudioBase);
                fieldRecordings.load();
                contactRecordings = recordedContactEffects(context,effects,environment,root.dataset?.contactAudioBase);
                contactRecordings.load();
                ballRecordings = recordedBallEffects(context,effects,environment,root.dataset?.ballAudioBase);
                ballRecordings.load();
                stadiumRecordings = recordedStadiumReactions(context,stadium,environment,root.dataset?.stadiumAudioBase);
                stadiumRecordings.load();
                playerRecordings = recordedPlayerVoices(context,players,environment,root.dataset?.playerAudioBase);
                playerRecordings.load();
                levels();
            }
            if (context.state === 'suspended') context.resume().then(() => { if (!disposed) levels(); }).catch(() => {});
            return Promise.all([recordings?.load(),fieldRecordings?.load(),playerRecordings?.load(),contactRecordings?.load(),ballRecordings?.load(),stadiumRecordings?.load()]);
        } catch { /* Gameplay continues if audio cannot start. */ }
    };
    const play = cue => {
        if (!active || !settings.enabled || context?.state !== 'running') return;
        switch (cue.type) {
            case 'cadence': playerRecordings?.play(cue.mood); break;
            case 'snap': noise(.09,effects,.18,1800); break;
            case 'throw': noise(.2,effects,.11,2400); break;
            case 'kick':
            case 'catch':
            case 'ball_ground': ballRecordings?.play(cue.type,(Number(root.dataset?.playNumber)||0) + Math.round(cue.time*100)); break;
            case 'pad':
            case 'tackle':
            case 'landing': contactRecordings?.play(cue.type,(Number(root.dataset?.playNumber)||0) + Math.round(cue.time*100)); break;
            case 'whistle': fieldRecordings?.playWhistle(cue.mood || 'short',Number(root.dataset?.playNumber)||0); break;
            case 'crowd': recordings?.play(cue.mood,{variant:Number(root.dataset?.playNumber)||0}); break;
            case 'first_down': [392,494,587].forEach((f,i)=>tone(f,.24,i*.13,.12,'triangle')); break;
            case 'touchdown':
            case 'home_turnover': stadiumRecordings?.play(cue.type); break;
            case 'away_score': tone(220,.4,0,.08,'triangle'); break;
            case 'third_down': stadiumRecordings?.play(cue.type); recordings?.play('cheer',{seconds:3.5,level:.65,variant:Number(root.dataset?.playNumber)||0}); break;
            case 'quarter': tone(750,.55,0,.12); break;
            case 'final': tone(750,.4,0,.12); tone(750,.4,.5,.12); tone(750,.7,1,.12); break;
        }
    };
    const save = () => { try { environment.localStorage.setItem(key,JSON.stringify(settings)); } catch { /* Optional persistence. */ } levels(); label(); root.querySelectorAll('[data-sound-volume]').forEach(input => { input.value = Math.round(settings[input.dataset.soundVolume] * 100); }); };
    controls.forEach(control => control.addEventListener('click', () => { settings.enabled = !settings.enabled; save(); if (settings.enabled) prepare(); }));
    root.querySelectorAll('[data-sound-volume]').forEach(input => {
        input.value = Math.round(settings[input.dataset.soundVolume] * 100);
        input.addEventListener('input', () => { settings[input.dataset.soundVolume] = Number(input.value)/100; save(); prepare(); });
    });
    const unlock = () => { prepare(); music.unlock(); entranceTrack.unlock(); breakMusic?.unlock(); }; root.addEventListener('pointerdown',unlock); root.addEventListener('keydown',unlock); label();
    return {
        play, prepare,
        whenReactionFinished() { return stadiumRecordings?.whenFinished() ?? Promise.resolve(); },
        startBreakMusic(kind) {
            breakMusic?.dispose();
            const file = {drive:'drive-break.mp3',quarter:'quarter-break.mp3',halftime:'halftime-break.mp3'}[kind];
            if(!file)return;
            breakMusic = recordedStadiumMusic(environment,`${(root.dataset?.stadiumAudioBase || '/audio/stadium/v1').replace(/\/$/,'')}/${file}`);
            levels();breakMusic.request();
        },
        unlockBreakMusic() { breakMusic?.unlock(); },
        finishBreakMusic(seconds=.6) { return breakMusic?.finish(seconds) ?? Promise.resolve(); },
        startEntranceMusic() { levels();entranceTrack.request(); },
        finishEntranceMusic(seconds=.6) { return entranceTrack.finish(seconds); },
        startAnthem(callbacks) { levels(); music.request(callbacks); },
        finishAnthem(seconds) { return music.finish(seconds); },
        stopPlayerVoices() { playerRecordings?.stop(); },
        setPlayback(value, ambience) {
            // Update both states before touching sources: no false/false transition.
            if (active === value && ambienceActive === ambience) return;
            const wasActive = active;
            active = value; ambienceActive = ambience;
            if (active || ambienceActive) prepare();
            if (wasActive && !active) {
                recordings?.stopReactions();
                for (const source of voices) { try { source.stop(); } catch { /* Already ended. */ } }
            }
            levels();
        },
        setActive(value) { if (active === value) return; active = value; if (active) prepare(); else { recordings?.stopReactions(); for (const source of voices) { try { source.stop(); } catch { /* Already ended. */ } } } levels(); },
        setAmbienceActive(value) { if (ambienceActive === value) return; ambienceActive = value; if (ambienceActive) prepare(); levels(); },
        dispose(handoff = false) {
            if (disposed) return;
            disposed = true; entranceTrack.dispose(); music.dispose(); breakMusic?.dispose();
            root.removeEventListener('pointerdown',unlock); root.removeEventListener('keydown',unlock);
            for (const source of voices) { try { source.stop(); } catch { /* Already ended. */ } }
            const graph = {gameKey, context, master, crowd, effects, players, stadium, recordings,
                fieldRecordings, playerRecordings, contactRecordings, ballRecordings, stadiumRecordings,
                ambienceActive: active || ambienceActive};
            if (handoff && gameKey && context) {
                recordings?.stopReactions();
                fieldRecordings?.setActive(false); playerRecordings?.setActive(false);
                contactRecordings?.setActive(false); ballRecordings?.setActive(false); stadiumRecordings?.setActive(false);
                graph.release = () => {
                    if (handoffs.get(environment) !== graph) return;
                    handoffs.delete(environment); closeGraph(graph);
                };
                handoffs.set(environment, graph);
                environment.addEventListener?.('pagehide', graph.release, {once: true});
            } else closeGraph(graph);
        },
    };
}
