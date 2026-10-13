// Presentation policy only. This never changes the simulation clock or CPU calls.
export function manualGameScreens({exhibition, cpuOnly}) {
    return Boolean(exhibition) && cpuOnly !== 'true';
}

export function screenTimerExpired({manual, elapsed, seconds, pending = false, visible = true}) {
    return !manual && !pending && visible && Number.isFinite(elapsed)
        && Number.isFinite(seconds) && elapsed >= seconds;
}
