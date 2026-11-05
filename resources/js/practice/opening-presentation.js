// Entrances are a presentation sequence, not a timed human decision screen.
export function entranceComplete({active, finishing, seconds, end, visible}) {
    return active && !finishing && visible && Number.isFinite(seconds) && seconds >= end;
}

export function hideOpeningUnits(...groups) {
    groups.forEach(group => group.forEach(model => { model.visible = false; }));
}

// Stop the music once and route the user's choice before opening another screen.
export async function finishOpeningEntrance({entrance,panel,stopMusic,continueIntro,isConnected},skipIntro=false) {
    if(!entrance.active || entrance.finishing)return;
    entrance.finishing=true;
    panel.querySelectorAll('button').forEach(button=>{button.disabled=true;});
    await stopMusic();
    if(!isConnected())return;
    entrance.active=false;panel.hidden=true;
    continueIntro(skipIntro);
}
