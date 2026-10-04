export function canAdvanceCpu({ enabled, visible, ready, submitting, dialogOpen, final }) {
    return enabled && visible && ready && !submitting && !dialogOpen && !final;
}
