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
