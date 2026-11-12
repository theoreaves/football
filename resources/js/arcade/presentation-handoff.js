// A navigation intent must not erase a still-presented outcome or mandatory notice.
export function presentationHandoffNotice({presentation,opening=false,staging=false,transitioning=false,requiredDecision=null}={}) {
    if(opening)return 'Finish or skip the opening presentation before changing controls.';
    if(presentation?.dialogOpen)return 'Close the current result or required notice before changing controls.';
    if(presentation?.recorded && !(presentation.completed && presentation.ready))return 'The saved play is still being presented. Wait for the result and next-play huddle before continuing.';
    if(staging || transitioning)return 'The players and camera are moving into position. Please wait a moment.';
    if(requiredDecision)return 'Complete the required football decision in Coach before returning to Arcade offense.';
    return null;
}
