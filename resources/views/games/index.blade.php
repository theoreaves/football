<x-layouts.app>
    <div class="min-h-screen bg-gray-950 text-gray-100 p-6">
        <div class="max-w-5xl mx-auto space-y-6">

            {{-- Header --}}
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Games</h1>
                    <p class="text-sm text-gray-400 mt-1">
                        All scheduled and in-progress games
                    </p>
                </div>

                <a href="{{ route('games.create') }}"
                   class="px-4 py-2 rounded bg-blue-600 hover:bg-blue-500 font-semibold">
                    + New Game
                </a>
            </div>

            <div class="h-px bg-white/10"></div>

            {{-- Games List --}}
            <div class="overflow-hidden rounded-lg border border-white/10">
                <table class="min-w-full text-sm">
                    <thead class="bg-white/5 text-gray-300">
                    <tr>
                        <th class="text-left px-4 py-3">Matchup</th>
                        <th class="text-center px-4 py-3">Status</th>
                        <th class="text-center px-4 py-3">Score</th>
                        <th class="text-right px-4 py-3">Action</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-white/10">
                    @forelse($games as $game)
                        <tr class="hover:bg-white/5">
                            {{-- Matchup --}}
                            <td class="px-4 py-3 font-semibold">
                                {{ $game->awayTeam?->name ?? 'Away' }}
                                <span class="text-gray-400 mx-1">@</span>
                                {{ $game->homeTeam?->name ?? 'Home' }}
                            </td>

                            {{-- Status --}}
                            <td class="text-center px-4 py-3 text-gray-300">
                                @if($game->phase === 'FINAL')
                                    Final
                                @else
                                    Q{{ $game->quarter }}
                                    <span class="text-gray-400">
                                            — {{ gmdate('i:s', $game->clock) }}
                                        </span>
                                @endif
                            </td>

                            {{-- Score --}}
                            <td class="text-center px-4 py-3 font-semibold">
                                {{ $game->away_score }}
                                <span class="text-gray-400 mx-1">-</span>
                                {{ $game->home_score }}
                            </td>

                            {{-- Action --}}
                            <td class="text-right px-4 py-3">
                                <a href="{{ route('games.show', $game) }}"
                                   class="text-blue-400 hover:text-blue-300 font-semibold">
                                    Open →
                                </a>
                                <a href="{{ route('games.boxscore', $game) }}"
                                   class="text-blue-400 hover:text-blue-300 font-semibold">
                                    Box →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-gray-400">
                                No games yet.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-layouts.app>
