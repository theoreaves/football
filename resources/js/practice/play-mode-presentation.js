import {createExhibitionFlowTransport} from '../arcade/exhibition-flow.js';

// Claim before audio starts, not when it finishes: reload/closing a tab mid-intro
// must not repeat the entrance or anthem. Ordinary Coach uses its existing path.
export function claimPlayOpening({flow, version, key, storage}) {
    if (!flow?.enabled || !flow.opening_enabled || flow.intro_seen || Number(version) !== 0) return false;
    try {
        if (!storage || storage.getItem(key)) return false;
        storage.setItem(key, 'claimed');
        return true;
    } catch { return false; }
}

export function watchMayAdvance({flow, recorded, completed, ready, visible, busy, dialogOpen, paused = false}) {
    return Boolean(flow?.enabled && flow.defense_mode === 'watch' && flow.can_advance
        && !paused && !flow.required_decision && recorded && completed && ready && visible && !busy && !dialogOpen);
}

export function shouldMountCoachWizard(flow) {
    // Watch's CPU chooses its own plays. Human special-team calls remain in
    // the existing Coach interface; ordinary Coach is entirely unchanged.
    return !flow?.enabled || flow.defense_mode !== 'watch' || ((flow.required_decision === 'special' || flow.phase === 'special') && !flow.can_advance);
}

// Only canonical football forms are routed through the Play command boundary.
// Highlight saves, dialog closes and ordinary Coach navigation remain untouched.
export function isPlayFootballForm(form, base) {
    return form?.method?.toLowerCase() === 'post'
        && new URL(form.getAttribute('action') || base, base).origin === new URL(base).origin
        && new URL(form.getAttribute('action') || base, base).pathname === new URL(base).pathname.replace(/\/$/, '') + '/play';
}

// Coach creates these two bracketed fields dynamically. Preserve their PHP-form
// structure when using JSON, without a generic untrusted object-path setter.
export function serializePlayForm(entries) {
    const fields = {};
    const forbidden = new Set(['__proto__', 'prototype', 'constructor']);
    for (const [name, value] of entries) {
        if (typeof name !== 'string' || (value !== null && typeof value !== 'string')) throw new Error('Unsupported play form value.');
        if (name === '_token') continue;
        const personnel = /^one_play\[(home|away)\]\[([A-Za-z][A-Za-z0-9_]*)\]$/.exec(name);
        const teamPlay = /^team_play\[(home|away)\]$/.exec(name);
        if (personnel) {
            const [, side, role] = personnel;
            if (forbidden.has(role)) throw new Error('Unsafe play form field.');
            fields.one_play ??= {};
            fields.one_play[side] ??= {};
            fields.one_play[side][role] = value;
        } else if (teamPlay) {
            fields.team_play ??= {};
            fields.team_play[teamPlay[1]] = value;
        } else {
            if (forbidden.has(name) || /[\[\]]/.test(name) || ['one_play', 'team_play'].includes(name)) throw new Error('Unsupported play form field.');
            fields[name] = value;
        }
    }
    return fields;
}

