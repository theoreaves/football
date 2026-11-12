import {createFullTeamPlay,stepFullTeamPlay} from './full-team.js';
import {exhibitionReservation,exhibitionCanPass,exhibitionControlHint,exhibitionLiveLabel} from './exhibition-play-selection.js';
import {createExhibitionFlowTransport, exhibitionStateLabel, exhibitionClockLabel, canonicalExhibitionNotices} from './exhibition-flow.js';
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

export function mountExhibition(root, {sharedHost=null, onCanonical=null, inputEnabled=()=>true, flowEnabled=inputEnabled} = {}) {
    if (!root || root.dataset.mounted) return;
    root.dataset.mounted = 'true';
    const data = JSON.parse(root.dataset.exhibition), find = name => root.querySelector(`[data-exhibition-${name}]`);
    if (data.replayOnly === true) return mountExhibitionHistory(root, data);
    const host = sharedHost?.host ?? root.querySelector('[data-arcade-field]'), status = find('status');
    const buttons = Object.fromEntries(['reserve','start','pause','cancel','submit','replay'].map(key => [key,find(key)]));
    const abort = new AbortController(), options = {signal: abort.signal};
    let official = data.state, allowed = data.available, busy = false, stopped = false;
    let flow = data.flow, flowBlocked = false;
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
        if (!response.ok) { const error = new Error(result.message || `Request failed (${response.status}). Reload to reconcile the saved game before starting another play.`); error.status=response.status; throw error; }
        return result;
    }
    const transport = createExhibitionTransport({storage, startUrl:data.startUrl, origin:window.location.origin, onWarning:message=>{storageWarning=message; status.textContent=message;}, key:`arcade-exhibition:${data.startUrl}`, request});
    const flowTransport = data.flowUrl ? createExhibitionFlowTransport({url:data.flowUrl, origin:window.location.origin, storage, key:`arcade-flow:${data.flowUrl}`, request, onWarning:message=>{storageWarning=message;}}) : null;
    function showNotices(result) {
        if (find('notices')) find('notices').textContent = canonicalExhibitionNotices(result).join('\n');
    }
    function flowGuidance() {
        if (flowBlocked) return 'Reload saved state to reconcile this decision before continuing.';
        if (flowTransport?.pending) return 'An unconfirmed game decision is saved. Retry the same decision before continuing.';
        if (!flow?.enabled) return 'Choose your team and defense preference, then Start Play mode.';
        if (flow.phase === 'final') return 'Final whistle. Your exhibition is complete. Recorded replays remain available.';
        if (flow.required_decision || (flow.phase === 'special' && !flow.can_advance)) return 'A football decision is required. Continue in Coach to choose, then return to Arcade.';
        if (flow.phase === 'special') return 'Opponent special teams play. Watch the next simulated play or Fast Sim to your next decision.';
        if (flow.phase === 'defense') return flow.defense_mode === 'coach' ? 'Opponent possession. Continue in Coach to call your defense.' : 'Opponent possession. Watch the next simulated play or Fast Sim to your next decision.';
        return 'Choose one of five plays, then Reserve play to load the field.';
    }
    function flowHud() {
        if (!flow || !find('preferences')) return;
        const locked=!!replay?.active || !inputEnabled() || sharedHost?.staging || sharedHost?.cameraDirector.transitioning || sharedHost?.staging || busy || !!transport.pending || !!flowTransport?.pending || flowBlocked;
        find('preferences').disabled=locked || flow.phase==='final';
        find('preferences').textContent=flow.enabled?'Apply defense choice':'Start Play mode';
        find('side').disabled=locked || flow.enabled;
        find('defense').disabled=locked || flow.phase==='final';
        find('advance').disabled=locked || !flow.can_advance || flow.defense_mode==='coach';
        find('fast').disabled=locked || !flow.can_fast;
        find('timeout').disabled=locked || !flow.can_timeout;
        find('spike').disabled=locked || !flow.can_spike;
        find('retry-flow').hidden=!flowTransport?.pending;
        find('retry-flow').disabled=busy || !!transport.pending;
        find('flow-status').textContent=flowGuidance();
        find('clock').textContent=`${exhibitionClockLabel(official)} · Timeouts Home ${official.home_timeouts ?? official.timeouts?.home ?? '—'} / Away ${official.away_timeouts ?? official.timeouts?.away ?? '—'}`;
    }
    if (flow) {
        if(find('side')) find('side').value=flow.human_side || official.possession;
        if(find('defense')) find('defense').value=flow.defense_mode || 'coach';
        showNotices({flow});
    }
    if (!transport.pending && data.pendingSession) transport.reserve(data.pendingSession);
    if (transport.pending) {
        recovered = true;
        if (transport.pending.body) recording = JSON.parse(transport.pending.body).recording;
        say(transport.pending.body ? 'An unconfirmed recorded play is saved. Retry submission with the same result, or reload to reconcile. Do not play it again.' : 'An unfinished reserved play exists. Cancel it before choosing a new play.');
    }
    function hud() {
        const empty = find('empty');
        if (flow) stopped=!flow.enabled || flow.phase!=='offense' || flowBlocked || !!flowTransport?.pending;
        empty.hidden = !!sharedHost || Boolean(state && view);
        if (!empty.hidden) empty.textContent = recovered
            ? (transport.pending?.body ? 'Your recorded play is recovered. Submit / retry same result, or review its replay.' : 'A reserved play was recovered. Choose Cancel reserved play before reserving a new play.')
            : flow ? flowGuidance() : stopped || !allowed ? 'This drive needs a Coach decision. Choose Continue in Coach.' + (recording ? ' You can still replay the recorded play.' : '')
            : 'Choose one of five plays, then Reserve play to load the field.' + (recording ? ' Replay recorded play shows the previous recording.' : '');
        const snapshot = transport.pending?.session.prepared_state || official;
        const clock = Number(snapshot.clock || 0);
        find('score').textContent = `Home ${snapshot.home_score ?? 0} · Away ${snapshot.away_score ?? 0}`;
        find('state').textContent = `Q${snapshot.quarter} · ${Math.floor(clock/60)}:${String(Math.floor(clock%60)).padStart(2,'0')} · Down ${snapshot.down} & ${snapshot.distance} · Ball ${snapshot.spot}`;
        if (flow) find('state').textContent=exhibitionStateLabel(snapshot);
        find('live').textContent = exhibitionLiveLabel(state);
        buttons.reserve.disabled = !!replay?.active || !inputEnabled() || sharedHost?.staging || sharedHost?.cameraDirector.transitioning || busy || !!transport.pending || !allowed || stopped;
        buttons.start.disabled = !inputEnabled() || sharedHost?.cameraDirector.transitioning || sharedHost?.staging || busy || recovered || !state || !['ready','paused'].includes(state.status) || replay?.active;
        buttons.start.textContent = state?.status === 'paused' ? 'Resume play' : 'Start play';
        buttons.pause.disabled = state?.status !== 'running' || busy;
        buttons.cancel.disabled = !!replay?.active || busy || !transport.pending;
        buttons.submit.disabled = !!replay?.active || busy || !transport.pending?.body;
        buttons.replay.disabled = sharedHost?.staging || sharedHost?.cameraDirector.transitioning || !recording || state?.status === 'running' || busy;
        find('kind').disabled = find('tempo').disabled = !!replay?.active || !!transport.pending || busy || stopped || !allowed;
        find('coach').hidden = false;
        flowHud();
    }
    const stepper = createFixedStepper(() => {
        if (!state || state.status !== 'running' || replay?.active) return;
        (state.fullTeam ? stepFullTeamPlay : state.mode === 'passing' ? stepPassingDrill : stepDrill)(state, input.sample());
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
        if (!inputEnabled() || sharedHost?.cameraDirector.transitioning || sharedHost?.staging || busy || recovered || replay?.active || !state || !['ready','paused'].includes(state.status)) return;
        startDrill(state); stepper.reset(); lastTime=null; host.focus({preventScroll:true});
        say(`Play live. Move ${state.direction === 1 ? 'right' : 'left'} toward the end zone. ${exhibitionControlHint(state)}${state.mode === 'passing' ? ' Click a target to pass, or hold and drag to aim. Stay behind the cyan line to throw. After a catch, run with the receiver.' : ''}`); hud();
    }
    function clearView() { input?.dispose(); observer?.disconnect(); view?.dispose(); input=null; view=null; }
    function setup(session) {
        clearView(); recovered = false; completed = false; aim = null;
        const config = session.setup;
        state = config.fullTeam ? createFullTeamPlay(config) : config.mode === 'passing' ? createPassingDrill(config) : createDrill(config);
        recorder = createDrillRecording(state, {appearance:config.appearance});
        view = createArcadeView(host, config, {sharedHost});
        input = createArcadeInput(root, host, {start,pause,retry:pause, enabled:()=>inputEnabled() && !sharedHost?.cameraDirector.transitioning && !sharedHost?.staging && !replay?.active && !busy,
            canPass:()=>exhibitionCanPass(state),
            aimAt:(x,y)=>view.groundPoint(x,y),onAim:point=>{aim=point;}});
        if(!sharedHost) { observer = new ResizeObserver(()=>view.resize()); observer.observe(host); } view.render(state); sharedHost?.stageNextFrame?.();
    }
    function reconcile(result) {
        const before=official;
        if (result.state) official = result.state;
        if (result.flow) { flow=result.flow; if(find('defense')) find('defense').value=flow.defense_mode; if(find('side')) find('side').value=flow.human_side || official.possession; }
        if (flow) showNotices({...result,before,flow,state:official});
        if (typeof result.available === 'boolean') allowed = result.available;
        if (result.coach_url) find('coach').href = result.coach_url;
        if (!flow) stopped ||= !canContinueExhibitionDrive(official, allowed, driveSide);
    }
    async function action(callback) { if(busy || replay?.active) return; busy=true; pause(); try { await callback(); } catch(error) {say(error.message);} finally {busy=false;hud();} }
    async function flowAction(actionName, extra={}, {onPrepared=()=>{},onPrepareError=()=>{},onDeclined=()=>{}}={}) {
        if (!flowTransport || busy || replay?.active || !flowEnabled(actionName || flowTransport?.pending?.action) || transport.pending || flowBlocked || (actionName && flowTransport.pending)) {onDeclined();return;}
        return action(async()=>{
            let prepared=false;
            try {
                if (actionName) flowTransport.prepare({action:actionName,version:official.version,flow_revision:flow.revision,state_hash:flow.state_hash,...extra});
                prepared=true;onPrepared();
                const result=await flowTransport.submit(); if(disposed)return; reconcile(result);
                if(onCanonical) await onCanonical(result,{kind:actionName || "retry"});
                say('Game decision saved. ' + flowGuidance());
                const destination=result.navigation_url || (actionName==='advance' ? result.watch_url : null);
                if (destination && !sharedHost) {
                    const target=new URL(destination,window.location.origin);
                    if(target.origin===window.location.origin && target.pathname!==window.location.pathname) window.location.assign(target.href);
                }
            } catch(error) { if(!prepared)onPrepareError(error);if(flowTransport.reject(error)) flowBlocked=true; throw error; }
        });
    }
    if (flowTransport) {
        find('preferences')?.addEventListener('click',()=>flowAction('preferences',{human_side:find('side').value,defense_mode:find('defense').value}),options);
        for (const name of ['advance','fast','timeout','spike']) find(name)?.addEventListener('click',()=>flowAction(name),options);
        find('retry-flow')?.addEventListener('click',()=>flowAction(null),options);
    }
    buttons.reserve.addEventListener('click',()=>action(async()=>{
        if (!inputEnabled() || sharedHost?.staging || sharedHost?.cameraDirector.transitioning || transport.pending || !allowed || stopped) return;
        if (flow && (!flow.enabled || flow.phase!=='offense' || flowBlocked || flowTransport?.pending)) return;
        const result = await request(data.startUrl, JSON.stringify(exhibitionReservation({version:official.version,call:find('kind').value,tempo:find('tempo').value})));
        if(disposed){if(result.session)transport.reserve(result.session);return;}
        reconcile(result);
        if (!result.session) {say(noSnapNotice(result.play, stopped)); if(onCanonical) await onCanonical(result,{kind:'no-snap'}); return;}
        transport.reserve(result.session);
        try { setup(result.session); } catch (error) { clearView(); state=null; recovered=true; throw new Error(`Field unavailable: ${error.message}. Cancel the reserved play or reload to recover.`); }
        say('Play reserved with the saved roster. Start when ready. '+exhibitionControlHint(state)+' Pausing and replaying do not drain the official clock.');
    }),options);
    buttons.start.addEventListener('click',start,options);
    buttons.pause.addEventListener('click',pause,options);
    buttons.cancel.addEventListener('click',()=>action(async()=>{
        const result = await transport.cancel(); if(disposed)return; if(result)reconcile(result);
        clearView(); state=null; recovered=false; if(onCanonical) await onCanonical(result,{kind:'cancel'}); say(result?.cancelled ? 'Reserved play cancelled. No play or clock change was committed.' : 'Session reconciled with the saved game. Check the official state before reserving another play.');
    }),options);
    buttons.submit.addEventListener('click',()=>action(async()=>{
        const result = await transport.submit(); if(disposed)return; reconcile(result); recovered=false;
        // The HUD now describes the next snap. Do not leave the completed play's
        // old LOS/first-down markers beneath it. Keep its exact recording for Replay.
        clearView(); state=null; aim=null;
        if(onCanonical) await onCanonical(result,{kind:'submit'});
        say(flow ? 'Recorded play saved. ' + flowGuidance() : stopped ? 'Recorded play saved. This offensive drive has ended or needs a game decision. Continue in Coach.' : 'Recorded play saved. Choose the next play when ready.');
        // Keep exact local recording for replay; the server never supplies a replacement simulation.
    }),options);
    replay = createReplayPanel(root,{sharedHost,onOpen:pause,onClose(){lastTime=null;stepper.reset();hud();}});
    buttons.replay.addEventListener('click',()=>{try{replay.open(recording);}catch(error){say(error.message);}},options);
    function frame(time) {
        if(disposed)return; if(!root.isConnected){dispose();return;}
        const delta=lastTime===null?0:(time-lastTime)/1000;lastTime=time;
        if(replay.active){stepper.reset();replay.advance(delta);}else{stepper.advance(delta);view?.render(state,{aim});} hud();
        if(!sharedHost) frameId=requestAnimationFrame(frame);
    }
    function dispose(){if(disposed)return;disposed=true;cancelAnimationFrame(frameId);clearView();replay.dispose();abort.abort();delete root.dataset.mounted;}
    document.addEventListener('livewire:navigating',dispose,options);window.addEventListener('pagehide',dispose,options);
    hud();if(!sharedHost) frameId=requestAnimationFrame(frame);
    Object.assign(dispose,{tick:frame,pause,flowAction,reconcile,openReplay:value=>replay.open(value)});
    Object.defineProperties(dispose,{busy:{get:()=>busy},hasLive:{get:()=>!!state},pendingFlow:{get:()=>!!flowTransport?.pending},pending:{get:()=>!!transport.pending || !!flowTransport?.pending},replaying:{get:()=>!!replay?.active}});
    return dispose;
}

window.addEventListener('pageshow', event => {if(event.persisted && !document.querySelector('[data-persistent-presentation]')) mountExhibition(document.querySelector('[data-arcade-exhibition]'));});
