<x-layouts.app>
<div class="max-w-4xl mx-auto p-6 text-white space-y-6">
    <h1 class="text-3xl font-semibold">Import preview · {{ $kind }}</h1>
    <p class="text-gray-300">Review before saving. Every imported player and graphic becomes an independent copy. No statistics, injuries or season progress are imported.</p>
    @foreach($errors->all() as $error)<p class="text-red-300">{{ $error }}</p>@endforeach
    @if($kind === 'roster')
        <h2 class="text-xl">{{ count($content['rows']) }} players</h2>
        <div class="overflow-x-auto"><table class="w-full text-left"><tr><th>Name</th><th>Position</th><th>Depth</th><th>Jersey</th></tr>
        @foreach($content['rows'] as $row)<tr><td class="py-1">{{ $row['player']['firstname'] }} {{ $row['player']['lastname'] }}</td><td>{{ $row['player']['position'] }}</td><td>{{ $row['pivot']['depth_chart_position'] }}</td><td>{{ $row['pivot']['jersey_number'] ?? '—' }}</td></tr>@endforeach
        </table></div>
    @else
        <h2 class="text-xl">{{ $content['manifest']['name'] }}</h2>
        @foreach($content['teams'] as $entry)<div class="border border-gray-700 rounded p-4"><h3 class="font-semibold">{{ $entry['data']['city'] }} {{ $entry['data']['name'] }}</h3><p>{{ count($entry['roster']) }} players in default roster @if(!empty($entry['rosters'])) · Saved years: {{ implode(', ', array_keys($entry['rosters'])) }} @endif · {{ $entry['data']['conference'] ?? 'No conference' }} · {{ $entry['data']['division'] ?? 'No division' }}</p></div>@endforeach
    @endif
    <form method="POST" action="{{ route('packages.import') }}" class="rounded-xl border border-gray-700 bg-gray-800 p-5 space-y-4">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="block">{{ $kind === 'world' ? 'First season year (saved roster years are preserved)' : 'Destination roster year' }}<input class="block bg-gray-900 border border-gray-600 rounded p-2 mt-1" type="number" min="1900" max="2200" name="year" value="{{ old('year', $content['rows'][0]['source_year'] ?? $content['teams'][0]['roster'][0]['source_year'] ?? date('Y')) }}" required></label>
        @if($kind === 'world')
            <input type="hidden" name="mode" value="add">
            <label class="block">New world name<input class="block w-full bg-gray-900 border border-gray-600 rounded p-2 mt-1" name="world_name" maxlength="255" value="{{ $content['manifest']['name'] }}" required></label>
            <p>Next: choose your season rules, team alignment and schedule. No schedule is generated automatically.</p>
        @else
            @if(!app(\App\Support\CurrentWorld::class)->id)<p class="text-amber-300">Open the destination world first, then upload or copy again.</p>@endif
            <label class="block">Import action<select class="block bg-gray-900 border border-gray-600 rounded p-2 mt-1" name="mode"><option value="add">{{ $kind === 'roster' ? 'Add as new players to the selected year' : 'Add as an expansion team' }}</option><option value="replace">{{ $kind === 'roster' ? 'Replace the selected year’s entire roster' : 'Replace the selected team · keep its division and schedule slot' }}</option></select></label>
            <label class="block">{{ $kind === 'roster' ? 'Destination team' : 'Team to replace (replacement only)' }}<select class="block bg-gray-900 border border-gray-600 rounded p-2 mt-1" name="team_id" @required($kind === 'roster')><option value="">Choose team</option>@foreach($teams as $team)<option value="{{ $team->id }}">{{ $team->city }} {{ $team->name }}</option>@endforeach</select></label>
            <p class="text-amber-200">Replacement changes the selected team or roster year. Existing completed game snapshots remain intact. Added players receive new identities; CSV upload does not match or update players by name.</p>
        @endif
        <button class="bg-blue-700 rounded px-5 py-2" @disabled($kind !== 'world' && !app(\App\Support\CurrentWorld::class)->id)>Confirm import</button>
        <a class="text-blue-300 underline ml-4" href="{{ route('packages.index') }}">Cancel</a>
    </form>
</div>
</x-layouts.app>
