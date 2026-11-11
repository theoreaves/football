<x-layouts.app>
<div class="arcade-shell" data-arcade-exhibition data-exhibition="{{ json_encode(['state'=>$state,'available'=>$available,'coachUrl'=>$coachUrl,'startUrl'=>$startUrl,'lastRecording'=>$lastRecording ?? null,'pendingSession'=>$pendingSession ?? null,'replayOnly'=>$replayOnly ?? false,'selectedPlay'=>$selectedPlay ?? null,'csrf'=>csrf_token()]) }}">
    <header class="arcade-top"><div><p class="arcade-eyebrow">WebSports Arcade · Exhibition</p><h1>{{ ($replayOnly ?? false) ? 'Recorded exhibition play.' : 'Your offensive drive.' }}</h1></div><p>Real roster. Real recorded movement. Official game state updates only when your recorded play is accepted.</p></header>
    <div class="arcade-scoreboard"><strong data-exhibition-score></strong><span data-exhibition-state></span><span data-exhibition-live></span></div>
    <div class="arcade-setup">
        <label>Play<select data-exhibition-kind><option value="run">Run</option><option value="pass">Pass</option></select></label>
        <label>Tempo<select data-exhibition-tempo><option value="normal">Normal</option><option value="hurry">Hurry</option><option value="drain">Drain</option></select></label>
        <button type="button" data-exhibition-reserve>Reserve play</button>
        <a data-exhibition-coach href="{{ $coachUrl }}">Continue in Coach</a>
        <a href="{{ $startUrl ? url()->full() : $coachUrl }}">Reload saved state</a>
    </div>
    <div class="arcade-stage">
        <div data-arcade-field class="arcade-field" tabindex="0" role="application" aria-label="Arcade exhibition. WASD or arrow keys move. Click a target to pass, or hold and drag to aim. Q or E juke. Escape pauses." aria-describedby="exhibition-controls"></div>
        <p data-exhibition-empty class="arcade-overlay arcade-empty">{{ ($replayOnly ?? false) ? 'Choose Replay recorded play to watch this saved play.' : ($available ? 'Choose Run or Pass, then Reserve play to load the field.' : 'Choose Continue in Coach before reserving another play.') }}</p>
        <div class="arcade-toolbar">
            <button type="button" data-exhibition-start disabled>Start play</button>
            <button type="button" data-exhibition-pause disabled>Pause</button>
            <button type="button" data-exhibition-cancel disabled>Cancel reserved play</button>
            <button type="button" data-exhibition-submit disabled>Submit / retry same result</button>
            <button type="button" data-exhibition-replay disabled>Replay recorded play</button>
        </div>
    </div>
    <p data-exhibition-status role="status" aria-live="polite">{{ $available ? 'Choose Run or Pass and reserve your play.' : 'This game needs a Coach decision before Arcade offense can continue.' }}</p>
    <p id="exhibition-controls" class="arcade-help">WASD / arrows: move · Click target: pass · Hold + drag: aim · Q / E: juke · Space: start/resume · Escape: pause</p>
    <p class="arcade-help">92.5% movement speed. Cyan: line of scrimmage. Yellow: first-down and goal lines. White field lines: every 5 yards. Live plays continue when the game clock expires. A local recording timeout is not a football result: cancel and reserve again. After possession changes or a kick, try, or half decision, continue in Coach.</p>
</div>
</x-layouts.app>
