// Durable, exact-body commands for non-interactive exhibition decisions.
const actions = new Set(['preferences', 'advance', 'fast', 'timeout', 'spike', 'intro_seen', 'penalty', 'coin', 'ot_call', 'ot_choice', 'play', 'cpu_timeout', 'lineup']);
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);

export function newFlowCommandId(crypto = globalThis.crypto) {
    if (typeof crypto?.randomUUID === 'function') return crypto.randomUUID();
    if (typeof crypto?.getRandomValues !== 'function') throw new Error('Secure command IDs are unavailable. Reload in a supported browser.');
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}

export function createExhibitionFlowTransport({url, origin, storage, key, request, idFactory = newFlowCommandId, onWarning = () => {}}) {
    const endpoint = new URL(url, origin);
    if (endpoint.origin !== origin || endpoint.username || endpoint.password || endpoint.search || endpoint.hash
        || !/^\/exhibitions\/[^/]+\/arcade\/flow$/.test(endpoint.pathname)) throw new Error('Invalid exhibition flow endpoint.');
    let pending = null;
    const valid = body => {
        if (typeof body !== 'string' || body.length > 16384) return false;
        try {
            const value = JSON.parse(body);
            return object(value) && uuid.test(value.command_id) && actions.has(value.action)
                && Number.isInteger(value.version) && value.version >= 0
                && Number.isInteger(value.flow_revision) && value.flow_revision >= 0
                && typeof value.state_hash === 'string' && /^[0-9a-f]{64}$/i.test(value.state_hash);
        } catch { return false; }
    };
    const warn = () => onWarning('Decision recovery storage is unavailable. Keep this page open until the action is confirmed.');
    const remove = () => { try { storage?.removeItem(key); } catch { warn(); } };
    const set = body => {
        pending = Object.freeze({...JSON.parse(body), body});
        try { if (!storage) throw new Error('No storage'); storage.setItem(key, body); } catch { warn(); }
    };
    try {
        const body = storage?.getItem(key);
        if (body) {
            if (valid(body)) pending = Object.freeze({...JSON.parse(body), body});
            else { remove(); onWarning('Invalid saved decision was discarded. Reload the saved game before continuing.'); }
        }
    } catch { warn(); }
    return {
        get pending() { return pending; },
        prepare(payload) {
            if (pending) throw new Error('An unconfirmed decision exists. Retry that same action before choosing another.');
            const body = JSON.stringify({...payload, command_id: idFactory()});
            if (!valid(body)) throw new Error('Invalid exhibition decision. Reload the saved game.');
            set(body); return body;
        },
        async submit() {
            if (!pending) throw new Error('No decision is waiting to be submitted.');
            const result = await request(endpoint.href, pending.body);
            pending = null; remove();
            return result;
        },
        reject(error) {
            // These are definitive server rejections, not an unknown network outcome.
            // The UI must reload authoritative state before allowing another command.
            if (![400, 403, 404, 409, 410, 422].includes(error?.status)) return false;
            pending = null; remove(); return true;
        },
    };
}

const ordinal = down => ({1:'1st',2:'2nd',3:'3rd',4:'4th'}[down] || `Down ${down}`);
export function exhibitionStateLabel(state) {
    const clock = Math.max(0, Number(state.clock) || 0);
    const period = state.status === 'final' ? 'FINAL' : Number(state.quarter) >= 5 ? `OT${state.quarter > 5 ? state.quarter - 4 : ''}` : `Q${state.quarter}`;
    const distance = Number(state.distance) >= 100 - Number(state.spot) ? 'Goal' : Number(Number(state.distance).toFixed(1));
    const spot = Number(Number(state.spot).toFixed(1));
    const direction = (state.possession === 'home' ? 1 : -1) * (Number(state.quarter) % 2 === 0 ? -1 : 1);
    return `${period} · ${Math.floor(clock/60)}:${String(Math.floor(clock%60)).padStart(2,'0')} · ${ordinal(state.down)} & ${distance} · Ball ${spot} · ${state.possession === 'away' ? 'Away' : 'Home'} offense ${direction === 1 ? '→' : '←'}`;
}

export function exhibitionClockLabel(state) {
    if (state.status === 'final') return 'Final whistle';
    if (state.untimed_down) return 'Untimed down';
    return state.clock_running || state.clock_restart_on_ready ? 'Clock runs between snaps' : 'Clock stopped';
}

export function canonicalExhibitionNotices({flow, plays = [], play = null, state = null, before = null} = {}) {
    const messages = [];
    const add = value => { if (typeof value === 'string' && value.trim() && !messages.includes(value.trim())) messages.push(value.trim()); };
    const saved = plays.length ? plays : play ? [play] : [];
    for (const item of saved) {
        add(item.summary);
        const prior = item.before || before, after = item.after || state;
        if (prior && after) {
            if (after.quarter !== prior.quarter) add(`End of ${prior.quarter >= 5 ? 'overtime period' : `Q${prior.quarter}`}. ${after.quarter >= 5 ? 'Overtime' : `Q${after.quarter}`} begins. Teams change ends.`);
            if (after.possession !== prior.possession) add(`Possession changes to ${after.possession === 'away' ? 'Away' : 'Home'}.`);
            if (after.status === 'final') add(`Final whistle. Home ${after.home_score} · Away ${after.away_score}.`);
        }
        if (item.penalty) {
            const penalty = item.penalty;
            add(`Penalty ${penalty.accepted ? 'accepted' : 'declined'}: ${String(penalty.type || 'flag').replaceAll('_', ' ')}.${penalty.accepted && item.outcome === 'penalty' && !item.no_snap ? ' The play was nullified; use the official down and distance above.' : ''}`);
        }
    }
    for (const notice of flow?.notices || []) add(typeof notice === 'string' ? notice : notice?.message);
    return messages;
}
