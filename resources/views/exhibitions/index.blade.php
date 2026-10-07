<x-layouts.app>
<div class="max-w-5xl mx-auto p-6 text-white space-y-6">
    <h1 class="text-3xl font-semibold">Play an exhibition</h1>
    <p class="text-gray-400">Choose Human or CPU control for each team and watch the results. Rosters and engine ratings are captured when the game starts.</p>
    @if($errors->any())<div class="bg-red-950 p-4 rounded">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('exhibitions.store') }}" class="flex flex-wrap gap-4 items-end bg-gray-800 p-5 rounded-xl">
        @csrf
        @foreach(['home' => 'Home', 'away' => 'Away'] as $side => $label)
            <label>{{ $label }}<select name="{{ $side }}" required class="block bg-gray-900 text-white rounded p-2 mt-1">
                @foreach($teams as $team)<option value="{{ $team->id }}" @selected((string) old($side, $side === 'home' ? $teams->first()?->id : $teams->skip(1)->first()?->id) === (string) $team->id)>{{ $team->city }} {{ $team->name }}</option>@endforeach
            </select></label>
            <label>{{ $label }} control<select name="{{ $side }}_control" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="human" @selected(old($side.'_control', 'human') === 'human')>Human</option><option value="cpu" @selected(old($side.'_control') === 'cpu')>CPU</option></select></label>
        @endforeach
        <label>Stadium fullness (%)<input type="number" name="crowd_fullness" min="0" max="100" step="1" value="{{ old('crowd_fullness', 80) }}" class="block bg-gray-900 text-white rounded p-2 mt-1 w-28" required></label>
        <label>Visiting fans (% of crowd)<input type="number" name="visiting_fans" min="0" max="100" step="1" value="{{ old('visiting_fans', 10) }}" class="block bg-gray-900 text-white rounded p-2 mt-1 w-28" required></label>
        <label>Quarter length<select name="quarter_length" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="180">Quick game · 3 minutes</option><option value="900">Full game · 15 minutes</option></select></label>
        <input type="hidden" name="penalties" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="penalties" value="1" @checked(old('penalties', true))>Penalties</label>
        <input type="hidden" name="injuries" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="injuries" value="1" @checked(old('injuries', true))>Injuries</label>
        <button class="bg-blue-700 rounded px-5 py-2" @disabled($teams->count() < 2)>Start game</button>
    </form>
    <p class="text-sm text-gray-400">First version: kickoffs, extra-point attempts, and a simplified clock, clock management and common scrimmage penalties; depth chart substitutions, player fatigue and exhibition injuries. Injuries reset for each new exhibition; no overtime. Ties stand.</p>
    <div class="space-y-3">
        @forelse($games as $game)
            <a href="{{ route('exhibitions.show', $game) }}" class="block bg-gray-800 rounded p-4 hover:bg-gray-700">{{ $game->awayTeam->name }} {{ $game->state['away_score'] }} at {{ $game->homeTeam->name }} {{ $game->state['home_score'] }} <span class="text-gray-400 ml-4">{{ $game->state['status'] === 'final' ? 'Final' : 'Resume · Q'.$game->state['quarter'] }}</span></a>
        @empty<p class="text-gray-400">No exhibitions yet. Use a demo save for complete rosters.</p>@endforelse
    </div>
    {{ $games->links() }}
</div>
</x-layouts.app>
