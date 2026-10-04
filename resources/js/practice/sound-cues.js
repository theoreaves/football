export function soundCues(animation, before, after) {
    if (!animation) return [];
    const cues = [], add = (time, type, mood = null) => cues.push({time,type,mood});
    const call = animation.call;
    const noSnap = animation.no_snap || call === 'clock_event';
    if (before?.phase === 'scrimmage' && before.possession === 'away' && before.down === 3 && !noSnap) add(-1,'third_down');
    if (!noSnap) {
        add(call === 'kickoff' ? 1.2 : 0,call === 'kickoff' ? 'kick' : 'snap');
        if (['punt','field_goal','extra_point'].includes(call)) add(1.2,'kick');
        if (animation.passing) add(2.2,'throw');
    }
    const resultAt = animation.reveal_at ?? (noSnap ? .8 : 5.3);
    const summary = animation.events?.at(-1)?.[1]?.toLowerCase() || '';
    if (!noSnap && !summary.includes('incomplete') && !summary.includes('touchdown') && !['punt','field_goal','extra_point','kickoff','spike','kneel'].includes(call)) add(resultAt,'tackle');
    add(resultAt,'whistle');
    if (before && after && !after.penalty_pending) {
        const homePoints = after.home_score - before.home_score, awayPoints = after.away_score - before.away_score;
        const touchdown = homePoints >= 6 || awayPoints >= 6;
        const turnover = after.stats?.home?.turnovers > before.stats?.home?.turnovers || after.stats?.away?.turnovers > before.stats?.away?.turnovers
            || summary.includes('turnover on downs');
        const firstDown = before.phase === 'scrimmage' && after.phase === 'scrimmage' && after.possession === before.possession && after.down === 1 && !noSnap && !summary.includes('nullified') && !summary.includes('awaiting');
        if (homePoints > 0 || awayPoints > 0) {
            add(resultAt,'crowd',homePoints > 0 ? 'cheer' : 'groan');
            if (touchdown) add(resultAt + .2, homePoints >= 6 ? 'touchdown' : 'away_score');
        } else if (turnover || firstDown || summary.includes('sack') || summary.includes('missed') || summary.includes('blocked')) {
            const goodSide = turnover || summary.includes('sack') || summary.includes('missed') || summary.includes('blocked') ? (before.possession === 'home' ? 'away' : 'home') : before.possession;
            add(resultAt,'crowd',goodSide === 'home' ? 'cheer' : 'groan');
            if (firstDown && goodSide === 'home') add(resultAt + .2,'first_down');
        }
        if (after.status === 'final') add(resultAt + .4,'final');
        else if (before.quarter !== after.quarter) add(resultAt + .4,'quarter');
    }
    return cues.sort((a,b) => a.time - b.time);
}

export function crossedCues(cues, previous, current) {
    return current < previous ? [] : cues.filter(cue => cue.time > previous && cue.time <= current);
}

export function soundSettings(value = {}) {
    if (!value || typeof value !== 'object') value = {};
    const volume = (key, fallback) => Number.isFinite(Number(value[key])) ? Math.max(0,Math.min(1,Number(value[key]))) : fallback;
    return { enabled: value.enabled !== false, master: volume('master',.6), crowd: volume('crowd',.35), effects: volume('effects',.7) };
}
