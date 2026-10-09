let overlay;
let mounted = false;

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
    });
    window.addEventListener('pageshow', hideSimProgress);
}
