/** A fixed physics clock. Long/background frames are discarded rather than fast-forwarded. */
export function createFixedStepper(step, interval = 1 / 60) {
    let accumulated = 0;
    return {
        advance(seconds) {
            accumulated += Math.max(0, Math.min(0.1, Number.isFinite(seconds) ? seconds : 0));
            let count = 0;
            while (accumulated + 1e-10 >= interval && count < 6) {
                step();
                accumulated -= interval;
                count++;
            }
            return count;
        },
        reset() { accumulated = 0; },
    };
}
