// Each response represents a committed game. Never start a second request
// until the first succeeds; a retry asks the server for its remaining fixtures.
export async function runCpuSimBatch(request, progress = () => {}) {
    let completed = 0;
    for (;;) {
        const result = await request();
        if (!Number.isInteger(result?.simulated) || result.simulated < 0 || result.simulated > 1
            || !Number.isInteger(result.remaining) || result.remaining < 0 || typeof result.redirect !== 'string') {
            throw new Error('The server returned an invalid simulation response. Reload the season before retrying.');
        }
        completed += result.simulated;
        progress({completed,remaining:result.remaining});
        if (result.remaining === 0) return {...result,completed};
        if (result.simulated === 0) throw new Error('No game was completed. Reload the season before retrying.');
    }
}
