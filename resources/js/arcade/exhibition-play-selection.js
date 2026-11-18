// New exhibition reservations opt in explicitly. Recovery never rewrites a saved body.
import {FULL_TEAM_PLAYBOOK, fullTeamPlay} from './full-team-plays.js';
export const ARCADE_CALLS = Object.freeze(Object.fromEntries(FULL_TEAM_PLAYBOOK.map(play=>[play.id,play.label])));
export function exhibitionReservation({version,call,tempo}) {
    if(!Object.hasOwn(ARCADE_CALLS,call))throw new Error('Choose an available Arcade play.');
    return {version,kind:fullTeamPlay(call).kind,call,tempo,contract_version:'live-recording-2'};
}
export function exhibitionUsesQbControl(state) {
    return !!state?.fullTeam && Object.hasOwn(state, 'inputActorKey');
}
export function exhibitionCanControl(state) {
    return !exhibitionUsesQbControl(state)
        || (state.status === 'running' && state.inputActorKey === state.quarterback?.key);
}
export function exhibitionCanPass(state) {
    return exhibitionCanControl(state) && !(exhibitionUsesQbControl(state) && state.qbCommitted) && state?.mode==='passing' && state.status==='running' && state.ball?.phase==='held'
        && state.ball.holderKey===(state.fullTeam?state.quarterback?.key:'qb');
}
export function exhibitionControlHint(state) {
    if(!state?.fullTeam)return '';
    if(state.status==='finished' && state.result?.reason==='timeout')return 'Local recording limit reached. No football result was saved. Cancel the reserved play, then reserve again.';
    if(state.status==='finished')return state.result?.reason==='interception' ? 'Interception ends at the catch. Replay or submit the recorded result.' : 'Play finished. Replay or submit the recorded result.';
    if(exhibitionUsesQbControl(state)){
        if(state.ball?.phase==='flight')return 'Pass in flight.';
        if(fullTeamPlay(state.config?.call || state.call)?.mechanic==='sneak')return 'QB Sneak: WASD / arrows to move, Q / E to juke. No forward pass on this designed run.';
        if(state.mode!=='passing'){
            return state.ball?.holderKey===state.quarterback?.key
                ? 'Automatic handoff. The CPU runs with the ball.'
                : 'The CPU is running with the ball.';
        }
        if(state.ball?.holderKey && state.ball.holderKey!==state.quarterback?.key)return 'The CPU runs after the catch.';
        if(state.qbCommitted)return 'QB scramble: WASD / arrows to move, Q / E to juke. Passing is no longer available.';
        if(state.status==='ready' || state.status==='paused' || exhibitionCanControl(state)){
            return 'QB controls: WASD / arrows to move, Q / E to juke. Click or drag to aim and throw from behind the line.';
        }
        return 'Waiting for the quarterback to take the snap.';
    }
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
