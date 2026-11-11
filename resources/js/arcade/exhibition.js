import {createDrill, startDrill, pauseDrill, stepDrill, STEP_SECONDS} from './simulation.js';
import {createPassingDrill, stepPassingDrill} from './passing.js';
import {createArcadeView} from './view.js';
import {createArcadeInput} from './input.js';
import {createFixedStepper} from './loop.js';
import {createDrillRecording} from './recording.js';
import {createReplayPanel} from './replay-panel.js';
import {mountExhibitionHistory} from './exhibition-history.js';
import {createExhibitionTransport, canContinueExhibitionDrive, noSnapNotice} from './exhibition-session.js';
import './style.css';
import './replay-panel.css';

export function mountExhibition(root) {
    if (!root || root.dataset.mounted) return;
    root.dataset.mounted = 'true';
    const data = JSON.parse(root.dataset.exhibition), find = name => root.querySelector(`[data-exhibition-${name}]`);
    if (data.replayOnly === true) return mountExhibitionHistory(root, data);
    const host = root.querySelector('[data-arcade-field]'), status = find('status');
    const buttons = Object.fromEntries(['reserve','start','pause','cancel','submit','replay'].map(key => [key,find(key)]));
    const abort = new AbortController(), options = {signal: abort.signal};
    let official = data.state, allowed = data.available, busy = false, stopped = false;
    let state = null, recorder = null, view = null, input = null, replay = null, aim = null, observer = null;
    let recording = data.lastRecording, frameId, lastTime = null, disposed = false, completed = false, recovered = false;
    const driveSide = official.possession;
    let storageWarning = '';
    const say = text => { status.textContent = `${text}${storageWarning ? ` ${storageWarning}` : ''}`; };
    let storage = null;
    try { storage = window.localStorage; } catch { /* Transport provides a recoverable warning. */ }
    async function request(url, body) {
        const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':data.csrf}, body});
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.message || `Request failed (${response.status}). Reload to reconcile the saved game before starting another play.`);
        return result;
    }
    const transport = createExhibitionTransport({storage, startUrl:data.startUrl, origin:window.location.origin, onWarning:message=>{storageWarning=message; status.textContent=message;}, key:`arcade-exhibition:${data.startUrl}`, request});
    if (!transport.pending && data.pendingSession) transport.reserve(data.pendingSession);
    if (transport.pending) {
        recovered = true;
        if (transport.pending.body) recording = JSON.parse(transport.pending.body).recording;
        say(transport.pending.body ? 'An unconfirmed recorded play is saved. Retry submission with the same result, or reload to reconcile. Do not play it again.' : 'An unfinished reserved play exists. Cancel it before choosing a new play.');
    }
    function hud() {
        const empty = find('empty');
        empty.hidden = Boolean(state && view);
        if (!empty.hidden) empty.textContent = recovered
            ? (transport.pending?.body ? 'Your recorded play is recovered. Submit / retry same result, or review its replay.' : 'A reserved play was recovered. Choose Cancel reserved play before reserving a new play.')
            : stopped || !allowed ? 'This drive needs a Coach decision. Choose Continue in Coach.' + (recording ? ' You can still replay the recorded play.' : '')
            : 'Choose Run or Pass, then Reserve play to load the field.' + (recording ? ' Replay recorded play shows the previous recording.' : '');
        const snapshot = transport.pending?.session.prepared_state || official;
        const clock = Number(snapshot.clock || 0);
        find('score').textContent = `Home ${snapshot.home_score ?? 0} · Away ${snapshot.away_score ?? 0}`;
        find('state').textContent = `Q${snapshot.quarter} · ${Math.floor(clock/60)}:${String(Math.floor(clock%60)).padStart(2,'0')} · Down ${snapshot.down} & ${snapshot.distance} · Ball ${snapshot.spot}`;
        find('live').textContent = state ? `${state.runner.name || 'Ball carrier'} · ${((state.runner.x-state.startX)*state.direction).toFixed(1)} yards · ${state.elapsed.toFixed(1)}s live` : '';
        buttons.reserve.disabled = busy || !!transport.pending || !allowed || stopped;
        buttons.start.disabled = busy || recovered || !state || !['ready','paused'].includes(state.status) || replay?.active;
        buttons.start.textContent = state?.status === 'paused' ? 'Resume play' : 'Start play';
        buttons.pause.disabled = state?.status !== 'running' || busy;
        buttons.cancel.disabled = busy || !transport.pending;
        buttons.submit.disabled = busy || !transport.pending?.body;
        buttons.replay.disabled = !recording || state?.status === 'running' || busy;
        find('kind').disabled = find('tempo').disabled = !!transport.pending || busy || stopped || !allowed;
        find('coach').hidden = false;
    }
    const stepper = createFixedStepper(() => {
        if (!state || state.status !== 'running' || replay?.active) return;
        (state.mode === 'passing' ? stepPassingDrill : stepDrill)(state, input.sample());
        recorder.capture(state);
        if (state.status === 'finished' && !completed) {
            completed = true; input.clear(); recording = recorder.export();
            if (state.result.reason === 'timeout') say('This play reached the local recording limit. No football result was saved. Cancel the session, then reserve a new play.');
            else {
                try { transport.prepare(recording); say(`${state.result.reason.replaceAll('_',' ')}. Review the actual replay, then submit this recorded play.`); }
                catch (error) { say(`Could not save the submission safely: ${error.message}. Keep this page open.`); }
            }
        }
    }, STEP_SECONDS);
    function pause() { if(state) pauseDrill(state); input?.clear(); stepper.reset(); lastTime = null; hud(); }
    function start() {
        if (busy || recovered || replay?.active || !state || !['ready','paused'].includes(state.status)) return;
        startDrill(state); stepper.reset(); lastTime=null; host.focus({preventScroll:true});
        say(`Play live. Move ${state.direction === 1 ? 'right' : 'left'} toward the end zone.${state.mode === 'passing' ? ' Click a target to pass, or hold and drag to aim. Stay behind the cyan line to throw. After a catch, run with the receiver.' : ''}`); hud();
    }
    function clearView() { input?.dispose(); observer?.disconnect(); view?.dispose(); input=null; view=null; }
    function setup(session) {
        clearView(); recovered = false; completed = false; aim = null;
        const config = session.setup;
        state = config.mode === 'passing' ? createPassingDrill(config) : createDrill(config);
        recorder = createDrillRecording(state, {appearance:config.appearance});
        view = createArcadeView(host, config);
        input = createArcadeInput(root, host, {start,pause,retry:pause, enabled:()=>!replay?.active && !busy,
            canPass:()=>state.mode==='passing' && state.status==='running' && state.ball.phase==='held' && state.ball.holderKey==='qb',
            aimAt:(x,y)=>view.groundPoint(x,y),onAim:point=>{aim=point;}});
        observer = new ResizeObserver(()=>view.resize()); observer.observe(host); view.render(state);
    }
    function reconcile(result) {
        if (result.state) official = result.state;
        if (typeof result.available === 'boolean') allowed = result.available;
        if (result.coach_url) find('coach').href = result.coach_url;
        stopped ||= !canContinueExhibitionDrive(official, allowed, driveSide);
    }
    async function action(callback) { if(busy) return; busy=true; pause(); try { await callback(); } catch(error) {say(error.message);} finally {busy=false;hud();} }
    buttons.reserve.addEventListener('click',()=>action(async()=>{
        const result = await request(data.startUrl, JSON.stringify({version:official.version,kind:find('kind').value,tempo:find('tempo').value}));
        reconcile(result);
        if (!result.session) {say(noSnapNotice(result.play, stopped)); return;}
        transport.reserve(result.session);
        try { setup(result.session); } catch (error) { clearView(); state=null; recovered=true; throw new Error(`Field unavailable: ${error.message}. Cancel the reserved play or reload to recover.`); }
        say('Play reserved with the saved roster. Start when ready. Pausing and replaying do not drain the official clock.');
    }),options);
    buttons.start.addEventListener('click',start,options);
    buttons.pause.addEventListener('click',pause,options);
    buttons.cancel.addEventListener('click',()=>action(async()=>{
        const result = await transport.cancel(); if(result)reconcile(result);
        clearView(); state=null; recovered=false; say(result?.cancelled ? 'Reserved play cancelled. No play or clock change was committed.' : 'Session reconciled with the saved game. Check the official state before reserving another play.');
    }),options);
    buttons.submit.addEventListener('click',()=>action(async()=>{
        const result = await transport.submit(); reconcile(result); recovered=false;
        // The HUD now describes the next snap. Do not leave the completed play's
        // old LOS/first-down markers beneath it. Keep its exact recording for Replay.
        clearView(); state=null; aim=null;
        say(stopped ? 'Recorded play saved. This offensive drive has ended or needs a game decision. Continue in Coach.' : 'Recorded play saved. Choose the next play when ready.');
        // Keep exact local recording for replay; the server never supplies a replacement simulation.
    }),options);
    replay = createReplayPanel(root,{onOpen:pause,onClose(){lastTime=null;stepper.reset();hud();}});
    buttons.replay.addEventListener('click',()=>{try{replay.open(recording);}catch(error){say(error.message);}},options);
    function frame(time) {
        if(disposed)return; if(!root.isConnected){dispose();return;}
        const delta=lastTime===null?0:(time-lastTime)/1000;lastTime=time;
        if(replay.active){stepper.reset();replay.advance(delta);}else{stepper.advance(delta);view?.render(state,{aim});} hud();
        frameId=requestAnimationFrame(frame);
    }
    function dispose(){if(disposed)return;disposed=true;cancelAnimationFrame(frameId);clearView();replay.dispose();abort.abort();delete root.dataset.mounted;}
    document.addEventListener('livewire:navigating',dispose,options);window.addEventListener('pagehide',dispose,options);
    hud();frameId=requestAnimationFrame(frame);return dispose;
}

window.addEventListener('pageshow', event => {if(event.persisted) mountExhibition(document.querySelector('[data-arcade-exhibition]'));});
