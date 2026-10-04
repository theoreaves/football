import WinBox from 'winbox/src/js/winbox.js';
import 'winbox/dist/css/winbox.min.css';
window.WinBox = WinBox;

function initializePractice() {
    const root = document.querySelector('[data-practice]');
    if (root) import('./practice/field.js').then(module => module.mountPractice(root)).catch(() => {
        root.querySelector('[data-status]').textContent = 'Unable to load the practice field. Reload to try again.';
    });
}
initializePractice();
document.addEventListener('livewire:navigated', initializePractice);
