// Move existing controls rather than duplicating forms or replay state.
export function mobileControls(root) {
    if (!root.matches('[data-exhibition]')) return () => {};
    const compact = window.matchMedia('(max-width: 950px), (max-height: 500px)');
    const bar = document.createElement('div');
    bar.className = 'game-mobile-toolbar';
    const groups = [
        ['Call play', '.game-play-panel'],
        ['Camera', '.game-camera-controls, .game-timeline'],
        ['Game', '.game-coaches, .game-clock-management, .game-timeouts, .game-actions, [data-open-personnel], details:has([data-sound-toggle]), .game-cpu-toggle, .game-cpu-status'],
    ];
    const entries = groups.map(([label, selector], index) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'game-dialog game-mobile-dialog';
        dialog.id = `mobile-game-controls-${index}`;
        dialog.setAttribute('aria-label', label);
        const close = document.createElement('button');
        close.type = 'button'; close.className = 'game-mobile-close'; close.textContent = 'Close';
        close.addEventListener('click', () => dialog.close());
        dialog.append(close);
        const button = document.createElement('button');
        button.type = 'button'; button.textContent = label;
        button.setAttribute('aria-controls', dialog.id);
        button.setAttribute('aria-haspopup', 'dialog');
        button.addEventListener('click', () => dialog.showModal());
        bar.append(button); root.append(dialog);
        const nodes = [...root.querySelectorAll(selector)].map(node => {
            const anchor = document.createComment('desktop control position');
            node.before(anchor); return {node, anchor};
        });
        if (!nodes.length) button.disabled = true;
        // Submitting a play closes the sheet before the replay begins.
        dialog.addEventListener('submit', () => dialog.close());
        return {dialog, nodes};
    });
    root.append(bar);
    const update = () => {
        entries.forEach(({dialog, nodes}) => {
            dialog.close();
            nodes.forEach(({node, anchor}) => compact.matches ? dialog.append(node) : anchor.after(node));
        });
    };
    compact.addEventListener('change', update); update();
    return () => {
        compact.removeEventListener('change', update);
        entries.forEach(({dialog, nodes}) => {
            nodes.forEach(({node, anchor}) => { anchor.replaceWith(node); }); dialog.remove();
        });
        bar.remove();
    };
}
