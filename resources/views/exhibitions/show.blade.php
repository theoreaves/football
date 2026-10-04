<x-layouts.app>
@php($state = $exhibition->state)
<div data-practice data-exhibition data-next-line="{{ $state['possession'] === 'home' ? 10 + $state['spot'] : 110 - $state['spot'] }}" data-next-possession="{{ $state['possession'] }}" data-next-distance="{{ $state['distance'] }}" data-camera-key="football-camera-{{ $exhibition->world_id }}-{{ $exhibition->id }}" data-play-number="{{ $state['version'] }}" data-animation="{{ json_encode($animation) }}" data-appearance="{{ json_encode($appearance) }}" data-autoplay="{{ request('watch') === '1' ? 'true' : 'false' }}" class="max-w-7xl mx-auto p-6 text-white space-y-4">
    <div class="flex flex-wrap justify-between gap-4 items-center">
        <div><a href="{{ route('exhibitions.index') }}" class="text-blue-300 text-sm">Exhibitions</a><h1 class="text-2xl font-semibold">{{ $exhibition->awayTeam->name }} {{ $state['away_score'] }} — {{ $exhibition->homeTeam->name }} {{ $state['home_score'] }}</h1></div>
        <p class="text-xl">{{ $state['status'] === 'final' ? 'FINAL' : 'Q'.$state['quarter'].' · '.gmdate('i:s', $state['clock']) }}</p>
    </div>
    <p class="text-gray-300">{{ $state['possession'] === 'home' ? $exhibition->homeTeam->name : $exhibition->awayTeam->name }} ball · Down {{ $state['down'] }} &amp; {{ $state['distance'] }} · {{ $state['spot'] <= 50 ? 'Own '.$state['spot'] : 'Opponent '.(100-$state['spot']) }} yard line</p>
    @if($errors->any())<p class="text-red-300">{{ $errors->first() }}</p>@endif
    @if($state['status'] === 'playing')
    <form data-call-form method="POST" action="{{ route('exhibitions.play', $exhibition) }}" class="flex flex-wrap gap-4 items-end bg-gray-800 rounded-xl p-4">
        @csrf<input type="hidden" name="version" value="{{ $state['version'] }}">
        <label>Offense formation<select name="offense_formation" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach(\App\Services\Simulation\ExhibitionEngine::OFFENSE_FORMATIONS as $value => $label)<option value="{{ $value }}" @selected(($last['offense_formation'] ?? 'shotgun') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Offense play<select name="call" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach(\App\Services\Simulation\ExhibitionEngine::OFFENSE as $call)<option value="{{ $call }}" @selected($last && $last['call'] === $call)>{{ ucwords(str_replace('_', ' ', $call)) }}</option>@endforeach</select></label>
        <label>Defense formation<select name="defense_formation" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach(\App\Services\Simulation\ExhibitionEngine::DEFENSE_FORMATIONS as $value => $label)<option value="{{ $value }}" @selected(($last['defense_formation'] ?? 'base_4_3') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Defense call<select name="defense" class="block bg-gray-900 text-white rounded p-2 mt-1">@foreach(\App\Services\Simulation\ExhibitionEngine::DEFENSE as $call)<option value="{{ $call }}" @selected($last && $last['defense'] === $call)>{{ ucwords(str_replace('_', ' ', $call)) }}</option>@endforeach</select></label>
        <button data-snap class="bg-blue-700 rounded px-6 py-2">Call play &amp; watch</button><p class="text-xs text-gray-400">You call both teams. Results save at the snap; replaying changes no stats.</p>
    </form>
    @endif
    @if($last)
    <div data-result-popup hidden role="status" class="fixed z-50 bottom-8 left-1/2 -translate-x-1/2 bg-gray-800 text-white border border-blue-400 rounded-xl shadow-xl p-5 w-full max-w-lg text-center">
        <h2 class="text-lg font-semibold text-blue-300">Play result</h2>
        <p class="mt-2">{{ $last['summary'] }}</p>
        <p class="mt-2 text-sm text-gray-400">{{ $exhibition->awayTeam->name }} {{ $state['away_score'] }} — {{ $exhibition->homeTeam->name }} {{ $state['home_score'] }}</p>
    </div>
    @endif
    @if($last && ($last['before']['quarter'] !== $last['after']['quarter'] || $last['after']['status'] === 'final'))
    <dialog data-quarter-dialog class="m-auto bg-gray-800 text-white rounded-xl border border-gray-600 p-6 max-w-md backdrop:bg-black/70">
        <h2 class="text-2xl font-semibold">{{ $last['after']['status'] === 'final' ? 'Final whistle' : ($last['before']['quarter'] === 2 ? 'Halftime' : 'End of quarter '.$last['before']['quarter']) }}</h2>
        <p class="mt-3">{{ $exhibition->awayTeam->name }} {{ $state['away_score'] }} — {{ $exhibition->homeTeam->name }} {{ $state['home_score'] }}</p>
        <p class="mt-3 text-gray-300">{{ $last['after']['status'] === 'final' ? 'The exhibition is complete.' : ($last['before']['quarter'] === 2 ? 'The away team receives to start the second half.' : 'Quarter '.$state['quarter'].' is ready. Possession and field position carry over.') }}</p>
        <form method="dialog"><button class="bg-blue-700 rounded px-5 py-2 mt-5">{{ $last['after']['status'] === 'final' ? 'View final result' : 'Continue' }}</button></form>
    </dialog>
    @endif
    <div class="flex flex-wrap gap-3 items-center">
        <label>Camera<select data-camera class="bg-gray-800 text-white rounded p-2 ml-2"><option value="broadcast">Broadcast</option><option value="overhead">Overhead</option></select></label>
        <label>Speed<select data-speed class="bg-gray-800 text-white rounded p-2 ml-2"><option value="0.5">Half</option><option value="1" selected>Normal</option><option value="2">Double</option></select></label>
        <button data-play class="bg-gray-700 rounded px-4 py-2">{{ $last ? 'Replay' : 'Play preview' }}</button><button data-reset class="border border-gray-600 rounded px-4 py-2">Reset replay</button><button data-reset-camera class="border border-gray-600 rounded px-4 py-2">Reset camera</button>
        <span class="text-xs text-gray-400">Drag to orbit · Scroll to zoom</span>
    </div>
    <div data-field class="h-[560px] min-h-[400px] w-full rounded-xl overflow-hidden border border-gray-700 bg-gray-950"></div>
    <div class="flex items-center gap-4"><label class="sr-only" for="play-timeline">Replay timeline</label><input id="play-timeline" data-timeline type="range" min="0" max="6" step="0.01" value="0" class="flex-1 accent-blue-400"><span data-time class="text-gray-400 text-sm">0.0 / 6.0s</span></div>
    <p data-status aria-live="polite" class="text-blue-300">Loading field…</p>
    @if($last)<p class="bg-gray-800 rounded p-3">Last play: {{ $last['summary'] }}</p>@endif
    <p class="text-sm text-gray-400">Home offense moves toward the right end zone; away offense toward the left. The scoreboard shows the saved result; the field replays the last play.</p>
    <div class="grid grid-cols-2 gap-4 text-sm">@foreach(['home', 'away'] as $side)<p>{{ $side === 'home' ? $exhibition->homeTeam->name : $exhibition->awayTeam->name }}: {{ $state['stats'][$side]['plays'] }} plays · {{ $state['stats'][$side]['yards'] }} yards · {{ $state['stats'][$side]['turnovers'] }} turnovers</p>@endforeach</div>
    <details><summary class="cursor-pointer text-gray-300">Play log ({{ count($exhibition->history) }})</summary><ol class="space-y-2 mt-3 text-sm text-gray-400">@foreach(array_reverse($exhibition->history) as $play)<li>#{{ $play['number'] }} · Q{{ $play['before']['quarter'] }} {{ gmdate('i:s', $play['before']['clock']) }} · {{ $play['before']['possession'] }} · {{ $play['summary'] }}</li>@endforeach</ol></details>
</div>
</x-layouts.app>
