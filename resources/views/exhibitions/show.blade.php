<x-layouts.app :immersive="true">
@php
    $state = $exhibition->state;
    $watching = request('watch') === '1' && $last;
    $shown = $watching ? $last['before'] : $state;
    $teamNames = ['home' => $exhibition->homeTeam->name, 'away' => $exhibition->awayTeam->name];
@endphp
<div data-practice data-exhibition data-cpu-special="{{ $cpuPlan && in_array($cpuPlan['call'], ['punt', 'field_goal', 'kickoff', 'extra_point'], true) ? 'true' : 'false' }}" data-cpu-only="{{ $cpuOffense && $cpuDefense ? 'true' : 'false' }}" data-before-state="{{ json_encode($last['before'] ?? $state) }}" data-after-state="{{ json_encode($state) }}" data-team-names="{{ json_encode($teamNames) }}" data-defense-options="{{ json_encode($defenseOptions) }}" data-next-line="{{ $state['possession'] === 'home' ? 10 + $state['spot'] : 110 - $state['spot'] }}" data-next-possession="{{ $state['possession'] }}" data-next-distance="{{ $state['distance'] }}" data-camera-key="football-camera-{{ $exhibition->world_id }}-{{ $exhibition->id }}" data-play-number="{{ $state['version'] }}" data-animation="{{ json_encode($animation) }}" data-appearance="{{ json_encode($appearance) }}" data-autoplay="{{ request('watch') === '1' ? 'true' : 'false' }}" class="game-stage text-white">
    <div class="game-brand-watermark" aria-hidden="true"><x-brand-logo /></div>
    <div class="game-scoreboard">
        <div><a href="{{ route('exhibitions.index') }}" class="text-blue-300 text-sm">Exhibitions</a><h1 data-scoreboard class="text-2xl font-semibold">{{ $exhibition->awayTeam->name }} {{ $shown['away_score'] }} — {{ $exhibition->homeTeam->name }} {{ $shown['home_score'] }}</h1></div>
        <p data-clock class="text-xl">{{ $shown['status'] === 'final' ? 'FINAL' : 'Q'.$shown['quarter'].' · '.gmdate('i:s', $shown['clock']) }}</p>
    </div>
    <p data-situation class="game-situation">{{ $teamNames[$shown['possession']] }} · {{ match($shown['phase'] ?? 'scrimmage') { 'kickoff' => 'Kickoff', 'extra_point' => 'Try · 1-point kick or 2-point play', default => 'Down '.$shown['down'].' & '.$shown['distance'].' · '.($shown['spot'] <= 50 ? 'Own '.$shown['spot'] : 'Opponent '.(100-$shown['spot'])).' yard line' } }}</p>
    <p class="game-coaches">{{ $teamNames['home'] }}: {{ strtoupper($controls['home']) }} · {{ $teamNames['away'] }}: {{ strtoupper($controls['away']) }}</p>
    <p data-clock-management class="game-clock-management">Timeouts: {{ $teamNames['home'] }} {{ $shown['timeouts']['home'] ?? 3 }} · {{ $teamNames['away'] }} {{ $shown['timeouts']['away'] ?? 3 }} · {{ ($shown['clock_running'] ?? false) ? 'Clock running' : 'Clock stopped' }}@if($shown['untimed_down'] ?? false) · Untimed down @endif</p>
    @if($errors->any())<p class="text-red-300">{{ $errors->first() }}</p>@endif
    @if($state['status'] === 'playing' && !($state['penalty_pending'] ?? false))
    <form data-call-form @if($watching) hidden @endif method="POST" action="{{ route('exhibitions.play', $exhibition) }}" class="game-play-panel flex flex-wrap gap-3 items-end">
        @csrf<input type="hidden" name="version" value="{{ $state['version'] }}">
        @unless($cpuOffense)
        <label>Tempo<select name="tempo" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="normal">Normal</option><option value="hurry">Hurry-up</option><option value="drain">Run the clock</option></select></label>
        <label>Clock strategy<select name="clock_strategy" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="normal">Normal finish</option>@if(app(\App\Services\Simulation\GameClock::class)->lateHalf($state))<option value="sideline">Try to get out of bounds</option>@endif</select></label>
        <label>Offense formation<select data-formation name="offense_formation" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach(\App\Services\Simulation\ExhibitionEngine::OFFENSE_FORMATIONS as $value => $label)<option value="{{ $value }}" @selected(($last['offense_formation'] ?? 'shotgun') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Offense play<select name="call" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach($calls as $call)<option value="{{ $call }}" @selected($last && $last['call'] === $call)>{{ match($call) { 'kneel' => 'QB kneel', 'extra_point' => '1-point kick', 'two_point_run' => '2-point run', 'two_point_pass' => '2-point pass', default => ucwords(str_replace('_', ' ', $call)) } }}</option>@endforeach</select></label>
        @else
        <p class="text-sm">{{ $teamNames[$offenseSide] }} offense: CPU @if(in_array($cpuPlan['call'], ['punt', 'field_goal', 'kickoff', 'extra_point'], true)) · {{ ucwords(str_replace('_', ' ', $cpuPlan['call'])) }}@endif</p>
        @endunless
        @unless($cpuDefense)
        <label>Defense formation<select data-formation name="defense_formation" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach(\App\Services\Simulation\ExhibitionEngine::DEFENSE_FORMATIONS as $value => $label)<option value="{{ $value }}" @selected(($last['defense_formation'] ?? 'base_4_3') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Defense call<select name="defense" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach($humanDefenseOptions as $call)<option value="{{ $call }}" @selected($last && $last['defense'] === $call)>{{ match($call) { 'kneel' => 'QB kneel', 'extra_point' => '1-point kick', 'two_point_run' => '2-point run', 'two_point_pass' => '2-point pass', default => ucwords(str_replace('_', ' ', $call)) } }}</option>@endforeach</select></label>
        @else
        <p class="text-sm">{{ $teamNames[$defenseSide] }} defense: CPU</p>
        @endunless
        <button data-snap class="bg-blue-700 rounded px-6 py-2">{{ $cpuOffense && $cpuDefense ? 'Next CPU play' : 'Call play & watch' }}</button><p class="text-xs text-gray-400">{{ $cpuOffense || $cpuDefense ? 'CPU calls are chosen automatically.' : 'You call both teams.' }} Results save at the snap; replaying changes no stats.</p>
    </form>
    @endif
    @if($state['status'] === 'playing' && $state['clock_running'] && !($state['penalty_pending'] ?? false))
    <div data-hidden-result @if($watching) hidden @endif class="game-timeouts flex flex-wrap gap-3">
    @foreach(['home', 'away'] as $timeoutSide)
        @if($controls[$timeoutSide] === 'human' && $state['timeouts'][$timeoutSide] > 0)
        <form method="POST" data-timeout-form action="{{ route('exhibitions.play', $exhibition) }}">@csrf<input type="hidden" name="version" value="{{ $state['version'] }}"><input type="hidden" name="action" value="timeout"><input type="hidden" name="timeout_team" value="{{ $timeoutSide }}"><button class="border border-gray-500 rounded px-4 py-2">{{ $teamNames[$timeoutSide] }} timeout ({{ $state['timeouts'][$timeoutSide] }})</button></form>
        @endif
    @endforeach
    </div>
    @endif
    @if($cpuOffense && $cpuDefense && $state['status'] === 'playing')
    <button type="button" data-cpu-toggle class="game-cpu-toggle border border-blue-400 rounded px-5 py-2">Start CPU game</button><span data-cpu-status class="game-cpu-status text-sm text-gray-400">Paused between plays</span>
    @endif
    @if($last)
    <div data-result-popup hidden role="status" class="fixed z-50 bottom-8 left-1/2 -translate-x-1/2 bg-gray-800 text-white border border-blue-400 rounded-xl shadow-xl p-5 w-full max-w-lg text-center">
        <h2 class="text-lg font-semibold text-blue-300">Play result</h2>
        <p class="mt-2">{{ $last['summary'] }}</p>
        <button type="button" data-result-ok class="bg-blue-700 rounded px-5 py-2 mt-3">OK · Continue</button>
        <p class="mt-2 text-sm text-gray-400">{{ $exhibition->awayTeam->name }} {{ $state['away_score'] }} — {{ $exhibition->homeTeam->name }} {{ $state['home_score'] }}</p>
    </div>
    @endif
    @if($last && !empty($last['personnel_notices']))
    <dialog data-injury-dialog class="m-auto bg-gray-800 text-white rounded-xl border border-orange-400 p-6 max-w-xl backdrop:bg-black/70">
        <h2 class="text-2xl font-semibold text-orange-300">Player availability</h2>
        @foreach($last['personnel_notices'] as $notice)<p class="mt-3">{{ $notice }}</p>@endforeach
        <form method="dialog"><button class="bg-blue-700 rounded px-5 py-2 mt-5">OK</button></form>
    </dialog>
    @endif
    <button type="button" data-hidden-result @if($watching) hidden @endif data-open-personnel class="fixed bottom-4 right-4 z-30 bg-gray-800 border border-gray-600 rounded px-4 py-2">Depth / injuries</button>
    <dialog data-personnel-dialog class="m-auto bg-gray-800 text-white rounded-xl border border-gray-600 p-6 w-full max-w-4xl max-h-[85vh] overflow-y-auto backdrop:bg-black/70">
        <div class="flex justify-between items-center gap-4"><h2 class="text-xl font-semibold">Depth chart and availability</h2><form method="dialog"><button class="border rounded px-3 py-2">Close</button></form></div>
        <p class="text-sm text-gray-300 mt-3">Lowest depth number starts. Tired players rotate with a rested backup; injuries force replacements. Fatigue lowers performance by up to 25%. Edit player stamina, durability and depth in Teams before starting a new exhibition.</p>
        @foreach($personnel as $side => $roster)
            <h3 class="font-semibold text-lg mt-5">{{ $teamNames[$side] }}</h3>
            @if(!isset($roster['pool']))<p class="text-orange-300">Start a new exhibition to capture backups and enable injuries.</p>@endif
            <table class="w-full text-sm mt-3"><thead><tr class="text-left"><th class="p-2">Role</th><th class="p-2">Player</th><th class="p-2">Fatigue</th><th class="p-2">Status</th></tr></thead><tbody>
            @foreach($roster['pool'] ?? $roster['players'] as $player)
                @php
                    $roles = array_keys(array_filter($roster['players'], fn ($active) => $active['id'] === $player['id']));
                    $injury = $state['injuries'][$side][$player['id']] ?? null;
                @endphp
                <tr class="border-t border-gray-700"><td class="p-2">{{ $player['depth'] ?? implode(', ', $roles) }}</td><td class="p-2">#{{ $player['number'] }} {{ $player['name'] }}</td><td class="p-2">{{ round($state['fatigue'][$side][$player['id']] ?? 0) }}%</td><td class="p-2">{{ $injury ? ($injury['return_snap'] === null ? 'Out for game' : 'Out · '.max(0, $injury['return_snap'] - ($state['personnel_snaps'] ?? 0)).' snaps') : ($roles ? 'Active · '.implode(', ', $roles) : 'Backup') }}</td></tr>
            @endforeach
            </tbody></table>
        @endforeach
    </dialog>
    @if($last && isset($last['penalty']) && !($last['penalty']['decided'] ?? false))
    <dialog data-penalty-dialog class="m-auto bg-gray-800 text-white rounded-xl border border-yellow-400 p-6 max-w-xl backdrop:bg-black/70">
        <h2 class="text-2xl font-semibold text-yellow-300">Flag: {{ ucwords(str_replace('_', ' ', $last['penalty']['type'])) }}</h2>
        <p class="mt-3">On {{ $teamNames[$last['penalty']['team']] }}.</p>
        <p class="mt-3 text-gray-300">{{ $last['penalty']['explanation'] }}</p>
        @if(!($last['no_snap'] ?? false) && isset($last['penalty']['play_result']))<p class="mt-3 text-gray-300">Play result: {{ $last['penalty']['play_result'] }}</p>@endif
        <p class="mt-3 text-sm text-gray-400">Yardage is reduced to half the distance to the goal when needed.</p>
        @if($state['penalty_pending'] ?? false)
            <p class="mt-3">{{ $teamNames[$last['penalty']['beneficiary']] }} chooses:</p>
            <div class="grid gap-3 mt-4">
            @foreach(['accept' => 'Accept', 'decline' => 'Decline'] as $decision => $label)
                @php($option = $last['penalty_options'][$decision]['state'])
                <form data-penalty-form method="POST" action="{{ route('exhibitions.play', $exhibition) }}">@csrf
                    <input type="hidden" name="version" value="{{ $state['version'] }}"><input type="hidden" name="action" value="penalty"><input type="hidden" name="decision" value="{{ $decision }}">
                    <button class="bg-blue-700 rounded px-5 py-2">{{ $label }}</button>
                    <span class="ml-2 text-sm">{{ $teamNames[$option['possession']] }} · Down {{ $option['down'] }} & {{ $option['distance'] }} · {{ $option['spot'] <= 50 ? 'Own '.$option['spot'] : 'Opponent '.(100-$option['spot']) }} · Score {{ $option['away_score'] }}–{{ $option['home_score'] }}</span>
                </form>
            @endforeach
            </div>
        @else
            <p class="mt-3">{{ $teamNames[$last['penalty']['beneficiary']] }} CPU {{ $last['penalty']['accepted'] ? 'accepted' : 'declined' }} the penalty.</p>
            <form method="dialog"><button class="bg-blue-700 rounded px-5 py-2 mt-5">OK</button></form>
        @endif
    </dialog>
    @endif
    @if($last && (($last['two_minute_warning'] ?? false) || $last['before']['quarter'] !== $last['after']['quarter'] || $last['after']['status'] === 'final'))
    <dialog data-quarter-dialog class="m-auto bg-gray-800 text-white rounded-xl border border-gray-600 p-6 max-w-md backdrop:bg-black/70">
        <h2 class="text-2xl font-semibold">{{ $last['after']['status'] === 'final' ? 'Final whistle' : (($last['two_minute_warning'] ?? false) ? 'Two-minute warning' : ($last['before']['quarter'] === 2 ? 'Halftime' : 'End of quarter '.$last['before']['quarter'])) }}</h2>
        <p class="mt-3">{{ $exhibition->awayTeam->name }} {{ $state['away_score'] }} — {{ $exhibition->homeTeam->name }} {{ $state['home_score'] }}</p>
        <p class="mt-3 text-gray-300">{{ $last['after']['status'] === 'final' ? 'The exhibition is complete.' : (($last['two_minute_warning'] ?? false) ? 'The clock is stopped. Choose your clock strategy for the rest of the half.' : ($last['before']['quarter'] === 2 ? 'The away team receives to start the second half.' : 'Quarter '.$state['quarter'].' is ready. Possession and field position carry over.')) }}</p>
        <form method="dialog"><button class="bg-blue-700 rounded px-5 py-2 mt-5">{{ $last['after']['status'] === 'final' ? 'View final result' : 'Continue' }}</button></form>
    </dialog>
    @endif
    <div class="game-camera-controls flex flex-wrap gap-3 items-center">
        <label>Camera<select data-camera class="bg-gray-800 text-white rounded p-2 ml-2"><option value="broadcast">Broadcast</option><option value="overhead">Overhead</option><option value="quarterback">Behind QB</option></select></label>
        <label>Speed<select data-speed class="bg-gray-800 text-white rounded p-2 ml-2"><option value="0.5">Half</option><option value="1" selected>Normal</option><option value="2">Double</option></select></label>
        <button data-play class="bg-gray-700 rounded px-4 py-2">{{ $last ? 'Replay' : 'Play preview' }}</button><button data-reset class="border border-gray-600 rounded px-4 py-2">Reset replay</button><button data-reset-camera class="border border-gray-600 rounded px-4 py-2">Reset camera</button>
        <span class="text-xs text-gray-400">Drag to orbit · Scroll to zoom</span>
    </div>
    <div data-field class="game-field"></div>
    <details class="game-audio absolute right-4 top-36 z-20 bg-gray-900/90 border border-gray-600 rounded p-3 text-sm">
        <summary class="cursor-pointer">Stadium sound</summary>
        <button type="button" data-sound-toggle class="border border-gray-500 rounded px-3 py-1 mt-2" aria-pressed="true">Sound on</button>
        @foreach(['master' => 'Master volume', 'crowd' => 'Crowd volume', 'effects' => 'Effects and stadium cues'] as $channel => $label)
        <label class="block mt-2">{{ $label }}<input data-sound-volume="{{ $channel }}" type="range" min="0" max="100" step="1" class="block w-48 accent-blue-400"></label>
        @endforeach
    </details>
    <div class="game-timeline flex items-center gap-4"><label class="sr-only" for="play-timeline">Replay timeline</label><input id="play-timeline" data-timeline type="range" min="0" max="6" step="0.01" value="0" class="flex-1 accent-blue-400"><span data-time class="text-gray-400 text-sm">0.0 / 6.0s</span></div>
    <p data-status aria-live="polite" class="game-status text-blue-300">Loading field…</p>
    @if($last)<p data-hidden-result @if($watching) hidden @endif class="game-last-result">Last play: {{ $last['summary'] }}</p>@endif
    <p class="game-help text-sm text-gray-400">Home offense moves toward the right end zone; away offense toward the left. The scoreboard updates when the replay reveals the result.</p>
    <div class="game-stats grid grid-cols-2 gap-4 text-sm">@foreach(['home', 'away'] as $side)<p data-stats="{{ $side }}">{{ $side === 'home' ? $exhibition->homeTeam->name : $exhibition->awayTeam->name }}: {{ $shown['stats'][$side]['plays'] }} plays · {{ $shown['stats'][$side]['yards'] }} yards · {{ $shown['stats'][$side]['turnovers'] }} turnovers · {{ $shown['stats'][$side]['penalties'] ?? 0 }} penalties / {{ $shown['stats'][$side]['penalty_yards'] ?? 0 }} yards</p>@endforeach</div>
    <div class="game-actions"><button type="button" data-open-log>Play log (<span data-log-count>{{ count($exhibition->history) - ($watching ? 1 : 0) }}</span>)</button><button type="button" data-open-box>Box score</button><button type="button" data-fullscreen>Full screen</button></div>
    <dialog data-log-dialog class="game-dialog"><form method="dialog"><button class="float-right">Close</button></form><h2 class="text-2xl font-semibold mb-4">Play log</h2>
<ol class="space-y-2 mt-3 text-sm text-gray-400">@foreach(array_reverse($exhibition->history) as $play)<li @if($loop->first) data-hidden-result @if($watching) hidden @endif @endif>#{{ $play['number'] }} · Q{{ $play['before']['quarter'] }} {{ gmdate('i:s', $play['before']['clock']) }} · {{ $play['before']['possession'] }} · {{ $play['summary'] }}</li>@endforeach</ol>
    </dialog>
    <dialog data-box-dialog class="game-dialog"><form method="dialog"><button class="float-right">Close</button></form>@include('exhibitions.box-score')</dialog>

</div>
</x-layouts.app>
