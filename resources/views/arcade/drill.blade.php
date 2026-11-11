<x-layouts.app>
@php
    $passing = $mode === 'passing';
    $controlledPlayer = $passing ? $quarterback : $runner;
    $speedScale = config('arcade.speed_scale', 0.925);
@endphp
<div data-arcade data-drill="{{ json_encode(['mode' => $mode, 'appearance' => $appearance, 'runner' => $runner, 'quarterback' => $quarterback, 'receivers' => $receivers, 'defenders' => $defenders, 'speedScale' => $speedScale]) }}" class="arcade-shell">
    <nav class="arcade-modes" aria-label="Arcade drills">
        @foreach(['running' => 'Running', 'passing' => 'Passing & receiving'] as $value => $label)
        <a href="{{ route('arcade.drill', array_filter(['mode' => $value, 'home' => $home?->id, 'away' => $away?->id])) }}" @if($mode === $value) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <header class="arcade-top">
        <div><p class="arcade-eyebrow">WebSports Arcade · {{ $passing ? 'Passing drill' : 'Running drill' }}</p><h1>{{ $passing ? 'Pick your window.' : 'One gap. Your move.' }}</h1></div>
        <p>{{ $passing ? 'Lead your receiver. Make the catch. Take it home. An isolated keyboard and mouse drill.' : 'Beat the defender. Find the end zone. A keyboard practice drill, separate from your games and season.' }}</p>
    </header>
    @if($errors->any())<p class="arcade-notice" role="alert">{{ $errors->first() }}</p>@endif
    @if($teams->isNotEmpty())
    <form action="{{ route('arcade.drill') }}" method="GET" class="arcade-setup">
        <input type="hidden" name="mode" value="{{ $mode }}">
        @foreach(['home' => 'Offense', 'away' => 'Defense'] as $side => $label)
        <label>{{ $label }}<select name="{{ $side }}">
            @foreach($teams as $team)<option value="{{ $team->id }}" @selected(${$side}?->id === $team->id)>{{ $team->city }} {{ $team->name }}</option>@endforeach
        </select></label>
        @endforeach
        <button type="submit">Load teams</button>
        <a class="arcade-exit" href="{{ route('practice') }}">Exit drill</a>
    </form>
    @endif
    @if($setupMessage)
        <div class="arcade-notice" role="status">{{ $setupMessage }} <a class="underline" href="{{ route('teams.index') }}">Open teams</a></div>
    @elseif($controlledPlayer)
        <div class="arcade-stage">
            <div class="arcade-scoreboard">
                <div class="arcade-runner"><strong data-arcade-carrier>#{{ $controlledPlayer['number'] }} {{ $controlledPlayer['name'] }}</strong><small data-arcade-carrier-info>{{ $home->city }} {{ $home->name }} · SPD {{ $controlledPlayer['ratings']['speed'] }} / ACC {{ $controlledPlayer['ratings']['acceleration'] }}</small><span data-arcade-phase class="arcade-phase">{{ $passing ? 'QB CONTROL · DRAG TO AIM' : 'RUNNING DRILL' }}</span></div>
                <div class="arcade-metrics">
                    <div class="arcade-metric"><span>Yards</span><strong data-arcade-yards>+0</strong></div>
                    <div class="arcade-metric"><span>Live time</span><strong data-arcade-time>0.0s</strong></div>
                    <div class="arcade-metric"><span>Juke</span><strong data-arcade-juke data-ready="true">READY</strong></div>
                </div>
            </div>
            <div data-arcade-field class="arcade-field" tabindex="0" role="application" aria-label="{{ $passing ? 'Passing drill. Start play, move with W A S D or arrows. Drag toward a target and release to throw. After a catch, control the receiver.' : 'Running drill. Start run, move with W A S D or arrows, Q or E to juke, Escape to pause.' }}" aria-describedby="arcade-controls"></div>
            <div data-arcade-overlay class="arcade-overlay"><h2 data-arcade-heading>{{ $passing ? 'PICK YOUR WINDOW' : 'FIND YOUR GAP' }}</h2><p data-arcade-detail>{{ $passing ? 'Follow a route. Lead your receiver. Drag toward the target and release.' : 'One defender. Thirty yards to the end zone. Make your move.' }}</p></div>
            <div class="arcade-toolbar">
                <button data-arcade-start class="arcade-primary" type="button">Start {{ $passing ? 'play' : 'run' }}</button>
                <button data-arcade-pause type="button" disabled>Pause</button>
                <button data-arcade-retry type="button">Retry</button>
                <button data-arcade-replay type="button" disabled>Replay</button>
                <button data-arcade-recording type="button" disabled title="Download this attempt for local replay playback.">Replay data</button>
                <button data-arcade-import type="button">Open replay file</button>
                <input data-arcade-import-file type="file" accept=".json,application/json" aria-label="Open local Arcade recording" hidden>
                <label class="arcade-scenario">Challenge<select data-arcade-defenders aria-label="Number of defenders"><option value="1">1 defender</option><option value="2" @disabled(count($defenders) < 2)>2 defenders</option></select></label>
            </div>
        </div>
        <div class="arcade-drill-options">
            @if($passing)
            <label>Routes<select data-arcade-routes aria-label="Receiver routes"><option value="slants">Slants</option><option value="comeback">Comebacks</option></select></label>
            <label>Targets<select data-arcade-receivers aria-label="Number of receivers"><option value="1">1 receiver</option><option value="2" @selected(count($receivers) >= 2) @disabled(count($receivers) < 2)>2 receivers</option></select></label>
            @endif
            <label>Movement speed<select data-arcade-speed aria-label="Movement speed">
                @if(!in_array((float) $speedScale, [0.9, 0.925, 0.95, 1.0], true))<option value="{{ $speedScale }}" selected>{{ round($speedScale * 100, 1) }}% · Configured</option>@endif
                @foreach(['0.9' => '90% · Relaxed', '0.925' => '92.5% · Recommended', '0.95' => '95% · Quick', '1' => '100% · Phase 1 pace'] as $value => $label)<option value="{{ $value }}" @selected((float) $speedScale === (float) $value)>{{ $label }}</option>@endforeach
            </select></label>
            <label>Automatic replays<select data-arcade-highlights aria-label="Automatic replays"><option value="off" selected>Off</option><option value="major">Touchdowns and interceptions</option><option value="all">All highlights</option></select></label>
            <span>Retry repeats the same setup. No saved game changes.</span>
        </div>
        <div class="arcade-footer">
            <div id="arcade-controls" class="arcade-help"><span><kbd>WASD</kbd> / <kbd>↑ ↓ ← →</kbd> Move</span>@if($passing)<span><kbd>Drag → release</kbd> Aim / throw</span>@endif<span><kbd>Q</kbd> / <kbd>E</kbd> Juke</span><span><kbd>Space</kbd> Start / resume</span><span><kbd>Esc</kbd> Pause · <kbd>R</kbd> Retry</span></div>
            <p>No saved stats. No injuries. Just play.</p>
        </div>
        <p data-arcade-status class="text-sm text-slate-300" role="status" aria-live="polite">Loading your drill…</p>
        <details class="arcade-diagnostics"><summary>Diagnostics</summary><p data-arcade-diagnostics>Measuring local rendering…</p><p>Replays use recorded motion only. Automatic replays are off by default and can always be skipped. All highlights includes gains of 25+ yards and goal-line or broken-tackle stops. Replay files stay on this device.</p></details>
    @endif
</div>
</x-layouts.app>
