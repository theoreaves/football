export function rosterMatches(name, position, search, filter) {
    return name.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()) && (!filter || position === filter);
}

export function closeSavedPlayerEditor(document, window) {
    if (!document.querySelector('[data-player-editor-saved]') || window.parent === window) return;
    window.parent.postMessage({type: 'football:close-player-editor'}, window.location.origin);
}

export function mountSeasonRoster() {
    closeSavedPlayerEditor(document, window);
    const back = document.querySelector('[data-player-editor-back]');
    back?.addEventListener('click', event => {
        if (window.parent === window) return;
        event.preventDefault();
        window.parent.postMessage({type: 'football:close-player-editor'}, window.location.origin);
    });
    const root = document.querySelector('[data-season-roster]');
    if (!root || root.dataset.mounted) return;
    root.dataset.mounted = 'true';
    const search = root.querySelector('[data-roster-search]'), position = root.querySelector('[data-roster-position]');
    if (search && position) {
    const rows = [...root.querySelectorAll('[data-roster-player]')];
    const key = 'football:roster-filters:'+window.location.pathname+window.location.search;
    try { const saved = JSON.parse(sessionStorage.getItem(key) || '{}'); search.value = saved.search || ''; position.value = saved.position || ''; } catch { /* Optional persistence. */ }
    const filter = () => {
        let count = 0;
        rows.forEach(row => { row.hidden = !rosterMatches(row.dataset.name, row.dataset.position, search.value, position.value); if (!row.hidden) count++; });
        root.querySelector('[data-roster-count]').textContent = `${count} of ${rows.length} players`;
        root.querySelector('[data-roster-empty]').hidden = count > 0 || rows.length === 0;
        try { sessionStorage.setItem(key, JSON.stringify({search:search.value,position:position.value})); } catch { /* Optional persistence. */ }
    };
    search.addEventListener('input', filter); position.addEventListener('change', filter); filter();
    }
    const dialog = root.querySelector('[data-roster-dialog]'), frame = root.querySelector('[data-roster-frame]');
    root.querySelectorAll('[data-roster-editor]').forEach(link => link.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); frame.src = link.href; dialog.showModal();
    }));
    root.querySelector('[data-roster-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => window.location.reload());
    window.addEventListener('message', event => {
        if (event.origin === window.location.origin && event.source === frame.contentWindow && event.data?.type === 'football:close-player-editor') dialog.close();
    });
}
