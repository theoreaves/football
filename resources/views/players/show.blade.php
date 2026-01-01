<x-layouts.app>
    @php
        $teamName = fn($t) => trim(($t->city ?? '').' '.($t->name ?? ''));

        $range = function($from, $to) {
            if ($from === null || $to === null) return '';
            return ((int)$from === (int)$to) ? (string)(int)$from : ((int)$from . '-' . (int)$to);
        };
    @endphp

    <div class="min-h-screen bg-gray-950 text-gray-100 p-6">
        <div class="max-w-5xl mx-auto space-y-6">

            {{-- Header --}}
            <div class="flex items-end justify-between gap-4">
                <div>
                    <div class="text-sm text-gray-400">Player</div>
                    <div class="text-3xl font-bold tracking-tight">
                        {{ $player->firstname }} {{ $player->lastname }}
                    </div>
                    <div class="mt-1 text-sm text-gray-400">
                        Age: <span class="text-gray-200 font-medium">{{ $player->age }}</span>
                        <span class="mx-2">•</span>
                        Position: <span class="text-gray-200 font-medium">{{ strtoupper($player->position) }}</span>
                    </div>
                </div>

                <div class="text-right">
                    <a href="{{ url()->previous() }}"
                       class="text-blue-400 hover:text-blue-300 font-medium">
                        ← Back
                    </a>
                </div>
            </div>

            <div class="h-px bg-white/10"></div>

            {{-- Ratings --}}
            <div class="rounded-lg border border-white/10 overflow-hidden">
                <div class="bg-white/5 px-4 py-3 font-semibold">Ratings</div>

                <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Rush</div>
                        <div class="text-lg font-semibold">{{ $player->rush }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Rush Power</div>
                        <div class="text-lg font-semibold">{{ $player->rush_power }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Receive</div>
                        <div class="text-lg font-semibold">{{ $player->receive }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Receive Deep</div>
                        <div class="text-lg font-semibold">{{ $player->receive_deep }}</div>
                    </div>

                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Pass Evade</div>
                        <div class="text-lg font-semibold">{{ $player->pass_evade }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Pass Accuracy</div>
                        <div class="text-lg font-semibold">{{ $player->pass_accuracy }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Pass Deep</div>
                        <div class="text-lg font-semibold">{{ $player->pass_deep }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Pass Control</div>
                        <div class="text-lg font-semibold">{{ $player->pass_control }}</div>
                    </div>

                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Speed</div>
                        <div class="text-lg font-semibold">{{ $player->speed }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Fumble</div>
                        <div class="text-lg font-semibold">{{ $player->fumble }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Tackle</div>
                        <div class="text-lg font-semibold">{{ $player->tackle }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Sack</div>
                        <div class="text-lg font-semibold">{{ $player->sack }}</div>
                    </div>

                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Cover</div>
                        <div class="text-lg font-semibold">{{ $player->cover }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Interception</div>
                        <div class="text-lg font-semibold">{{ $player->interception }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Strip</div>
                        <div class="text-lg font-semibold">{{ $player->strip }}</div>
                    </div>

                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Kick 30</div>
                        <div class="text-lg font-semibold">{{ $player->kick30 }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Kick 39</div>
                        <div class="text-lg font-semibold">{{ $player->kick39 }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Kick 49</div>
                        <div class="text-lg font-semibold">{{ $player->kick49 }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Kick 50</div>
                        <div class="text-lg font-semibold">{{ $player->kick50 }}</div>
                    </div>

                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Punt Distance</div>
                        <div class="text-lg font-semibold">{{ $player->punt_distance }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Pooch Yard</div>
                        <div class="text-lg font-semibold">{{ $player->punt_pooch_yard }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Pooch</div>
                        <div class="text-lg font-semibold">{{ $player->punt_pooch }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Punt Block</div>
                        <div class="text-lg font-semibold">{{ $player->punt_block }}</div>
                    </div>

                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Return Yards</div>
                        <div class="text-lg font-semibold">{{ $player->return_yards }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Return Speed</div>
                        <div class="text-lg font-semibold">{{ $player->return_speed }}</div>
                    </div>
                    <div class="rounded bg-white/5 p-3">
                        <div class="text-gray-400">Return Fumble</div>
                        <div class="text-lg font-semibold">{{ $player->return_fumble }}</div>
                    </div>
                </div>
            </div>

            {{-- Teams / Pivot --}}
            <div class="rounded-lg border border-white/10 overflow-hidden">
                <div class="bg-white/5 px-4 py-3 font-semibold">Teams / Roles</div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-gray-300 border-b border-white/10">
                        <tr>
                            <th class="text-left px-4 py-3">Team</th>
                            <th class="text-left px-4 py-3">Year</th>
                            <th class="text-left px-4 py-3">Pos</th>
                            <th class="text-left px-4 py-3">#</th>
                            <th class="text-left px-4 py-3">Depth</th>
                            <th class="text-center px-4 py-3">Catch</th>
                            <th class="text-center px-4 py-3">Catch+</th>
                            <th class="text-center px-4 py-3">Rush</th>
                            <th class="text-center px-4 py-3">Sack</th>
                            <th class="text-center px-4 py-3">INT</th>
                            <th class="text-center px-4 py-3">Kick</th>
                            <th class="text-center px-4 py-3">Punt</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-white/10">
                        @forelse($player->teams as $t)
                            <tr class="bg-gray-950">
                                <td class="px-4 py-3 font-semibold">
                                    <a class="text-blue-400 hover:text-blue-300"
                                       href="{{ route('teams.sheet', $t) }}">
                                        {{ $teamName($t) }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ $t->pivot->team_year }}</td>
                                <td class="px-4 py-3">{{ $t->pivot->position }}</td>
                                <td class="px-4 py-3">{{ $t->pivot->jersey_number }}</td>
                                <td class="px-4 py-3">{{ $t->pivot->depth_chart_position }}</td>
                                <td class="text-center px-4 py-3">{{ $range($t->pivot->catch_from, $t->pivot->catch_to) }}</td>
                                <td class="text-center px-4 py-3">{{ $range($t->pivot->catch_plus_from, $t->pivot->catch_plus_to) }}</td>
                                <td class="text-center px-4 py-3">{{ $range($t->pivot->rush_from, $t->pivot->rush_to) }}</td>
                                <td class="text-center px-4 py-3">{{ $range($t->pivot->sack_from, $t->pivot->sack_to) }}</td>
                                <td class="text-center px-4 py-3">{{ $range($t->pivot->interception_from, $t->pivot->interception_to) }}</td>
                                <td class="text-center px-4 py-3">{{ $range($t->pivot->kick_from, $t->pivot->kick_to) }}</td>
                                <td class="text-center px-4 py-3">{{ $range($t->pivot->punt_from, $t->pivot->punt_to) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-4 py-6 text-center text-gray-400">
                                    This player is not assigned to any team yet.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-layouts.app>
