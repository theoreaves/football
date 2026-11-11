import {createReplayPanel} from './replay-panel.js';

// Read-only branch: deliberately has no transport, storage, input, or simulation dependencies.
export function mountExhibitionHistory(root, data, deps = {}) {
    const document = deps.document ?? root.ownerDocument, window = deps.window ?? document.defaultView;
    const schedule = deps.requestAnimationFrame ?? window.requestAnimationFrame.bind(window);
    const cancel = deps.cancelAnimationFrame ?? window.cancelAnimationFrame.bind(window);
    const panel = deps.createReplayPanel ?? createReplayPanel;
    const find = name => root.querySelector(`[data-exhibition-${name}]`);
    for (const name of ['reserve','start','pause','cancel','submit','kind','tempo']) find(name).disabled = true;
    const state = data.state, clock = Number(state.clock || 0);
    find('score').textContent = `Home ${state.home_score ?? 0} · Away ${state.away_score ?? 0}`;
    find('state').textContent = `Q${state.quarter} · ${Math.floor(clock/60)}:${String(Math.floor(clock%60)).padStart(2,'0')} · Down ${state.down} & ${state.distance} · Ball ${state.spot}`;
    find('live').textContent = data.selectedPlay ? `Recorded play #${data.selectedPlay}` : 'Recorded play';
    find('status').textContent = 'Read-only historical replay. These are the original recorded movements. Your live game and pending play are unchanged.';
    find('coach').hidden = false; find('coach').href = data.coachUrl;
    find('replay').disabled = !data.lastRecording;
    find('empty').hidden = false;
    find('empty').textContent = data.lastRecording
        ? 'Choose Replay recorded play to watch this saved play. This historical view does not change your live game.'
        : 'No recorded replay is available for this play.';
    let lastTime = null, frameId, disposed = false;
    const replay = panel(root, {onOpen(){lastTime=null;},onClose(){lastTime=null;}});
    const open = () => { try { replay.open(data.lastRecording); } catch(error) { find('status').textContent = `Replay unavailable: ${error.message}`; } };
    find('replay').addEventListener('click', open);
    function frame(time) {
        if(disposed)return; if(!root.isConnected){dispose();return;}
        if(replay.active) replay.advance(lastTime===null?0:(time-lastTime)/1000);
        lastTime=time; frameId=schedule(frame);
    }
    function dispose() {
        if(disposed)return; disposed=true; cancel(frameId); replay.dispose();
        find('replay').removeEventListener('click',open);
        document.removeEventListener('livewire:navigating',dispose); window.removeEventListener('pagehide',dispose);
        delete root.dataset.mounted;
    }
    document.addEventListener('livewire:navigating',dispose);window.addEventListener('pagehide',dispose);
    frameId=schedule(frame);return dispose;
}
