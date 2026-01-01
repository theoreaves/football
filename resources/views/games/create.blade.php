{{-- resources/views/games/create.blade.php --}}
<x-layouts.app>
    <div class="min-h-screen bg-gray-950 text-gray-100 p-6">
        <div class="max-w-xl mx-auto rounded-lg border border-white/10 bg-gray-900/40 p-6 space-y-4">
            <div class="text-2xl font-bold">New Game</div>

            <form method="POST" action="{{ route('games.store') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm text-gray-300 mb-1">Away Team</label>
                    <select name="away_team_id" class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2">
                        <option value="">Select…</option>
                        @foreach($teams as $t)
                            <option value="{{ $t->id }}" @selected(old('away_team_id') == $t->id)>
                                {{ $t->city }} {{ $t->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('away_team_id') <div class="text-red-300 text-sm mt-1">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="block text-sm text-gray-300 mb-1">Home Team</label>
                    <select name="home_team_id" class="w-full bg-gray-900 border border-gray-700 rounded px-3 py-2">
                        <option value="">Select…</option>
                        @foreach($teams as $t)
                            <option value="{{ $t->id }}" @selected(old('home_team_id') == $t->id)>
                                {{ $t->city }} {{ $t->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('home_team_id') <div class="text-red-300 text-sm mt-1">{{ $message }}</div> @enderror
                </div>

                <button class="w-full px-4 py-2 rounded bg-blue-600 hover:bg-blue-500 font-semibold">
                    Create Game
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
