<x-layouts.app :immersive="request()->boolean('embedded')">
<div class="max-w-5xl mx-auto p-6 bg-white text-gray-900">
    <h1 class="text-2xl font-semibold">{{ $mode === 'create' ? 'Add Player' : 'Edit Player' }} · {{ $team->name }} ({{ $year }})</h1>
    @if(isset($season))<a data-player-editor-back href="{{ route('seasons.team', ['season' => $season, 'team' => $team, 'tab' => 'roster']) }}" class="underline">Back to roster</a>@else<a href="{{ route('teams.editor.teams.players.index', [$team, 'year' => $year]) }}" class="underline">Back to Players</a>@endif
    @if(session('status'))<p class="my-4">{{ session('status') }}</p>@endif
    @if($errors->any())<ul class="my-4 text-red-700">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <form method="POST" action="{{ $mode === 'create' ? route('teams.editor.teams.players.store', [$team, 'year' => $year]) : route('teams.editor.teams.players.update', [$team, $player, 'year' => $year, 'season' => $season?->id, 'embedded' => request()->boolean('embedded') ? 1 : null]) }}" class="space-y-6 mt-4">
        @csrf @if($mode === 'edit') @method('PUT') @endif
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            @foreach(['firstname' => 'First name', 'lastname' => 'Last name', 'age' => 'Age', 'position' => 'Position'] as $field => $label)
            <label>{{ $label }}<input name="{{ $field }}" type="{{ in_array($field, ['age', 'height_inches', 'weight_pounds']) ? 'number' : 'text' }}" value="{{ old($field, $player->{$field} ?? ($field === 'height_inches' ? 72 : ($field === 'weight_pounds' ? 215 : ''))) }}" class="block w-full border rounded p-2"></label>
            @endforeach

            <label>Jersey number<input type="number" name="jersey_number" min="0" max="99" value="{{ old('jersey_number', $pivot['jersey_number']) }}" class="block w-full border rounded p-2"></label>
            <p class="text-sm text-gray-600">Depth order: QB1 starts before QB2; WR1–WR3 fill receiver slots. Stamina controls fatigue and recovery; durability controls injury risk. Higher ratings are better. Changes apply to new games.</p>
            <label>Roster depth<input name="depth_chart_position" value="{{ old('depth_chart_position', $pivot['depth_chart_position']) }}" placeholder="QB1, WR2, LB3…" class="block w-full border rounded p-2"></label>
        </div>
        @include('teams.players.appearance')
        <h2 class="font-semibold">Engine ratings (1–99)</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach(\App\Services\Simulation\PlayerRatings::FIELDS as $field)
            <label>{{ ucwords(str_replace('_', ' ', $field)) }}<input type="number" name="ratings[{{ $field }}]" min="1" max="99" required value="{{ old('ratings.'.$field, $ratings[$field]) }}" class="block w-full border rounded p-2"></label>
            @endforeach
        </div>
        <button class="bg-blue-600 text-white rounded px-5 py-2">Save player</button>
    </form>
</div>
</x-layouts.app>
