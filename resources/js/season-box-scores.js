export function mountSeasonBoxScores() {
    const root = document.querySelector('.season-page');
    if (!root || root.dataset.boxMounted || !root.querySelector('[data-season-box]')) return;
    root.dataset.boxMounted = 'true';
    const dialog = document.createElement('dialog');
    dialog.className = 'season-box-dialog';
    dialog.setAttribute('aria-label', 'Game box score');
    const close = document.createElement('button');
    close.type = 'button'; close.textContent = 'Close box score';
    const frame = document.createElement('iframe');
    frame.title = 'Game box score';
    dialog.append(close, frame); root.append(dialog);
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { frame.src = 'about:blank'; });
    root.querySelectorAll('[data-season-box]').forEach(link => link.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); frame.src = link.href; dialog.showModal();
    }));
}
