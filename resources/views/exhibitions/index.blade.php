<x-layouts.app>
<div class="max-w-5xl mx-auto p-6 text-white space-y-6">
    <h1 class="text-3xl font-semibold">Play an exhibition</h1>
    <p class="text-gray-400">Call plays for both teams and watch the results. Rosters and engine ratings are captured when the game starts.</p>
    @if($errors->any())<div class="bg-red-950 p-4 rounded">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('exhibitions.store') }}" class="flex flex-wrap gap-4 items-end bg-gray-800 p-5 rounded-xl">
        @csrf
        @foreach(['home' => 'Home', 'away' => 'Away'] as $side => $label)
            <label>{{ $label }}<select name="{{ $side }}" required class="block bg-gray-900 text-white rounded p-2 mt-1">
                @foreach($teams as $team)<option value="{{ $team->id }}" @selected((string) old($side, $side === 'home' ? $teams->first()?->id : $teams->skip(1)->first()?->id) === (string) $team->id)>{{ $team->city }} {{ $team->name }}</option>@endforeach
            </select></label>
        @endforeach
        <label>Quarter length<select name="quarter_length" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="180">Quick game · 3 minutes</option><option value="900">Full game · 15 minutes</option></select></label>
        <button class="bg-blue-700 rounded px-5 py-2" @disabled($teams->count() < 2)>Start game</button>
    </form>
    <p class="text-sm text-gray-400">First version: automatic touchbacks and extra points, simplified clock, no penalties, injuries, substitutions, or overtime. Ties stand.</p>
    <div class="space-y-3">
        @forelse($games as $game)
            <a href="{{ route('exhibitions.show', $game) }}" class="block bg-gray-800 rounded p-4 hover:bg-gray-700">{{ $game->awayTeam->name }} {{ $game->state['away_score'] }} at {{ $game->homeTeam->name }} {{ $game->state['home_score'] }} <span class="text-gray-400 ml-4">{{ $game->state['status'] === 'final' ? 'Final' : 'Resume · Q'.$game->state['quarter'] }}</span></a>
        @empty<p class="text-gray-400">No exhibitions yet. Use a demo save for complete rosters.</p>@endforelse
    </div>
</div>
</x-layouts.app>
