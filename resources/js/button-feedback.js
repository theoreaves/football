const instances = new WeakMap();

export function waitsForSubmit(form, event) {
    const method = (event.submitter?.formMethod || form.getAttribute('method') || 'get').toLowerCase();
    const target = event.submitter?.formTarget || form.getAttribute('target') || '_self';
    return !event.defaultPrevented && method !== 'dialog' && target === '_self'
        && form.dataset.feedback !== 'off' && form.dataset.feedback !== 'download';
}

export function waitsForLink(link, event, location) {
    if (event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
        || link.hasAttribute('download') || link.dataset.feedback === 'off' || link.dataset.feedback === 'download'
        || (link.target && link.target !== '_self')) return false;
    const raw = link.getAttribute('href');
    if (!raw || raw.startsWith('#')) return false;
    const url = new URL(raw, location.href);
    return ['http:', 'https:'].includes(url.protocol) && url.origin === location.origin
        && !/\.(zip|pdf|csv|xlsx?|mp3|wav)(?:$)/i.test(url.pathname)
        && !(url.pathname === location.pathname && url.search === location.search && url.hash);
}

export function mountButtonFeedback(doc = document, win = window) {
    if (instances.has(doc)) { instances.get(doc).reset(); return instances.get(doc); }
    const working = new Map(), forms = new Set();
    let status = null, lastButton = null, lastClick = 0, hooked = false, requests = 0;
    const defer = callback => Promise.resolve().then(callback);
    const begin = button => {
        if (button && !working.has(button)) {
            const original = button.getAttribute('aria-busy');
            const spinner = doc.createElement('span'); spinner.className = 'button-feedback-spinner'; spinner.setAttribute('aria-hidden', 'true');
            if (button.tagName !== 'INPUT') button.append(spinner);
            button.setAttribute('aria-busy', 'true'); button.classList.add('button-is-working');
            working.set(button, {original, spinner});
        }
        if (!status) {
            status = doc.createElement('div'); status.className = 'button-feedback-status';
            status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); status.textContent = 'Working…';
            (button?.closest('dialog[open]') || doc.fullscreenElement || doc.body).append(status);
        }
    };
    const reset = () => {
        for (const [button, {original, spinner}] of working) {
            spinner.remove(); button.classList.remove('button-is-working');
            if (original === null) button.removeAttribute('aria-busy'); else button.setAttribute('aria-busy', original);
        }
        working.clear(); forms.clear(); status?.remove(); status = null; requests = 0;
    };
    doc.addEventListener('click', event => {
        const button = event.target.closest?.('button, input[type="submit"], input[type="button"], a[href]');
        if (!button || button.disabled) return;
        if (working.has(button)) {event.preventDefault();return;}
        lastButton = button; lastClick = Date.now();
        button.classList.add('button-was-pressed'); win.setTimeout(() => button.classList.remove('button-was-pressed'), 180);
        if (button.tagName === 'A') defer(() => { if (waitsForLink(button, event, win.location)) begin(button); });
    }, true);
    // Prevent a second submission without disabling named buttons or altering FormData.
    doc.addEventListener('submit', event => {if (forms.has(event.target)) event.preventDefault();}, true);
    doc.addEventListener('submit', event => defer(() => {
        if (!waitsForSubmit(event.target, event)) return;
        forms.add(event.target); begin(event.submitter || event.target.querySelector('button[type="submit"], button:not([type]), input[type="submit"]'));
    }));
    doc.addEventListener('livewire:navigating', () => begin(lastButton));
    doc.addEventListener('livewire:navigated', reset);
    win.addEventListener('pageshow', reset);
    const hookRequests = () => {
        if (hooked || !win.Livewire?.hook) return;
        hooked = true;
        win.Livewire.hook('request', ({succeed, fail}) => {
            if (Date.now() - lastClick > 1500 || !lastButton) return;
            requests++; begin(lastButton);
            let finished = false;
            const done = () => {if (finished) return; finished = true; requests = Math.max(0, requests - 1); if (requests === 0) reset();};
            succeed(done); fail(done);
        });
    };
    doc.addEventListener('livewire:init', hookRequests); hookRequests();
    const instance = {reset}; instances.set(doc, instance); return instance;
}
