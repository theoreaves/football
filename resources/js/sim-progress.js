import {runCpuSimBatch} from './cpu-sim-batch.js';

let overlay;
let mounted = false;
let batchRunning = false;

export function hideSimProgress() {
    overlay?.remove();
    overlay = null;
}

export function showSimProgress(batch = false) {
    if (overlay?.isConnected) return;
    overlay = document.createElement('div');
    overlay.className = 'sim-progress';
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'polite');
    overlay.innerHTML = '<div class="sim-progress-card"><span class="sim-progress-spinner" aria-hidden="true"></span><strong>Simulating'+(batch ? ' CPU games' : ' game')+'…</strong><p>Please wait while the games are played and results are saved.</p></div>';
    (document.fullscreenElement || document.body).append(overlay);
}

export function mountSimProgress() {
    hideSimProgress();
    if (mounted) return;
    mounted = true;
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
        const batch = form.hasAttribute('data-sim-cpu');
        const quick = event.submitter?.name === 'quick_sim' && event.submitter.value === '1';
        if (!batch && !quick) return;
        if (overlay?.isConnected) { event.preventDefault(); return; }
        showSimProgress(batch);
        if (!batch) return;
        event.preventDefault();
        if (batchRunning) return;
        batchRunning = true;
        const button = event.submitter || form.querySelector('button');
        const initialDisabled = button?.disabled;
        if (button) {button.disabled = true;button.setAttribute('aria-busy','true');}
        const progressOverlay = overlay;
        const controller = new AbortController();
        const stop = () => controller.abort();
        window.addEventListener('pagehide',stop,{once:true});
        document.addEventListener('livewire:navigating',stop,{once:true});
        const payload = new FormData(form);
        let total = Number(form.dataset.simCount) || 0;
        const message = overlay.querySelector('p');
        message.textContent = `Completed 0 of ${total} games. Each game is saved before the next begins.`;
        runCpuSimBatch(async () => {
            const response = await fetch(form.action, {method:'POST',body:payload,credentials:'same-origin',signal:controller.signal,
                headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok) {
                let explanation = `Simulation stopped (HTTP ${response.status}).`;
                try {explanation = (await response.json()).message || explanation;} catch { /* HTML error pages are not inserted into the UI. */ }
                throw new Error(explanation);
            }
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Unable to read simulation progress. Reload the season and sign in again if needed.');
            return response.json();
        }, ({completed,remaining}) => {
            total = completed + remaining;
            message.textContent = `Completed ${completed} of ${total} games · ${remaining} remaining.`;
        }).then(result => {
            window.location.assign(result.redirect);
        }).catch(error => {
            if (controller.signal.aborted || !progressOverlay.isConnected) return;
            progressOverlay.querySelector('.sim-progress-spinner')?.remove();
            progressOverlay.querySelector('strong').textContent = 'Simulation paused';
            message.textContent = `${error.message} Completed games are saved. Retry continues with unstarted CPU games.`;
            const retry = document.createElement('button');retry.type = 'button';retry.className = 'landing-button mt-4';retry.textContent = 'Retry remaining games';
            retry.addEventListener('click',()=>{hideSimProgress();form.requestSubmit(button);});
            const close = document.createElement('button');close.type = 'button';close.className = 'landing-button mt-4';close.textContent = 'Reload results';
            close.addEventListener('click',()=>window.location.reload());
            progressOverlay.querySelector('.sim-progress-card').append(retry,close);
        }).finally(()=>{
            window.removeEventListener('pagehide',stop);
            document.removeEventListener('livewire:navigating',stop);
            batchRunning = false;
            if (button) {button.disabled = initialDisabled;button.removeAttribute('aria-busy');}
        });
    });
    window.addEventListener('pageshow', hideSimProgress);
}
