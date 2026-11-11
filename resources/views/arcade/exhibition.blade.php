<x-layouts.app>
<div class="arcade-shell" data-arcade-exhibition data-exhibition="{{ json_encode(['flow'=>$flow ?? null,'flowUrl'=>$flowUrl ?? null,'state'=>$state,'available'=>$available,'coachUrl'=>$coachUrl,'startUrl'=>$startUrl,'lastRecording'=>$lastRecording ?? null,'pendingSession'=>$pendingSession ?? null,'replayOnly'=>$replayOnly ?? false,'selectedPlay'=>$selectedPlay ?? null,'csrf'=>csrf_token()]) }}">
    <header class="arcade-top"><div><p class="arcade-eyebrow">WebSports Arcade · Exhibition</p><h1>{{ ($replayOnly ?? false) ? 'Recorded exhibition play.' : 'Your exhibition game.' }}</h1></div><p>Real roster. Real recorded movement. Official game state updates only when your recorded play is accepted.</p></header>
    <div class="arcade-scoreboard"><strong data-exhibition-score></strong><span data-exhibition-state></span><span data-exhibition-live></span></div>
    @unless($replayOnly ?? false)
    <section class="arcade-setup" aria-label="Exhibition game flow">
        <label>Your team<select data-exhibition-side><option value="home">Home</option><option value="away">Away</option></select></label>
        <label>Defense<select data-exhibition-defense><option value="coach">Coach Defense</option><option value="watch">Watch Simulation</option><option value="fast">Fast Sim</option></select></label>
        <button type="button" data-exhibition-preferences>Start Play mode</button>
        <button type="button" data-exhibition-advance>Watch next play</button>
        <button type="button" data-exhibition-fast>Fast Sim to next decision</button>
        <button type="button" data-exhibition-timeout>Call timeout</button>
        <button type="button" data-exhibition-spike>Spike Ball</button>
        <button type="button" data-exhibition-retry-flow hidden>Retry same game decision</button>
    </section>
    <p data-exhibition-flow-status role="status"></p><p data-exhibition-clock></p>
    <p data-exhibition-notices role="status" aria-live="polite" style="white-space:pre-line"></p>
    @endunless
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
    <p class="arcade-help">92.5% movement speed. Cyan: line of scrimmage. Yellow: first-down and goal lines. White field lines: every 5 yards. Live plays continue when the game clock expires. A local recording timeout is not a football result: cancel and reserve again. Choose Coach Defense to call defensive plays, Watch Simulation to view committed 3D plays, or Fast Sim to reach your next offensive opportunity. Required kick, try, penalty and half decisions open in Coach, then return here. No game action runs simply by loading this page.</p>
</div>
</x-layouts.app>
