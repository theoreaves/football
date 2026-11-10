<x-layouts.app>
<div data-arcade data-drill="{{ json_encode(['appearance' => $appearance, 'runner' => $runner, 'defenders' => $defenders]) }}" class="arcade-shell">
    <header class="arcade-top">
        <div><p class="arcade-eyebrow">WebSports Arcade · Running drill</p><h1>One gap. Your move.</h1></div>
        <p>Beat the defender. Find the end zone. A keyboard practice drill, separate from your games and season.</p>
    </header>
    @if($errors->any())<p class="arcade-notice" role="alert">{{ $errors->first() }}</p>@endif
    @if($teams->isNotEmpty())
    <form action="{{ route('arcade.drill') }}" method="GET" class="arcade-setup">
        @foreach(['home' => 'Running team', 'away' => 'Defending team'] as $side => $label)
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
    @elseif($runner)
        <div class="arcade-stage">
            <div class="arcade-scoreboard">
                <div class="arcade-runner"><strong>#{{ $runner['number'] }} {{ $runner['name'] }}</strong><small>{{ $home->city }} {{ $home->name }} · SPD {{ $runner['ratings']['speed'] }} / ACC {{ $runner['ratings']['acceleration'] }}</small></div>
                <div class="arcade-metrics">
                    <div class="arcade-metric"><span>Yards</span><strong data-arcade-yards>+0</strong></div>
                    <div class="arcade-metric"><span>Live time</span><strong data-arcade-time>0.0s</strong></div>
                    <div class="arcade-metric"><span>Juke</span><strong data-arcade-juke data-ready="true">READY</strong></div>
                </div>
            </div>
            <div data-arcade-field class="arcade-field" tabindex="0" role="application" aria-label="Running drill. Start run, then use W A S D or arrows to move, Q or E to juke, Escape to pause." aria-describedby="arcade-controls"></div>
            <div data-arcade-overlay class="arcade-overlay"><h2 data-arcade-heading>FIND YOUR GAP</h2><p data-arcade-detail>One defender. Thirty yards to the end zone. Make your move.</p></div>
            <div class="arcade-toolbar">
                <button data-arcade-start class="arcade-primary" type="button">Start run</button>
                <button data-arcade-pause type="button" disabled>Pause</button>
                <button data-arcade-retry type="button">Retry</button>
                <label class="arcade-scenario">Challenge<select data-arcade-defenders aria-label="Number of defenders"><option value="1">1 defender</option><option value="2" @disabled(count($defenders) < 2)>2 defenders</option></select></label>
            </div>
        </div>
        <div class="arcade-footer">
            <div id="arcade-controls" class="arcade-help"><span><kbd>WASD</kbd> / <kbd>↑ ↓ ← →</kbd> Move</span><span><kbd>Q</kbd> / <kbd>E</kbd> Juke up/down</span><span><kbd>Space</kbd> Start / resume</span><span><kbd>Esc</kbd> Pause · <kbd>R</kbd> Retry</span></div>
            <p>No saved stats. No injuries. Just run.</p>
        </div>
        <p data-arcade-status class="text-sm text-slate-300" role="status" aria-live="polite">Loading your drill…</p>
    @endif
</div>
</x-layouts.app>
