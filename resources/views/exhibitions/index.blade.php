<x-layouts.app>
<div class="max-w-5xl mx-auto p-6 text-white space-y-6">
    <header><p class="text-blue-300 text-sm uppercase tracking-widest">World overview</p><h1 class="text-3xl font-semibold mt-2">{{ $world->name }}</h1><p class="text-gray-400 mt-2">Your teams, games, and the next chapter of your football world.</p></header>
    <div class="world-modes">
        <a class="world-mode active" href="#exhibitions"><span class="mode-label">Play now</span><h2>Exhibitions</h2><p>Coach a matchup, watch the CPU, or Quick Sim to the final score.</p></a>
        <section class="world-mode"><span class="mode-label">Coming later</span><h2>Seasons</h2><p>A home for schedules, standings, and a full season of games.</p></section>
        <section class="world-mode"><span class="mode-label">Coming later</span><h2>Franchise</h2><p>A home for your team's story across multiple seasons.</p></section>
    </div>
    <div id="exhibitions"><h2 class="text-2xl font-semibold">Exhibitions</h2></div>
    @if(session('status'))<p role="status" class="text-blue-200">{{ session('status') }}</p>@endif
    <p class="text-gray-400">Choose Human or CPU control for each team and watch the results. Rosters and engine ratings are captured when the game starts.</p>
    @if($errors->any())<div class="bg-red-950 p-4 rounded">{{ $errors->first() }}</div>@endif
    <details class="creation-panel" @if($errors->any()) open @endif>
    <summary class="creation-toggle">Create new exhibition</summary>
    <form method="POST" action="{{ route('exhibitions.store') }}" class="flex flex-wrap gap-4 items-end bg-gray-800 p-5 rounded-xl">
        @csrf
        @foreach(['home' => 'Home', 'away' => 'Away'] as $side => $label)
            <label>{{ $label }}<select name="{{ $side }}" required class="block bg-gray-900 text-white rounded p-2 mt-1">
                @foreach($teams as $team)<option value="{{ $team->id }}" @selected((string) old($side, $side === 'home' ? $teams->first()?->id : $teams->skip(1)->first()?->id) === (string) $team->id)>{{ $team->city }} {{ $team->name }}</option>@endforeach
            </select></label>
            <label>{{ $label }} control<select name="{{ $side }}_control" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="human" @selected(old($side.'_control', 'human') === 'human')>Human</option><option value="cpu" @selected(old($side.'_control') === 'cpu')>CPU</option></select></label>
        @endforeach
        <label>Visitor coin call<select name="coin_call" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="heads" @selected(old('coin_call', 'heads') === 'heads')>Heads</option><option value="tails" @selected(old('coin_call') === 'tails')>Tails</option></select></label>
        <label>Stadium fullness (%)<input type="number" name="crowd_fullness" min="0" max="100" step="1" value="{{ old('crowd_fullness', 80) }}" class="block bg-gray-900 text-white rounded p-2 mt-1 w-28" required></label>
        <label>Visiting fans (% of crowd)<input type="number" name="visiting_fans" min="0" max="100" step="1" value="{{ old('visiting_fans', 10) }}" class="block bg-gray-900 text-white rounded p-2 mt-1 w-28" required></label>
        <label>Quarter length<select name="quarter_length" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="180" @selected((string) old('quarter_length', 180) === '180')>Quick game · 3 minutes</option><option value="300" @selected((string) old('quarter_length', 180) === '300')>5 minutes</option><option value="600" @selected((string) old('quarter_length', 180) === '600')>10 minutes</option><option value="900" @selected((string) old('quarter_length', 180) === '900')>Full game · 15 minutes</option></select></label>
        <label>Overtime<select name="overtime" class="block bg-gray-900 text-white rounded p-2 mt-1"><option value="none" @selected(old('overtime', 'none') === 'none')>No OT · ties stand</option><option value="traditional" @selected(old('overtime', 'none') === 'traditional')>Traditional · sudden death (10 min)</option><option value="modern" @selected(old('overtime', 'none') === 'modern')>Modern NFL · both teams get a chance (10 min)</option><option value="traditional_playoff" @selected(old('overtime', 'none') === 'traditional_playoff')>Traditional playoff · sudden death (15 min periods)</option><option value="modern_playoff" @selected(old('overtime', 'none') === 'modern_playoff')>Modern NFL playoff · both teams get a chance (15 min periods)</option></select></label>
        <input type="hidden" name="penalties" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="penalties" value="1" @checked(old('penalties', true))>Penalties</label>
        <input type="hidden" name="injuries" value="0"><label class="flex items-center gap-2"><input type="checkbox" name="injuries" value="1" @checked(old('injuries', true))>Injuries</label>
        <button class="bg-blue-700 rounded px-5 py-2" @disabled($teams->count() < 2)>Start game</button>
        <button name="quick_sim" value="1" class="border border-blue-400 rounded px-5 py-2" @disabled($teams->count() < 2)>Quick Sim</button>
        <p class="text-xs text-gray-400 w-full">Quick Sim lets the CPU coach both teams and opens the final box score, with every play and replay saved.</p>
    </form>
    </details>
    <div class="space-y-3">
        @forelse($games as $game)
            <article class="exhibition-card"><a href="{{ route('exhibitions.show', $game) }}" class="block bg-gray-800 rounded p-4 hover:bg-gray-700">{{ $game->awayTeam->name }} {{ $game->state['away_score'] }} at {{ $game->homeTeam->name }} {{ $game->state['home_score'] }} <span class="text-gray-400 ml-4">{{ $game->state['status'] === 'final' ? 'Final' : 'Resume · Q'.$game->state['quarter'] }}</span></a>
                <details class="exhibition-delete"><summary>Delete</summary><form method="POST" action="{{ route('exhibitions.destroy', $game) }}">@csrf @method('DELETE')<p>Delete this exhibition and its saved replays? This cannot be undone.</p><button class="rounded bg-red-800 px-3 py-2 mt-2">Delete exhibition</button></form></details>
            </article>
        @empty<p class="text-gray-400">No exhibitions yet. Create a matchup above to get started.</p>@endforelse
    </div>
    {{ $games->links() }}
</div>
</x-layouts.app>
