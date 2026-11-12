// New exhibition reservations opt in explicitly. Recovery never rewrites a saved body.
export const ARCADE_CALLS = Object.freeze({inside_run:'Inside run',outside_run:'Outside run',short_pass:'Short pass',medium_pass:'Medium pass',deep_pass:'Deep pass'});
export function exhibitionReservation({version,call,tempo}) {
    if(!Object.hasOwn(ARCADE_CALLS,call))throw new Error('Choose one of the five Arcade plays.');
    return {version,kind:call.endsWith('_run')?'run':'pass',call,tempo,contract_version:'live-recording-2'};
}
export function exhibitionCanPass(state) {
    return state?.mode==='passing' && state.status==='running' && state.ball?.phase==='held'
        && state.ball.holderKey===(state.fullTeam?state.quarterback?.key:'qb');
}
export function exhibitionControlHint(state) {
    if(!state?.fullTeam)return '';
    if(state.status==='finished')return state.result?.reason==='interception' ? 'Interception ends at the catch. Replay or submit the recorded result.' : 'Play finished. Replay or submit the recorded result.';
    if(state.mode!=='passing' && state.ball?.holderKey===state.quarterback?.key)return 'Automatic handoff: control transfers to your running back when he takes the ball.';
    if(state.mode==='passing' && state.ball?.holderKey===state.quarterback?.key)return 'Control your quarterback. Click a target to throw; control transfers to the receiver after a catch.';
    if(state.ball?.phase==='flight')return 'Pass in flight. Control transfers automatically after a catch.';
    return 'Run with your ball carrier. WASD / arrows move; Q / E juke.';
}

export function exhibitionLiveLabel(state) {
    if(!state)return '';
    const terminal=state.fullTeam && state.status==='finished';
    const reason=terminal ? state.result?.reason : null;
    const actor=reason==='interception' ? state.actorByKey?.[state.ball?.holderKey] || state.runner : state.runner;
    const name=reason==='incomplete' ? 'Incomplete pass' : actor.name || 'Ball carrier';
    const yards=terminal ? state.result.yards : (state.runner.x-state.startX)*state.direction;
    return `${name}${reason==='interception' ? ' · interception' : ''} · ${yards.toFixed(1)} yards · ${state.elapsed.toFixed(1)}s live${state.fullTeam ? ' · '+exhibitionControlHint(state) : ''}`;
}
