import { mountSeasonBoxScores } from './season-box-scores.js';
import { mountSeasonDepth } from './season-depth.js';
import { mountSeasonRoster } from './season-roster.js';
import { mountSeasonSetup } from './season-setup.js';
function initializePractice() {
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
    if (root) import('./practice/field.js').then(module => module.mountPractice(root)).catch(() => {
        root.querySelector('[data-status]').textContent = 'Unable to load the practice field. Reload to try again.';
    });
}
initializePractice();
document.addEventListener('livewire:navigated', initializePractice);
