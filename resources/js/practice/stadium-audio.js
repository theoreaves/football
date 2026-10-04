import {soundSettings} from './sound-cues.js';

// Original synthesized sounds; no recordings or external downloads are required.
export function stadiumAudio(root, environment = window) {
    const key = 'football-sound-settings';
    let saved = {}; try { saved = JSON.parse(environment.localStorage.getItem(key) || '{}'); } catch { /* Defaults when storage is unavailable. */ }
    let settings = soundSettings(saved), context, master, crowd, effects, ambience, active = false, disposed = false;
    const voices = new Set();
    const supported = Boolean(environment.AudioContext || environment.webkitAudioContext);
    const control = root.querySelector('[data-sound-toggle]');
    const label = () => { if (control) { control.textContent = !supported ? 'Sound unavailable' : settings.enabled ? 'Sound on' : 'Sound muted'; control.setAttribute('aria-pressed',String(settings.enabled)); control.disabled = !supported; } };
    const levels = () => {
        if (!context) return;
        master.gain.setTargetAtTime(settings.enabled && active ? settings.master : 0,context.currentTime,.04);
        crowd.gain.value = settings.crowd; effects.gain.value = settings.effects;
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
        source.connect(gain); gain.connect(effects); track(source,gain); source.start(start); source.stop(start+seconds);
    };
    const prepare = () => {
        if (!supported || disposed || !settings.enabled) return;
        try {
            if (!context) {
                context = new (environment.AudioContext || environment.webkitAudioContext)();
                master = context.createGain(); master.gain.value = 0; crowd = context.createGain(); effects = context.createGain();
                crowd.connect(master); effects.connect(master); master.connect(context.destination);
                const buffer = context.createBuffer(1,context.sampleRate * 4,context.sampleRate), data = buffer.getChannelData(0);
                let smooth = 0; for (let i=0;i<data.length;i++) { smooth = .96 * smooth + .04 * (Math.random()*2-1); data[i] = smooth * (.65 + .25*Math.sin(i/context.sampleRate*3)); }
                ambience = context.createBufferSource(); ambience.buffer = buffer; ambience.loop = true; ambience.connect(crowd); ambience.start();
                levels();
            }
            if (context.state === 'suspended') context.resume().catch(() => {});
        } catch { /* Gameplay continues if audio cannot start. */ }
    };
    const play = cue => {
        if (!active || !settings.enabled || context?.state !== 'running') return;
        switch (cue.type) {
            case 'snap': noise(.09,effects,.18,1800); break;
            case 'throw': noise(.2,effects,.11,2400); break;
            case 'kick': noise(.17,effects,.45,350); tone(95,.13); break;
            case 'tackle': noise(.35,effects,.65,450); tone(65,.22); break;
            case 'whistle': tone(2300,.3,0,.09); tone(2550,.25,.025,.04); break;
            case 'crowd': noise(cue.mood === 'cheer' ? 2.6 : 1.8,crowd,cue.mood === 'cheer' ? 1.6 : .65,cue.mood === 'cheer' ? 2200 : 500); break;
            case 'first_down': [392,494,587].forEach((f,i)=>tone(f,.24,i*.13,.12,'triangle')); break;
            case 'touchdown': [392,494,587,784].forEach((f,i)=>tone(f,.45,i*.17,.17,'triangle')); break;
            case 'away_score': tone(220,.4,0,.08,'triangle'); break;
            case 'third_down': [110,110,165].forEach((f,i)=>tone(f,.25,i*.27,.18,'triangle')); noise(1.3,crowd,.6,1400); break;
            case 'quarter': tone(750,.55,0,.12); break;
            case 'final': tone(750,.4,0,.12); tone(750,.4,.5,.12); tone(750,.7,1,.12); break;
        }
    };
    const save = () => { try { environment.localStorage.setItem(key,JSON.stringify(settings)); } catch { /* Optional persistence. */ } levels(); label(); };
    control?.addEventListener('click', () => { settings.enabled = !settings.enabled; save(); if (settings.enabled) prepare(); });
    root.querySelectorAll('[data-sound-volume]').forEach(input => {
        input.value = Math.round(settings[input.dataset.soundVolume] * 100);
        input.addEventListener('input', () => { settings[input.dataset.soundVolume] = Number(input.value)/100; save(); prepare(); });
    });
    const unlock = () => prepare(); root.addEventListener('pointerdown',unlock); root.addEventListener('keydown',unlock); label();
    return {
        play, prepare,
        setActive(value) { if (active === value) return; active = value; if (active) prepare(); else { for (const source of voices) { try { source.stop(); } catch { /* Already ended. */ } } } levels(); },
        dispose() { disposed = true; root.removeEventListener('pointerdown',unlock); root.removeEventListener('keydown',unlock); try { ambience?.stop(); context?.close().catch(()=>{}); } catch { /* Already closed. */ } },
    };
}
