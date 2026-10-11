// Presentation time only: never advances the simulation clock or chooses a penalty.
export function autoNotices(entries) {
    let current = null, elapsed = 0, submitted = false;
    return ({delta, enabled, visible}) => {
        const entry = entries.find(({dialog, allowed = () => true}) => dialog?.open && allowed());
        if (entry !== current) { current = entry; elapsed = 0; submitted = false; }
        if (!entry || !enabled || !visible || submitted) return;
        const form = entry.dialog.querySelector('form[method="dialog"]');
        if (!form) return;
        elapsed += Math.max(0, Math.min(delta, .25));
        if (elapsed < entry.seconds) return;
        // Submit normally so recap music fades and existing close handlers run.
        submitted = true;
        form.requestSubmit();
    };
}
