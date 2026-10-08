// Keep the rendered field visible while the next server-generated game view loads.
export function gameNavigation(root, { freeze, dispose, mount }) {
    let pending = false;
    const submit = async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        const action = new URL(form.action, window.location.href);
        if (action.origin !== window.location.origin) return;
        event.preventDefault();
        if (pending) return;
        pending = true;
        const data = new FormData(form);
        if (event.submitter?.name) data.append(event.submitter.name, event.submitter.value);
        let cover;
        try {
            const response = await fetch(action, { method: 'POST', body: data, credentials: 'same-origin' });
            if (!response.ok) throw new Error('Game request failed');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = page.querySelector('[data-practice]');
            if (!next) {
                window.location.assign(response.url);
                return;
            }
            cover = freeze();
            dispose();
            // Retain the stage element so browser fullscreen survives each play.
            for (const attribute of [...root.attributes]) root.removeAttribute(attribute.name);
            for (const attribute of [...next.attributes]) root.setAttribute(attribute.name, attribute.value);
            root.replaceChildren(...next.childNodes);
            (document.fullscreenElement || document.body).append(cover);
            window.history.replaceState(null, '', response.url);
            mount(root, () => cover.remove());
        } catch {
            cover?.remove();
            // Never repeat a POST: the server may already have processed the play.
            const message = document.createElement('p');
            message.style.cssText = 'position:fixed;z-index:10000;top:1rem;left:1rem;right:1rem;padding:1rem;background:#111827;color:white';
            message.textContent = 'Unable to load the updated game. Reload to see its current state. ';
            const reload = document.createElement('a');
            reload.href = window.location.href;
            reload.textContent = 'Reload game';
            reload.style.textDecoration = 'underline';
            message.append(reload);
            document.body.append(message);
        }
    };
    root.addEventListener('submit', submit);
    return () => root.removeEventListener('submit', submit);
}
