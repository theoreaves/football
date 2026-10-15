// Shared presentation action: this button never submits or changes a selected call.
export function mountExchangeSkipButton(button, state, skip) {
    button.type = 'button';
    const update = () => {
        const {active,fastForwarding} = state();
        button.hidden = !active;
        button.disabled = !active || fastForwarding;
        button.textContent = fastForwarding ? 'Fast-forwarding…' : 'Skip player change';
    };
    button.onclick = () => {
        const {active,fastForwarding} = state();
        if (!active || fastForwarding) return;
        skip();
        update();
    };
    update();
    return update;
}
