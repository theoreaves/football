const kicks = ['kickoff','onside_kick','punt','field_goal','extra_point'];

// Derive audible ball events from saved timing, possession and height.
export function ballSoundCues(animation) {
    if (!animation || animation.no_snap || animation.call === 'clock_event') return [];
    const cues = [], duration = animation.duration ?? Infinity;
    const inPlay = time => Number.isFinite(time) && time >= 0 && time <= duration;
    const isKick = kicks.includes(animation.call);
    const kickAt = animation.kick_at ?? animation.events?.find(event => /^kick$/i.test(event[1]))?.[0] ?? 1.2;
    const releaseAt = isKick ? kickAt : animation.throw_at ?? 2.2;
    if (isKick && inPlay(kickAt)) cues.push({time:kickAt,type:'kick',mood:null});
    const holders = animation.ballHolders ?? [];
    const heldAt = time => Boolean(holders.filter(entry => entry[0] <= time).at(-1)?.[1]);
    // Ignore the center-to-QB snap and RB handoff; a catch follows a pass/kick.
    if (isKick || animation.passing) {
        for (let i = 1; i < holders.length; i++) {
            const [time,team,role] = holders[i];
            if (inPlay(time) && time > releaseAt && !holders[i-1][1] && team && role) {
                cues.push({time,type:'catch',mood:null});
            }
        }
    }
    const path = animation.ball ?? [];
    let lastGround = -Infinity, groundCount = 0;
    for (let i = 1; i < path.length; i++) {
        const previous = path[i-1], point = path[i];
        const time = point[0];
        // A descending, loose ball reaches the ground. A tee, carried ball,
        // or ball resting on the ground cannot repeatedly make impact sounds.
        if (inPlay(time) && previous[2] > .27 && point[2] <= .27
            && !heldAt(time) && time - lastGround >= .15) {
            cues.push({time,type:'ball_ground',mood:null});lastGround=time;
            if (++groundCount === 3) break;
        }
    }
    return cues.sort((a,b) => a.time-b.time);
}
