{{-- resources/views/teams/sheet.blade.php --}}

<x-layouts.app>
    <div class="min-h-screen bg-gray-950 text-gray-100 p-6">
        @php
            // Expect $team loaded with players pivot data
            $players = $team->players ?? collect();

            // Helper: format "from-to" where from==to shows just one number
            $range = function($from, $to) {
                if ($from === null || $to === null) return '';
                return ((int)$from === (int)$to) ? (string)(int)$from : ((int)$from . '-' . (int)$to);
            };

            // Group by pivot position (you’re using depth_chart_position for section membership)
            $pos = fn($p) => strtoupper((string)($p->pivot->depth_chart_position ?? ''));

            // OFFENSE
            $qbs = $players->filter(fn($p) => in_array($pos($p), ['QB1','QB2','QB3','QB4'], true));

            $skill = $players->filter(fn($p) => in_array($pos($p), [
                'RB1','RB2','RB3','RB4',
                'WR1','WR2','WR3','WR4',
                'TE1','TE2',
            ], true));

            $ol = $players->filter(fn($p) => in_array($pos($p), ['LT','LG','C','RG','RT','OL'], true));

            // DEFENSE
            $dl = $players->filter(fn($p) => in_array($pos($p), [
                'DE1','DT1','NT1','DL1',
                'DE2','DT2','NT2','DL2',
                'DE3','DT3','NT3','DL3',
                'DE4','DT4','NT4','DL4',
            ], true));

            $lb = $players->filter(fn($p) => in_array($pos($p), [
                'LB1','MLB1','OLB1',
                'LB2','MLB2','OLB2',
                'LB3','MLB3','OLB3',
                'LB4','MLB4','OLB4',
            ], true));

            $db = $players->filter(fn($p) => in_array($pos($p), [
                'CB1','FS1','SS1','DB1',
                'CB2','FS2','SS2','DB2',
                'CB3','FS3','SS3','DB3',
                'CB4','FS4','SS4','DB4',
            ], true));

            // SPECIAL TEAMS
            $kp = $players->filter(fn($p) => in_array(strtoupper((string)($p->pivot->position ?? '')), ['K','P'], true));

            $kr = $players->filter(fn($p) => !empty($p->pivot->kick_return_depth_chart_position));
            $pr = $players->filter(fn($p) => !empty($p->pivot->punt_return_depth_chart_position));

            // Convenience
            $teamName = trim(($team->city ?? '') . ' ' . ($team->name ?? ''));
        @endphp

        <div class="max-w-6xl mx-auto" x-data="{ tab: 'offense' }">
            {{-- Header --}}
            <div class="flex items-end justify-between gap-4">
                <div>
                    <div class="text-sm text-gray-400">Team Sheet</div>
                    <div class="text-3xl font-bold tracking-tight">{{ $teamName }}</div>
                </div>

                <div class="text-right text-sm text-gray-400">
                    <div>Generated: {{ now()->format('Y-m-d H:i') }}</div>
                    <div>Total Players: {{ $players->count() }}</div>
                </div>

                <div class="text-right">
                    <a href="{{ route('teams.index') }}"
                       class="text-blue-400 hover:text-blue-300 font-medium">
                        ← Back
                    </a>
                </div>
            </div>

            <div class="mt-4 h-px bg-white/10"></div>

            {{-- Tabs --}}
            <div class="mt-5 flex flex-wrap gap-2">
                <button
                    type="button"
                    @click="tab = 'offense'"
                    :class="tab === 'offense' ? 'bg-blue-600 text-white border-blue-500' : 'bg-gray-900 text-gray-300 border-white/10 hover:bg-gray-800'"
                    class="px-4 py-2 rounded-lg border text-sm font-semibold"
                >
                    Offense
                </button>

                <button
                    type="button"
                    @click="tab = 'defense'"
                    :class="tab === 'defense' ? 'bg-blue-600 text-white border-blue-500' : 'bg-gray-900 text-gray-300 border-white/10 hover:bg-gray-800'"
                    class="px-4 py-2 rounded-lg border text-sm font-semibold"
                >
                    Defense
                </button>

                <button
                    type="button"
                    @click="tab = 'special'"
                    :class="tab === 'special' ? 'bg-blue-600 text-white border-blue-500' : 'bg-gray-900 text-gray-300 border-white/10 hover:bg-gray-800'"
                    class="px-4 py-2 rounded-lg border text-sm font-semibold"
                >
                    Special Teams
                </button>
            </div>

            {{-- OFFENSE TAB --}}
            <div x-show="tab === 'offense'" x-cloak class="mt-6 space-y-8">
                {{-- QB SECTION --}}
                <div>
                    <div class="flex items-center justify-between">
                        <div class="text-lg font-semibold">Quarterbacks</div>
                        <div class="text-xs text-gray-400">Ratings from Players table</div>
                    </div>

                    <div class="mt-2 overflow-x-auto rounded-lg border border-white/10">
                        <table class="min-w-full text-sm">
                            <thead class="bg-white/5 text-gray-300">
                            <tr>
                                <th class="text-left px-3 py-2">Pos</th>
                                <th class="text-left px-3 py-2">Player</th>
                                <th class="text-center px-3 py-2">Rush</th>
                                <th class="text-center px-3 py-2">Evade</th>
                                <th class="text-center px-3 py-2">Accy</th>
                                <th class="text-center px-3 py-2">Deep</th>
                                <th class="text-center px-3 py-2">Fum</th>
                                <th class="text-center px-3 py-2">Spd</th>
                                <th class="text-center px-3 py-2">Ctrl</th>
                                <th class="text-center px-3 py-2">Rush Rng</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                            @forelse($qbs as $p)
                                <tr class="bg-gray-950">
                                    <td class="px-3 py-2 font-semibold">{{ $p->pivot->depth_chart_position }}</td>
                                    <td class="px-3 py-2">
                                        <a href="{{ route('players.show', $p) }}" class="text-blue-400 hover:text-blue-300">
                                            {{ $p->firstname }} {{ $p->lastname }}
                                            <span class="text-xs text-gray-400">({{ $p->age }})</span>
                                        </a>
                                    </td>

                                    <td class="text-center px-3 py-2">{{ $p->rush }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->pass_evade }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->pass_accuracy }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->pass_deep }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->fumble }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->speed }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->pass_control }}</td>
                                    <td class="text-center px-3 py-2">{{ $range($p->pivot->rush_from, $p->pivot->rush_to) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="px-3 py-3 text-gray-400">No quarterbacks on this roster yet.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- SKILL --}}
                <div>
                    <div class="flex items-center justify-between">
                        <div class="text-lg font-semibold">RB / WR / TE</div>
                        <div class="text-xs text-gray-400">Ratings from Players + roll ranges from pivot</div>
                    </div>

                    <div class="mt-2 overflow-x-auto rounded-lg border border-white/10">
                        <table class="min-w-full text-sm">
                            <thead class="bg-white/5 text-gray-300">
                            <tr>
                                <th class="text-left px-3 py-2">Pos</th>
                                <th class="text-left px-3 py-2">Player</th>

                                <th class="text-center px-3 py-2">Rush</th>
                                <th class="text-center px-3 py-2">Pwr</th>
                                <th class="text-center px-3 py-2">Rec</th>
                                <th class="text-center px-3 py-2">Deep</th>
                                <th class="text-center px-3 py-2">Fum</th>
                                <th class="text-center px-3 py-2">Spd</th>

                                <th class="text-center px-3 py-2">Catch</th>
                                <th class="text-center px-3 py-2">Catch+</th>
                                <th class="text-center px-3 py-2">Rush Rng</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                            @forelse($skill as $p)
                                <tr class="bg-gray-950">
                                    <td class="px-3 py-2 font-semibold">{{ $p->pivot->depth_chart_position }}</td>
                                    <td class="px-3 py-2">
                                        <a href="{{ route('players.show', $p) }}" class="text-blue-400 hover:text-blue-300">
                                            {{ $p->firstname }} {{ $p->lastname }}
                                            <span class="text-xs text-gray-400">({{ $p->age }})</span>
                                        </a>
                                    </td>

                                    <td class="text-center px-3 py-2">{{ $p->rush }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->rush_power }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->receive }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->receive_deep }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->fumble }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->speed }}</td>

                                    <td class="text-center px-3 py-2">{{ $range($p->pivot->catch_from, $p->pivot->catch_to) }}</td>
                                    <td class="text-center px-3 py-2">{{ $range($p->pivot->catch_plus_from, $p->pivot->catch_plus_to) }}</td>
                                    <td class="text-center px-3 py-2">{{ $range($p->pivot->rush_from, $p->pivot->rush_to) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="px-3 py-3 text-gray-400">No RB/WR/TE players on this roster yet.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex">
                {{-- OFFENSIVE LINE --}}
                <div class="w-full pr-4">
                    <div class="text-lg font-semibold">Offensive Line</div>

                    <div class="mt-2 overflow-x-auto rounded-lg border border-white/10">
                        <table class="min-w-full text-sm">
                            <thead class="bg-white/5 text-gray-300">
                            <tr>
                                <th class="text-left px-3 py-2">Ratings</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                            <tr>
                                <td class="px-3 py-3 text-gray-400 flex justify-around">
                                    <div>Rush={{ $team->ol_rush }}</div>
                                    <div>Power={{ $team->ol_power }}</div>
                                    <div>Pass={{ $team->ol_pass }}</div>
                                    <div>Protect={{ $team->ol_protect }}</div>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- PLAYCALLING --}}
                <div class="w-full pl-4">
                    <div class="text-lg font-semibold">Play Calling</div>

                    <div class="mt-2 overflow-x-auto rounded-lg border border-white/10">
                        <table class="min-w-full text-sm">
                            <thead class="bg-white/5 text-gray-300">
                            <tr>
                                <th class="text-left px-3 py-2">1st/2nd Down</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                            <tr>
                                <td class="px-3 py-3 text-gray-400 flex justify-around">
                                    <div>
                                        <div class="font-bold">Behind</div>
                                        <div>
                                            {{ $team->playcalling_behind > 0 ? '+' : '' }}{{ $team->playcalling_behind }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="font-bold">Tied</div>
                                        <div>
                                            {{ $team->playcalling_tied > 0 ? '+' : '' }}{{ $team->playcalling_tied }}
                                        </div>
                                    </div>

                                    <div>
                                        <div class="font-bold">Ahead</div>
                                        <div>
                                            {{ $team->playcalling_ahead > 0 ? '+' : '' }}{{ $team->playcalling_ahead }}
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            </tbody>

                        </table>
                    </div>
                </div>
                </div>
            </div>

            {{-- DEFENSE TAB --}}
            <div x-show="tab === 'defense'" x-cloak class="mt-6 space-y-6">
                <div class="text-lg font-semibold">Defense</div>

                <div class="mt-2 space-y-4">
                    {{-- DL --}}
                    <div class="rounded-lg border border-white/10 overflow-hidden">
                        <div class="bg-white/5 px-3 py-2 font-semibold text-sm">Defensive Line</div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-gray-300">
                                <tr class="border-b border-white/10">
                                    <th class="text-left px-3 py-2">Pos</th>
                                    <th class="text-left px-3 py-2">Player</th>
                                    <th class="text-center px-3 py-2">Tkl</th>
                                    <th class="text-center px-3 py-2">Sack</th>
                                    <th class="text-center px-3 py-2">Strip</th>
                                    <th class="text-center px-3 py-2">Sack Rng</th>
                                    <th class="text-center px-3 py-2">Int Rng</th>
                                    <th class="text-center px-3 py-2">Tkl Rng</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-white/10">
                                @forelse($dl as $p)
                                    <tr class="bg-gray-950">
                                        <td class="px-3 py-2 font-semibold">{{ $p->pivot->depth_chart_position }}</td>
                                        <td class="px-3 py-2">
                                        <a href="{{ route('players.show', $p) }}" class="text-blue-400 hover:text-blue-300">
                                            {{ $p->firstname }} {{ $p->lastname }}
                                        </a>
                                        </td>

                                        <td class="text-center px-3 py-2">{{ $p->tackle }}</td>
                                        <td class="text-center px-3 py-2">{{ $p->sack }}</td>
                                        <td class="text-center px-3 py-2">{{ $p->strip }}</td>
                                        <td class="text-center px-3 py-2">{{ $range($p->pivot->sack_from, $p->pivot->sack_to) }}</td>
                                        <td class="text-center px-3 py-2">{{ $range($p->pivot->interception_from, $p->pivot->interception_to) }}</td>
                                        <td class="text-center px-3 py-2">{{ $range($p->pivot->tackle_from ?? null, $p->pivot->tackle_to ?? null) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="px-3 py-3 text-gray-400">No DL players.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- LB --}}
                    <div class="rounded-lg border border-white/10 overflow-hidden">
                        <div class="bg-white/5 px-3 py-2 font-semibold text-sm">Linebackers</div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-gray-300">
                                <tr class="border-b border-white/10">
                                    <th class="text-left px-3 py-2">Pos</th>
                                    <th class="text-left px-3 py-2">Player</th>
                                    <th class="text-center px-3 py-2">Tkl</th>
                                    <th class="text-center px-3 py-2">Cov</th>
                                    <th class="text-center px-3 py-2">Int</th>
                                    <th class="text-center px-3 py-2">Sack Rng</th>
                                    <th class="text-center px-3 py-2">Int Rng</th>
                                    <th class="text-center px-3 py-2">Tkl Rng</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-white/10">
                                @forelse($lb as $p)
                                    <tr class="bg-gray-950">
                                        <td class="px-3 py-2 font-semibold">{{ $p->pivot->depth_chart_position }}</td>
                                        <td class="px-3 py-2">
                                            <a href="{{ route('players.show', $p) }}" class="text-blue-400 hover:text-blue-300">
                                                {{ $p->firstname }} {{ $p->lastname }}
                                            </a>
                                        </td>
                                        <td class="text-center px-3 py-2">{{ $p->tackle }}</td>
                                        <td class="text-center px-3 py-2">{{ $p->cover }}</td>
                                        <td class="text-center px-3 py-2">{{ $p->interception }}</td>
                                        <td class="text-center px-3 py-2">{{ $range($p->pivot->sack_from, $p->pivot->sack_to) }}</td>
                                        <td class="text-center px-3 py-2">{{ $range($p->pivot->interception_from, $p->pivot->interception_to) }}</td>
                                        <td class="text-center px-3 py-2">{{ $range($p->pivot->tackle_from ?? null, $p->pivot->tackle_to ?? null) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="px-3 py-3 text-gray-400">No LB players.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- DB --}}
                    <div class="rounded-lg border border-white/10 overflow-hidden">
                        <div class="bg-white/5 px-3 py-2 font-semibold text-sm">Defensive Backs</div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-gray-300">
                                <tr class="border-b border-white/10">
                                    <th class="text-left px-3 py-2">Pos</th>
                                    <th class="text-left px-3 py-2">Player</th>
                                    <th class="text-center px-3 py-2">Tkl</th>
                                    <th class="text-center px-3 py-2">Cov</th>
                                    <th class="text-center px-3 py-2">Int</th>
                                    <th class="text-center px-3 py-2">Sack Rng</th>
                                    <th class="text-center px-3 py-2">Int Rng</th>
                                    <th class="text-center px-3 py-2">Tkl Rng</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-white/10">
                                @forelse($db as $p)
                                    <tr class="bg-gray-950">
                                        <td class="px-3 py-2 font-semibold">{{ $p->pivot->depth_chart_position }}</td>
                                        <td class="px-3 py-2">
                                            <a href="{{ route('players.show', $p) }}" class="text-blue-400 hover:text-blue-300">
                                                {{ $p->firstname }} {{ $p->lastname }}
                                            </a>
                                        </td>
                                        <td class="text-center px-3 py-2">{{ $p->tackle }}</td>
                                        <td class="text-center px-3 py-2">{{ $p->cover }}</td>
                                        <td class="text-center px-3 py-2">{{ $p->interception }}</td>
                                        <td class="text-center px-3 py-2">{{ $range($p->pivot->sack_from, $p->pivot->sack_to) }}</td>
                                        <td class="text-center px-3 py-2">{{ $range($p->pivot->interception_from, $p->pivot->interception_to) }}</td>
                                        <td class="text-center px-3 py-2">{{ $range($p->pivot->tackle_from ?? null, $p->pivot->tackle_to ?? null) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="px-3 py-3 text-gray-400">No DB players.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {{-- SPECIAL TEAMS TAB --}}
            <div x-show="tab === 'special'" x-cloak class="mt-6 space-y-8">
                {{-- K / P --}}
                <div>
                    <div class="text-lg font-semibold">Kickers / Punters</div>

                    <div class="mt-2 overflow-x-auto rounded-lg border border-white/10">
                        <table class="min-w-full text-sm">
                            <thead class="bg-white/5 text-gray-300">
                            <tr>
                                <th class="text-left px-3 py-2">Pos</th>
                                <th class="text-left px-3 py-2">Player</th>
                                <th class="text-center px-3 py-2">Kick30</th>
                                <th class="text-center px-3 py-2">Kick39</th>
                                <th class="text-center px-3 py-2">Kick49</th>
                                <th class="text-center px-3 py-2">Kick50</th>
                                <th class="text-center px-3 py-2">PuntDist</th>
                                <th class="text-center px-3 py-2">PoochYd</th>
                                <th class="text-center px-3 py-2">Pooch</th>
                                <th class="text-center px-3 py-2">Block</th>
                                <th class="text-center px-3 py-2">Kick Rng</th>
                                <th class="text-center px-3 py-2">Punt Rng</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                            @forelse($kp as $p)
                                <tr class="bg-gray-950">
                                    <td class="px-3 py-2 font-semibold">{{ strtoupper((string)$p->pivot->position) }}</td>
                                    <td class="px-3 py-2">
                                        <a href="{{ route('players.show', $p) }}" class="text-blue-400 hover:text-blue-300">
                                            {{ $p->firstname }} {{ $p->lastname }}
                                        </a>
                                    </td>

                                    <td class="text-center px-3 py-2">{{ $p->kick30 }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->kick39 }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->kick49 }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->kick50 }}</td>

                                    <td class="text-center px-3 py-2">{{ $p->punt_distance }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->punt_pooch_yard }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->punt_pooch }}</td>
                                    <td class="text-center px-3 py-2">{{ $p->punt_block }}</td>

                                    <td class="text-center px-3 py-2">{{ $range($p->pivot->kick_from, $p->pivot->kick_to) }}</td>
                                    <td class="text-center px-3 py-2">{{ $range($p->pivot->punt_from, $p->pivot->punt_to) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="px-3 py-3 text-gray-400">No K/P players.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- RETURNERS --}}
                <div>
                    <div class="text-lg font-semibold">Return Depth Charts</div>

                    <div class="mt-2 grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div class="rounded-lg border border-white/10 overflow-hidden">
                            <div class="bg-white/5 px-3 py-2 font-semibold text-sm">Kick Return</div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="text-gray-300">
                                    <tr class="border-b border-white/10">
                                        <th class="text-left px-3 py-2">KR Slot</th>
                                        <th class="text-left px-3 py-2">Player</th>
                                        <th class="text-center px-3 py-2">Yds</th>
                                        <th class="text-center px-3 py-2">Spd</th>
                                        <th class="text-center px-3 py-2">Fum</th>
                                    </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/10">
                                    @forelse($kr as $p)
                                        <tr class="bg-gray-950">
                                            <td class="px-3 py-2 font-semibold">{{ $p->pivot->kick_return_depth_chart_position }}</td>
                                            <td class="px-3 py-2">
                                                <a href="{{ route('players.show', $p) }}" class="text-blue-400 hover:text-blue-300">
                                                    {{ $p->firstname }} {{ $p->lastname }}
                                                </a>
                                            </td>
                                            <td class="text-center px-3 py-2">{{ $p->return_yards }}</td>
                                            <td class="text-center px-3 py-2">{{ $p->return_speed }}</td>
                                            <td class="text-center px-3 py-2">{{ $p->return_fumble }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-3 py-3 text-gray-400">No kick returners assigned.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="rounded-lg border border-white/10 overflow-hidden">
                            <div class="bg-white/5 px-3 py-2 font-semibold text-sm">Punt Return</div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead class="text-gray-300">
                                    <tr class="border-b border-white/10">
                                        <th class="text-left px-3 py-2">PR Slot</th>
                                        <th class="text-left px-3 py-2">Player</th>
                                        <th class="text-center px-3 py-2">Yds</th>
                                        <th class="text-center px-3 py-2">Spd</th>
                                        <th class="text-center px-3 py-2">Fum</th>
                                    </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/10">
                                    @forelse($pr as $p)
                                        <tr class="bg-gray-950">
                                            <td class="px-3 py-2 font-semibold">{{ $p->pivot->punt_return_depth_chart_position }}</td>
                                            <td class="px-3 py-2">
                                                <a href="{{ route('players.show', $p) }}" class="text-blue-400 hover:text-blue-300">
                                                    {{ $p->firstname }} {{ $p->lastname }}
                                                </a>
                                            </td>
                                            <td class="text-center px-3 py-2">{{ $p->return_yards }}</td>
                                            <td class="text-center px-3 py-2">{{ $p->return_speed }}</td>
                                            <td class="text-center px-3 py-2">{{ $p->return_fumble }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-3 py-3 text-gray-400">No punt returners assigned.</td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="mt-10 text-xs text-gray-500">
                Notes:
                <ul class="list-disc ml-5 mt-2 space-y-1">
                    <li>“Ratings” come from the <code>players</code> table columns (rush, pass_accuracy, tackle, etc.).</li>
                    <li>“Ranges” come from the <code>team_players</code> pivot columns (catch_from/catch_to, etc.).</li>
                    <li>Section membership is based on <code>pivot->depth_chart_position</code> (QB1, RB2, DE3, etc.).</li>
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
