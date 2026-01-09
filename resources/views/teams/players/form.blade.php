<x-layouts.app>
    <div class="max-w-6xl mx-auto p-6 bg-white">
        @if(session('status'))
            <div class="mb-4 p-3 border rounded bg-green-50">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="mb-4 p-3 border rounded bg-red-50">
                <div class="font-semibold mb-2">Please fix the following:</div>
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-semibold">
                {{ $mode === 'create' ? 'Add Player' : 'Edit Player' }} ({{ $year }})
            </h1>
            <a href="{{ route('teams.editor.teams.players.index', [$team, 'year' => $year]) }}" class="underline text-gray-700">
                Back to Players
            </a>
        </div>

        <form method="POST"
              action="{{ $mode === 'create'
                ? route('teams.editor.teams.players.store', [$team, 'year' => $year])
                : route('teams.editor.teams.players.update', [$team, $player, 'year' => $year]) }}"
              class="space-y-6">
            @csrf
            @if($mode === 'edit') @method('PUT') @endif

            {{-- Identity --}}
            <div class="border rounded p-4">
                <div class="font-semibold mb-3">Player</div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block font-medium mb-1">First Name</label>
                        <input name="firstname" value="{{ old('firstname', $player->firstname) }}" class="w-full border rounded p-2" required />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Last Name</label>
                        <input name="lastname" value="{{ old('lastname', $player->lastname) }}" class="w-full border rounded p-2" required />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Age</label>
                        <input type="number" min="0" max="99" name="age" value="{{ old('age', $player->age ?? 20) }}" class="w-full border rounded p-2" required />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Position (Player)</label>
                        <input name="position" value="{{ old('position', $player->position) }}" class="w-full border rounded p-2" required />
                    </div>
                </div>
            </div>

            {{-- Roster / Pivot --}}
            <div class="border rounded p-4">
                <div class="font-semibold mb-3">Roster Slot (team_players)</div>

                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block font-medium mb-1">Position (Team)</label>
                        <input name="tp_position" value="{{ old('tp_position', $pivot['position'] ?? '') }}" class="w-full border rounded p-2" required />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Depth Chart</label>
                        <input name="depth_chart_position" value="{{ old('depth_chart_position', $pivot['depth_chart_position'] ?? '') }}" class="w-full border rounded p-2" required />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">KR Depth</label>
                        <input name="kick_return_depth_chart_position" value="{{ old('kick_return_depth_chart_position', $pivot['kick_return_depth_chart_position'] ?? '') }}" class="w-full border rounded p-2" required />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">PR Depth</label>
                        <input name="punt_return_depth_chart_position" value="{{ old('punt_return_depth_chart_position', $pivot['punt_return_depth_chart_position'] ?? '') }}" class="w-full border rounded p-2" required />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Jersey #</label>
                        <input type="number" min="0" max="99" name="jersey_number" value="{{ old('jersey_number', $pivot['jersey_number'] ?? '') }}" class="w-full border rounded p-2" />
                    </div>
                </div>
            </div>

            {{-- Ratings (players) --}}
            <div class="border rounded p-4">
                <div class="font-semibold mb-3">Ratings (players)</div>

                @php
                    $ratings = [
                        'pass_evade','pass_accuracy','pass_deep','pass_control',
                        'rush','rush_power',
                        'receive','receive_deep',
                        'fumble','speed',
                        'tackle','sack','cover','interception','strip',
                        'kick30','kick39','kick49','kick50',
                        'punt_distance','punt_pooch_yard','punt_pooch','punt_block',
                        'return_yards','return_speed','return_fumble',
                    ];
                @endphp

                <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                    @foreach($ratings as $field)
                        <div>
                            <label class="block font-medium mb-1">{{ str_replace('_',' ', $field) }}</label>
                            <input type="number" min="0" name="{{ $field }}"
                                   value="{{ old($field, $player->{$field} ?? 0) }}"
                                   class="w-full border rounded p-2" required />
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Ranges (team_players pivot) --}}
            <div class="border rounded p-4">
                <div class="font-semibold mb-3">Outcome Ranges (team_players)</div>

                @php
                    $ranges = [
                        'catch_from','catch_to','catch_plus_from','catch_plus_to',
                        'rush_from','rush_to',
                        'sack_from','sack_to',
                        'interception_from','interception_to',
                        'tackle_from','tackle_to',
                        'kick_from','kick_to',
                        'punt_from','punt_to',
                    ];
                @endphp

                <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                    @foreach($ranges as $field)
                        <div>
                            <label class="block font-medium mb-1">{{ str_replace('_',' ', $field) }}</label>
                            <input type="number" name="{{ $field }}"
                                   value="{{ old($field, $pivot[$field] ?? 0) }}"
                                   class="w-full border rounded p-2" required />
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button class="px-5 py-2 rounded bg-blue-600 text-white" type="submit">
                    {{ $mode === 'create' ? 'Add Player' : 'Save Changes' }}
                </button>

                <a href="{{ route('teams.editor.teams.players.index', [$team, 'year' => $year]) }}" class="px-4 py-2 rounded border">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</x-layouts.app>
