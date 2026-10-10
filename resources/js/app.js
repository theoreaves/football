import { mountSimProgress } from './sim-progress.js';
import { mountSeasonBoxScores } from './season-box-scores.js';
import { mountSeasonDepth } from './season-depth.js';
import { mountSeasonRoster } from './season-roster.js';
import { mountSeasonSetup } from './season-setup.js';
function initializePractice() {
    mountSimProgress();
    document.querySelectorAll('[data-player-tabs]').forEach(root => {
        if (root.dataset.tabsMounted) return;
        root.dataset.tabsMounted = 'true';
        root.querySelectorAll('[data-player-tab]').forEach(button => button.addEventListener('click', async () => {
            root.querySelectorAll('[data-player-tab]').forEach(tab => tab.setAttribute('aria-selected', String(tab === button)));
            root.querySelectorAll('[data-player-panel]').forEach(panel => panel.hidden = panel.dataset.playerPanel !== button.dataset.playerTab);
            const panel = root.querySelector(`[data-player-panel="${button.dataset.playerTab}"]`);
            if (!panel?.dataset.playerLazyUrl || panel.dataset.loaded === 'true' || panel.dataset.loading === 'true') return;
            panel.dataset.loading = 'true';
            panel.innerHTML = `<div role="status" aria-live="polite" class="flex items-center justify-center gap-3 py-10 text-gray-700">
                <span aria-hidden="true" class="inline-block h-7 w-7 animate-spin rounded-full border-4 border-gray-200 border-t-blue-600"></span>
                <span>Loading player history…</span>
            </div>`;
            try {
                const response = await fetch(panel.dataset.playerLazyUrl, {credentials: 'same-origin', headers: {'Accept': 'text/html'}});
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                panel.innerHTML = await response.text();
                panel.dataset.loaded = 'true';
            } catch (error) {
                panel.innerHTML = '<p role="alert">Unable to load player history. Select the tab again to retry.</p>';
                console.error('Player history failed to load', error);
            } finally {
                delete panel.dataset.loading;
            }
        }));
    });
    const appearance = document.querySelector('[data-player-appearance]');
    if (appearance) import('./player-appearance.js').then(module => module.mountPlayerAppearance(appearance)).catch(() => {
        appearance.querySelector('[data-appearance-panel]').hidden = false;
        appearance.querySelector('[data-appearance-open]').hidden = true;
        appearance.querySelector('[data-player-portrait]').textContent = 'Preview unavailable. You can still edit and save appearance below.';
    });
    mountSeasonBoxScores();
    mountSeasonDepth();
    mountSeasonRoster();
    mountSeasonSetup(document.querySelector('[data-season-setup]'));
    const root = document.querySelector('[data-practice]');
    if (root) import('./practice/field.js').then(module => module.mountPractice(root)).catch((error) => {
        console.error('[WebSports] Field initialization failed:', error);
        root.querySelector('[data-status]').textContent = 'Unable to load the practice field. Reload to try again.';
    });
}
initializePractice();
document.addEventListener('livewire:navigated', initializePractice);
