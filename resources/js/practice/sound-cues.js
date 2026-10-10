import {ballSoundCues} from './ball-sound-cues.js';
import {contactSoundCues} from './contact-sound-cues.js';

export function soundCues(animation, before, after, penalty = null) {
    if (!animation) return [];
    const cues = [], add = (time, type, mood = null) => cues.push({time,type,mood});
    const call = animation.call;
    const noSnap = animation.no_snap || call === 'clock_event';
    if (before?.phase === 'scrimmage' && before.possession === 'away' && before.down === 3 && !noSnap) add(-1,'third_down');
    if (!noSnap) {
        if (call !== 'kickoff') add(0,'snap');
        if (animation.passing) add(animation.throw_at ?? 2.2,'throw');
    }
    const resultAt = animation.reveal_at ?? (noSnap ? .8 : 5.3);
    const reactionAt = penalty ? Math.max(resultAt,animation.duration ?? resultAt) + .2 : resultAt;
    const summary = animation.events?.at(-1)?.[1]?.toLowerCase() || '';
    cues.push(...contactSoundCues(animation), ...ballSoundCues(animation));
    if (call !== 'clock_event') {
        const stoppedAt = animation.contact_at ?? animation.result_at ?? resultAt;
        const at = noSnap ? .48 : stoppedAt + .1;
        add(Number.isFinite(animation.duration) ? Math.min(animation.duration,at) : at,'whistle',noSnap ? 'stop' : 'short');
    }
    if (before && after && !after.penalty_pending) {
        const homePoints = after.home_score - before.home_score, awayPoints = after.away_score - before.away_score;
        const touchdown = homePoints >= 6 || awayPoints >= 6;
        const turnover = after.stats?.home?.turnovers > before.stats?.home?.turnovers || after.stats?.away?.turnovers > before.stats?.away?.turnovers
            || summary.includes('turnover on downs');
        const firstDown = before.phase === 'scrimmage' && after.phase === 'scrimmage' && after.possession === before.possession && after.down === 1 && !noSnap && !summary.includes('nullified') && !summary.includes('awaiting');
        if (homePoints > 0 || awayPoints > 0) {
            add(reactionAt,'crowd',homePoints > 0 ? 'cheer' : 'groan');
            if (touchdown) add(reactionAt + .2, homePoints >= 6 ? 'touchdown' : 'away_score');
        } else if (turnover || firstDown || summary.includes('sack') || summary.includes('missed') || summary.includes('blocked')) {
            const goodSide = turnover || summary.includes('sack') || summary.includes('missed') || summary.includes('blocked') ? (before.possession === 'home' ? 'away' : 'home') : before.possession;
            add(reactionAt,'crowd',goodSide === 'home' ? 'cheer' : 'groan');
            if (turnover && goodSide === 'home' && !noSnap) add(reactionAt + .2,'home_turnover');
            if (firstDown && goodSide === 'home') add(reactionAt + .2,'first_down');
        }
        if (after.status === 'final') add(reactionAt + .4,'final');
        else if (before.quarter !== after.quarter) add(reactionAt + .4,'quarter');
    }
    return cues.sort((a,b) => a.time - b.time);
}

export function crossedCues(cues, previous, current) {
    return current < previous ? [] : cues.filter(cue => cue.time > previous && cue.time <= current);
}

export function soundSettings(value = {}) {
    if (!value || typeof value !== 'object') value = {};
    const volume = (key, fallback) => Number.isFinite(Number(value[key])) ? Math.max(0,Math.min(1,Number(value[key]))) : fallback;
    return { enabled: value.enabled !== false, master: volume('master',.6), crowd: volume('crowd',.35), effects: volume('effects',.7), players: volume('players',.75), stadium: volume('stadium',.5) };
}

// The set phase runs in real seconds, independently of replay speed.
export function cadenceCues(animation) {
    if (!animation || animation.no_snap || ['clock_event','kickoff','punt','field_goal','extra_point'].includes(animation.call)) return [];
    return [{time:-2,type:'cadence',mood:'ready'}, {time:-1,type:'cadence',mood:'set'}, {time:0,type:'cadence',mood:'hut'}];
}

// Presentation phases reset their local timers; the audio cursor must not rewind.
export function presentationSoundTime(phase,elapsed,duration,postElapsed,previous) {
    if (phase === 'liningup' || phase === 'set') return -1;
    return ['result','celebration','big_play'].includes(phase)
        ? Math.max(previous,duration + postElapsed) : elapsed;
}
