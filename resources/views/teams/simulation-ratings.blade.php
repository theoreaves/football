<x-layouts.app>
<div class="max-w-7xl mx-auto p-6 text-white space-y-4">
    <h1 class="text-2xl font-semibold">{{ $team->city }} {{ $team->name }} · Engine ratings</h1>
    <p class="text-gray-400">{{ $year }} roster · 1–99, higher is better. Initial ratings are fictional, position-based values. Saved changes apply to new exhibitions; games in progress keep their roster snapshot.</p>
    <a href="{{ route('teams.editor.edit', $team) }}" class="text-blue-300">Back to team</a>
    @if(session('status'))<p class="text-green-300">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="text-red-300">{{ $errors->first() }}</p>@endif
    <form action="{{ route('simulation-ratings.update', $team) }}" method="POST">@csrf @method('PUT')
        <div class="overflow-x-auto"><table class="text-sm w-full"><thead><tr><th class="text-left p-2">Player</th>@foreach($ratings::FIELDS as $field)<th class="p-2">{{ ucfirst(str_replace('_', ' ', $field)) }}</th>@endforeach</tr></thead><tbody>
            @foreach($players as $player)<tr class="border-t border-gray-700"><td class="p-2 whitespace-nowrap">#{{ $player->pivot->jersey_number }} {{ $player->firstname }} {{ $player->lastname }} · {{ $player->position }}</td>
                @foreach($ratings->forPlayer($player) as $field => $value)<td class="p-1"><input aria-label="{{ $player->firstname }} {{ $player->lastname }} {{ $field }}" type="number" min="1" max="99" required name="ratings[{{ $player->id }}][{{ $field }}]" value="{{ old('ratings.'.$player->id.'.'.$field, $value) }}" class="w-16 p-2 bg-gray-800 text-white rounded"></td>@endforeach
            </tr>@endforeach
        </tbody></table></div>
        @if($players->isNotEmpty())<button class="bg-blue-700 rounded px-5 py-2 mt-4">Save ratings</button>@endif
    </form>
</div>
</x-layouts.app>
