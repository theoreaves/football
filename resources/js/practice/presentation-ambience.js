export function presentationAmbience({visible,callingPlay,flagOpen,final,recapOpen,inspectionOpen}) {
    return visible && !recapOpen && !inspectionOpen && (flagOpen || (!final && callingPlay));
}

// Pending penalties and final games intentionally omit the play-call form.
export function playCallVisible(form) {
    return Boolean(form && !form.hidden);
}
