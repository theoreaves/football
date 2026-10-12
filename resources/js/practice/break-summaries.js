const sides=['away','home'];
const number=value=>Number.isFinite(Number(value))?Number(value):0;
const difference=(play,side,key)=>number(play.after?.stats?.[side]?.[key])-number(play.before?.stats?.[side]?.[key]);
export const clockText=seconds=>`${Math.floor(seconds/60)}:${String(Math.floor(seconds%60)).padStart(2,'0')}`;
function elapsed(play){
    if(play.before?.phase==='extra_point')return 0;
    const before=play.before ?? {},after=play.after ?? {};
    // Clock resets at period boundaries; the saved elapsed value includes runoff.
    const remaining=before.quarter===after.quarter ? Math.max(0,number(before.clock)-number(after.clock)):number(before.clock);
    return Math.min(remaining,Math.max(0,number(play.clock_seconds ?? remaining)));
}
// Match ExhibitionBoxScore's first-down rule, including accepted defensive fouls.
export function earnedFirstDown(play) {
    const before=play.before,after=play.after;if(!before || !after || after.penalty_pending)return false;
    const side=before.possession,penalty=play.penalty;
    const defensiveFirst=penalty?.accepted && penalty.team!==side
        && (['defensive_pass_interference','face_mask'].includes(penalty.type) || Number(penalty.yards)>=Number(before.distance));
    return after.possession===side && after.down===1 && (before.phase ?? 'scrimmage')==='scrimmage'
        && Boolean(defensiveFirst || (!(play.no_snap || ['penalty','interception'].includes(play.outcome))
            && Number(play.gain)>=Number(before.distance) && !['punt','field_goal'].includes(play.call)));
}
export function periodSummary(history,quarter,half=false){
    const plays=history.filter(p=>p.before && p.after && !p.after.penalty_pending && (quarter==null || (half ? p.before.quarter<=2:p.before.quarter===quarter)));
    return Object.fromEntries(sides.map(side=>[side,{
        ...Object.fromEntries(['plays','yards','turnovers','penalties','penalty_yards'].map(key=>[key,plays.reduce((sum,p)=>sum+difference(p,side,key),0)])),
        first_downs:plays.filter(p=>p.before.possession===side && earnedFirstDown(p)).length,
        points:plays.reduce((sum,p)=>sum+number(p.after[`${side}_score`])-number(p.before[`${side}_score`]),0),
        possession:plays.filter(p=>p.before.possession===side && p.before.phase==='scrimmage').reduce((sum,p)=>sum+elapsed(p),0)
    }]));
}
function driveResult(play,side){
    const after=play.after,before=play.before,other=side==='home'?'away':'home';
    const points=number(after[`${side}_score`])-number(before[`${side}_score`]);
    if(points>=6)return 'Touchdown';
    if(play.outcome==='field_goal_good' || (play.call==='field_goal' && points===3))return 'Field goal';
    if(play.call==='field_goal')return 'Missed / blocked field goal';
    if(play.call==='punt')return 'Punt';
    if(number(after[`${other}_score`])-number(before[`${other}_score`])===2)return 'Safety';
    if(difference(play,side,'turnovers')>0)return number(after[`${other}_score`])>number(before[`${other}_score`])?'Turnover · defensive score':'Turnover';
    if(after.possession!==side && before.quarter===after.quarter)return 'Turnover on downs';
    if(after.status==='final')return 'End of game';
    if(before.quarter===2 && after.quarter===3)return 'End of half';
    return 'Drive ended';
}
export function completedDrives(history,includeActive=false){
    const drives=[];let current;
    for(const play of history){
        const before=play.before,after=play.after;
        if(!before || !after || after.penalty_pending)continue;
        if(before.phase!=='scrimmage')continue;
        if(!current || current.side!==before.possession)current={side:before.possession,plays:0,yards:0,seconds:0,startQuarter:before.quarter,startClock:before.clock};
        current.plays+=difference(play,current.side,'plays');current.yards+=difference(play,current.side,'yards');current.seconds+=elapsed(play);
        const half=before.quarter===2 && after.quarter===3;
        if(after.possession!==current.side || after.phase!=='scrimmage' || half || after.status==='final'){
            drives.push({...current,number:play.number,result:driveResult(play,current.side)});current=undefined;
        }
    }
    if(includeActive && current)drives.push({...current,number:null,result:'In progress'});
    return drives;
}
export function latestBreakSummary(history){
    const last=history.at(-1);if(!last || last.after?.penalty_pending)return null;
    return completedDrives(history).findLast(drive=>drive.number===last.number) ?? null;
}
