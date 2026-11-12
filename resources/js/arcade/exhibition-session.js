// Exact-body retries. Storage is untrusted and may be unavailable in private browsers.
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
export function createExhibitionTransport({storage, key, request, startUrl, origin, onWarning = () => {}}) {
    let pending = null;
    const base = new URL(startUrl, origin);
    if (base.origin !== origin || !/^\/exhibitions\/[^/]+\/arcade\/sessions$/.test(base.pathname) || base.search || base.hash) throw new Error('Invalid exhibition session endpoint.');
    const warn = () => onWarning('Browser recovery storage is unavailable. Keep this page open: exact retries are retained here, but after reload an unfinished session may need cancellation.');
    const sessionValid = session => {
        if (!object(session) || !object(session.envelope) || !object(session.setup)) return false;
        const {session_id: id, result_id: resultId} = session.envelope;
        if (typeof id !== 'string' || !/^[a-zA-Z0-9-]{1,80}$/.test(id) || typeof resultId !== 'string' || !resultId) return false;
        if (session.prepared_state !== undefined && !object(session.prepared_state)) return false;
        return ['submit', 'cancel'].every(action => {
            if (typeof session[`${action}_url`] !== 'string') return false;
            try {
                const url = new URL(session[`${action}_url`], origin);
                return url.origin === origin && !url.username && !url.password && !url.search && !url.hash
                    && url.pathname === `${base.pathname}/${id}/${action}`;
            } catch { return false; }
        });
    };
    const recordingValid = (value, session) => object(value)
        && ((value.version === 1 && session.setup.fullTeam !== true) || (value.version === 2 && value.fullTeam === true
            && value.contractVersion === 'live-recording-2' && value.catalogVersion === 'arcade-fullteam-1'
            && session.envelope.contract_version === 'live-recording-2'
            && session.setup.fullTeam === true && session.setup.contractVersion === 'live-recording-2'
            && value.call === session.setup.call && Array.isArray(value.actors) && value.actors.length === 22))
        && Array.isArray(value.frames)
        && Array.isArray(value.actors) && Array.isArray(value.events) && object(value.result)
        && typeof value.result.reason === 'string' && value.result.reason !== 'timeout';
    const pendingValid = value => {
        if (!object(value) || !sessionValid(value.session)) return false;
        if (value.body === null) return true;
        const bodyLimit = value.session.envelope.contract_version === 'live-recording-2' ? 4 * 1024 * 1024 : 2 * 1024 * 1024;
        if (typeof value.body !== 'string' || value.body.length > bodyLimit || new TextEncoder().encode(value.body).length > bodyLimit) return false;
        try {
            const body = JSON.parse(value.body);
            return object(body) && recordingValid(body.recording, value.session) && JSON.stringify(body.envelope) === JSON.stringify(value.session.envelope);
        } catch { return false; }
    };
    const remove = () => { try { storage?.removeItem(key); } catch { warn(); } };
    try {
        const raw = storage?.getItem(key);
        if (!storage) warn();
        if (raw) {
            let value;
            try { value = JSON.parse(raw); } catch { /* Discard malformed local data. */ }
            if (pendingValid(value)) pending = value;
            else { remove(); onWarning('Invalid browser recovery data was discarded. The saved server reservation, if any, is available for cancellation.'); }
        }
    } catch { warn(); }
    const save = value => {
        pending = value; // Preserve exact evidence in memory even when quota is exhausted.
        try { if (!storage) throw new Error('Storage unavailable'); storage.setItem(key, JSON.stringify(value)); } catch { warn(); }
    };
    return {
        get pending() { return pending; },
        reserve(session) {
            if (!sessionValid(session)) throw new Error('Invalid session recovery data. Reload the saved game.');
            save({session, body: null});
        },
        prepare(recording) {
            if (!pending) throw new Error('Reserve a play first.');
            if (recording.result?.reason === 'timeout') throw new Error('Expired local play cannot be submitted. Cancel this session.');
            if (!pending.body) save({...pending, body: JSON.stringify({envelope: pending.session.envelope, recording})});
            return pending.body;
        },
        async submit() {
            if (!pending?.body) throw new Error('No finished recording to submit.');
            const result = await request(pending.session.submit_url, pending.body);
            pending = null; remove(); // Cleanup failure must never hide an accepted receipt.
            return result;
        },
        async cancel() {
            if (!pending) return null;
            const result = await request(pending.session.cancel_url, '{}');
            pending = null; remove();
            return result;
        },
    };
}

export function canContinueExhibitionDrive(state, available, driveSide) {
    return available === true && state?.possession === driveSide;
}

export function noSnapNotice(play, stopped) {
    const summary = typeof play?.summary === 'string' ? `${play.summary} ` : '';
    return `${summary}${stopped ? 'The game needs a Coach decision before another Arcade snap. Continue in Coach.' : 'No snap occurred. Choose and reserve the next play when ready.'}`;
}
