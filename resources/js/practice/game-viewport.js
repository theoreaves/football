// Safari changes the visible viewport during rotation and browser chrome changes.
// Use its actual height for the stage and scrollable sheets, without closing dialogs.
export function gameViewport(root, win = window) {
    const update = () => {
        const height = win.visualViewport?.height || win.innerHeight;
        if (Number.isFinite(height) && height > 0) root.style.setProperty('--game-viewport-height', `${height}px`);
    };
    const viewport = win.visualViewport;
    win.addEventListener('resize', update);
    win.addEventListener('orientationchange', update);
    viewport?.addEventListener('resize', update);
    viewport?.addEventListener('scroll', update);
    update();
    return () => {
        win.removeEventListener('resize', update);
        win.removeEventListener('orientationchange', update);
        viewport?.removeEventListener('resize', update);
        viewport?.removeEventListener('scroll', update);
        root.style.removeProperty('--game-viewport-height');
    };
}
