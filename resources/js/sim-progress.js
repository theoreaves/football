let overlay;
let mounted = false;

export function hideSimProgress() {
    overlay?.remove();
    overlay = null;
}

export function showSimProgress(batch = false) {
    if (overlay?.isConnected) return;
    overlay = document.createElement('dialog');
    overlay.className = 'sim-progress';
    overlay.setAttribute('aria-label',batch ? 'CPU game simulation progress' : 'Game simulation progress');
    overlay.addEventListener('cancel',event=>event.preventDefault());
    overlay.innerHTML = '<div class="sim-progress-card" role="status" aria-live="polite" aria-atomic="true"><span class="sim-progress-spinner" aria-hidden="true"></span><strong>Simulating'+(batch ? ' CPU games' : ' game')+'…</strong><p>Please wait while the games are played and results are saved.</p></div>';
    (document.fullscreenElement || document.body).append(overlay);
    if (typeof overlay.showModal === 'function') overlay.showModal();
    else overlay.setAttribute('open','');
}

export function mountSimProgress() {
    hideSimProgress();
    if (mounted) return;
    mounted = true;
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
        // Weekly batches now run in the queue and use Livewire inline progress.
        const quick = event.submitter?.name === 'quick_sim' && event.submitter.value === '1';
        if (!quick) return;
        if (overlay?.isConnected) { event.preventDefault(); return; }
        showSimProgress();
    });
    window.addEventListener('pageshow',hideSimProgress);
}
