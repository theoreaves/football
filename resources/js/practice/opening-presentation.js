// Entrances are a presentation sequence, not a timed human decision screen.
export function entranceComplete({active, finishing, seconds, end, visible}) {
    return active && !finishing && visible && Number.isFinite(seconds) && seconds >= end;
}

export function hideOpeningUnits(...groups) {
    groups.forEach(group => group.forEach(model => { model.visible = false; }));
}
