/** Optional, local-only replay selection. This never changes a drill result. */
export function automaticHighlight(recording, preference = 'off') {
    if (!['major', 'all'].includes(preference) || !recording?.result) return null;
    const {reason, yards} = recording.result;
    if (reason === 'touchdown') return {type: 'touchdown', label: 'Touchdown'};
    if (reason === 'interception') return {type: 'interception', label: 'Interception'};
    if (preference !== 'all') return null;
    if (Number.isFinite(yards) && yards >= 25) return {type: 'long_gain', label: 'Long gain'};
    if (reason !== 'tackle') return null;
    const frame = recording.frames?.at(-1), player = frame?.[3]?.[frame?.[2]];
    const scale = recording.scales?.position, goal = recording.field?.goalX, direction = recording.direction;
    const gap = player && Number.isFinite(scale) && scale > 0 && Number.isFinite(goal) && [1, -1].includes(direction)
        ? (goal - player[0] / scale) * direction : Infinity;
    const broken = recording.events?.some(event => event[2] === 'broken_tackle');
    if (broken || (gap >= 0 && gap <= 5)) return {type: 'notable_tackle', label: broken ? 'Broken-tackle stop' : 'Goal-line stop'};
    return null;
}
