// Capture the server-offered list BEFORE Coach filters its private dropdown by
// formation. Display and execution must use the same immutable eligibility list.
export function createFourthDownActions({blocked,tempo,send,notice=()=>{}}) {
    let snapshot=null,offered=[];
    return {
        update(next,calls){snapshot=next;offered=[...calls].filter(call=>['punt','field_goal'].includes(call));},
        get offered(){return [...offered];},
        execute(call){
            if(!snapshot || snapshot.state.down!==4 || snapshot.flow.phase!=='offense' || !offered.includes(call))return false;
            if(blocked()){notice('Finish or cancel the current play, or wait for the presentation before kicking.');return false;}
            return send('play',{call,tempo:tempo()});
        },
    };
}