export function mountPlayPresentation(root, flow) {
    if (!flow?.enabled) return {tick() {}, dispose() {}};
    let disposed = false, busy = false, completed = false, blocked = false, viewReady = false, watchPaused = false;
    const status = root.querySelector('[data-play-flow-status]');
    const retry = root.querySelector('[data-play-flow-retry]');
    const pause = root.querySelector('[data-play-watch-pause]');
    const controls = [...root.querySelectorAll('[data-play-flow-action], [data-play-defense]')];
    const message = text => { if (status) status.textContent = text; };
    let storage;
    try { storage = localStorage; } catch { /* Transport warns; don't silently resend. */ }
    const transport = createExhibitionFlowTransport({
        url: root.dataset.arcadeFlowUrl, origin: window.location.origin, storage,
        key: `arcade-flow:${root.dataset.arcadeFlowUrl}`,
        onWarning: message,
        request: async (url, body) => {
            const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{
                'Content-Type':'application/json', Accept:'application/json',
                'X-CSRF-TOKEN':root.querySelector('[name="_token"]')?.value || document.querySelector('meta[name="csrf-token"]')?.content || '',
            }, body});
            const data = await response.json();
            if (!response.ok) { const error = new Error(data.message || 'Decision was not accepted. Reload the saved game.'); error.status=response.status; throw error; }
            return data;
        },
    });
    const setBusy = value => { busy=value; if(pause)pause.disabled=value; controls.forEach(button => { button.disabled=value; }); };
    const send = async (action, fields = {}) => {
        if (busy || disposed || (blocked && action !== 'retry')) return;
        setBusy(true); message('Saving decision…');
        try {
            if (action !== 'retry') transport.prepare({...fields, action, version:Number(root.dataset.playNumber), flow_revision:flow.revision, state_hash:flow.state_hash});
            const data = await transport.submit();
            if (disposed) return;
            const nextFlow=data.flow || flow;
            // A newly recorded CPU play must be watched before returning to the
            // next Arcade opportunity. Backend chooses that authoritative URL.
            const destination=data.navigation_url || data.navigate_url || data.redirect_url || data.url
                || (data.play ? nextFlow.coach_url + (nextFlow.coach_url.includes('?') ? '&' : '?') + 'watch=1'
                    : nextFlow.phase === 'offense' ? nextFlow.arcade_url : nextFlow.coach_url);
            window.location.assign(destination);
        } catch (error) {
            const rejected=transport.reject(error);
            message(`${error.message || 'Could not confirm this decision.'} ${rejected ? 'Reload before continuing.' : 'Retry sends the identical command, not another play.'}`);
            if(retry) retry.hidden=rejected;
            // Do not permit another decision after uncertain/rejected state.
            busy=false; blocked=true;
        }
    };
    const base = new URL(window.location.href); base.search=''; base.hash='';
    const submit = event => {
        if (!isPlayFootballForm(event.target, base.href)) return;
        if (event.defaultPrevented) { event.stopImmediatePropagation(); return; }
        event.preventDefault(); event.stopImmediatePropagation();
        const data=new FormData(event.target);
        if(event.submitter?.name) data.set(event.submitter.name,event.submitter.value);
        try {
            const fields=serializePlayForm(data);
            send(fields.action || 'play', fields);
        } catch (error) { message(`Could not prepare this decision: ${error.message}`); }
    };
    // Register before gameNavigation, but in bubble phase so existing personnel
    // validation and queued-play capture guards still run first.
    root.addEventListener('submit',submit);
    const clicks=[];
    controls.forEach(button => {
        const handler=()=>{ if (!viewReady) return; return send(button.dataset.playDefense ? 'preferences' : button.dataset.playFlowAction,
            button.dataset.playDefense ? {defense_mode:button.dataset.playDefense} : {}); };
        button.addEventListener('click',handler); clicks.push([button,handler]);
    });
    const pauseClick=()=>{
        if(busy || blocked || disposed || transport.pending) return;
        watchPaused=!watchPaused;
        if(pause){pause.textContent=watchPaused?'Resume Watch':'Pause after this play';pause.setAttribute('aria-pressed',String(watchPaused));}
        message(watchPaused?'Watch paused: the current animation may finish. Choose another defense mode or resume when ready.':'Watch resumed. The next play starts after the current result and notices finish.');
    };
    pause?.addEventListener('click',pauseClick);
    const retryClick=()=>send('retry'); retry?.addEventListener('click',retryClick);
    if(transport.pending){blocked=true;message('An earlier decision needs confirmation. Retry it before continuing.');controls.forEach(button=>{button.disabled=true;});if(retry)retry.hidden=false;}
    return {
        tick(view) {
            completed ||= view.completed;
            viewReady = !view.dialogOpen && !busy && !blocked && (view.ready || (view.recorded && completed && view.completed && view.decisionWindow) || (!view.recorded && Number(root.dataset.playNumber) === 0));
            if(pause)pause.disabled=busy || blocked || Boolean(transport.pending);
            controls.forEach(button => { button.disabled = !viewReady || button.dataset.playDefense === flow.defense_mode; });
            if (flow.phase === 'offense' && flow.defense_mode === 'watch' && view.recorded && completed && view.ready && viewReady && view.visible && !watchPaused) {
                busy=true; window.location.assign(flow.arcade_url); return;
            }
            if (watchMayAdvance({flow, ...view, completed, paused:watchPaused, busy:busy || Boolean(transport.pending)})) send('advance');
        },
        dispose(){disposed=true;root.removeEventListener('submit',submit);clicks.forEach(([button,handler])=>button.removeEventListener('click',handler));retry?.removeEventListener('click',retryClick);pause?.removeEventListener('click',pauseClick);},
    };
}

export function requiredDecisionReady({action, requiredDecision, dialogOpen, blocked=false}) {
    return !blocked && ['penalty','coin','ot_call','ot_choice'].includes(action)
        && action===requiredDecision && Boolean(dialogOpen);
}
