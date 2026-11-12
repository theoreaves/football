// Uses the existing idempotent highlight endpoint. Never submits a football play.
export async function saveExhibitionHighlight({url,gameUrl,origin,body,request,read,reconcile}) {
    const game=new URL(gameUrl,origin),target=new URL(url,origin);
    if(target.origin!==origin || target.pathname!==game.pathname.replace(/\/$/,'')+'/highlights')throw new Error('Invalid highlight endpoint.');
    const response=await request(target.href,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body});
    if(!response.ok){const data=await response.json().catch(()=>({}));throw new Error(data.message || `Highlight was not confirmed (${response.status}). Reload saved state before retrying.`);}
    if(response.url){const final=new URL(response.url,origin);if(final.origin!==origin || ![target.pathname,game.pathname].includes(final.pathname))throw new Error('Sign-in may have expired. Reload to sign in and check whether the highlight was saved.');}
    // Highlight metadata changes the authoritative history hash. Refresh that hash
    // without restarting the current presentation or navigating the document.
    const snapshot=await read();await reconcile(snapshot);return snapshot;
}

export function highlightRequiresPresentation(previous,next) {
    return JSON.stringify(previous.state)!==JSON.stringify(next.state)
        || previous.flow?.revision!==next.flow?.revision
        || previous.pending_session?.envelope?.session_id!==next.pending_session?.envelope?.session_id;
}
