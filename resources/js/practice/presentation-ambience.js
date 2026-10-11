export function presentationAmbience({visible,callingPlay,flagOpen,final,recapOpen,inspectionOpen}) {
    return visible && !recapOpen && !inspectionOpen && (flagOpen || (!final && callingPlay));
}
