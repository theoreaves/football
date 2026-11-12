import {isSpecialTeamCall} from '../practice/special-teams-options.js';
// A refreshed snapshot can already contain another tab's later outcome. Never
// present that unrelated latest play as the result of this command.
export function acceptedPlaybackUrl(snapshot,result) {
    if(!result?.receipt?.watch)return null;
    const number=result.play?.number ?? result.plays?.at(-1)?.number;
    if(!Number.isSafeInteger(number) || number<1 || snapshot.latest_play?.number!==number)return null;
    if(result.state && snapshot.state?.version!==result.state.version)return null;
    if(result.flow?.state_hash && snapshot.flow?.state_hash!==result.flow.state_hash)return null;
    return `${snapshot.urls.playback_base}/${number}`;
}

// Canonical outcome tracks and incoming formation previews have distinct roles.
// A refresh must never promote the next snap's preview into the last result.
export function applyPresentationPlayback(dataset, playback=null) {
    dataset.autoplay=playback?.animation?'true':'false';
    if(playback?.animation){
        dataset.animation=JSON.stringify(playback.animation);
        dataset.beforeState=JSON.stringify(playback.before);
        dataset.afterState=JSON.stringify(playback.after);
    }
}

// Shared controls arrive independently of the watched outcome. Classify the
// canonical CPU plan, even when unchanged units need no formation animation.
export function refreshSharedCpuSpecial(root, nextAnimation) {
    if(root.querySelector('[data-call-form] [name="call"]'))return;
    const call=root.dataset.sharedCpuCall || nextAnimation?.call;
    if(!call)return;
    root.dataset.cpuSpecial=isSpecialTeamCall(call)?'true':'false';
}
