<x-layouts.app>
    @php
        $home = $game->homeTeam;
        $away = $game->awayTeam;

        $homeName = trim(($home->city ?? '').' '.($home->name ?? 'HOME'));
        $awayName = trim(($away->city ?? '').' '.($away->name ?? 'AWAY'));
    @endphp

    <div class="min-h-screen bg-gray-950 text-gray-100 p-6">
        <div class="max-w-7xl mx-auto space-y-6">

            {{-- Header --}}
            <div class="flex items-end justify-between gap-4">
                <div>
                    <div class="text-sm text-gray-400">Boxscore</div>
                    <div class="text-3xl font-bold tracking-tight">
                        {{ $awayName }} @ {{ $homeName }}
                    </div>
                    <div class="text-sm text-gray-400 mt-1">
                        Game #{{ $game->id }} — {{ $game->season_year ?? $game->year ?? '' }}
                    </div>
                </div>

                <div class="text-right">
                    <div class="text-2xl font-bold">
                        {{ $game->away_score }} - {{ $game->home_score }}
                    </div>
                    <div class="text-sm text-gray-400">
                        Final: {{ $game->phase ?? 'NORMAL' }}
                    </div>
                </div>
            </div>

            <div class="h-px bg-white/10"></div>

            {{-- Score by Quarter --}}
            <div class="rounded-lg border border-white/10 overflow-hidden">
                <div class="bg-white/5 px-4 py-2 font-semibold">Score by Quarter</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-gray-300 border-b border-white/10">
                        <tr>
                            <th class="text-left px-4 py-2"></th>
                            <th class="text-center px-3 py-2">Q1</th>
                            <th class="text-center px-3 py-2">Q2</th>
                            <th class="text-center px-3 py-2">Q3</th>
                            <th class="text-center px-3 py-2">Q4</th>
                            <th class="text-center px-3 py-2">OT</th>
                            <th class="text-center px-3 py-2 font-semibold">Total</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                        <tr>
                            <td class="px-4 py-2 font-semibold">{{ $awayName }}</td>
                            <td class="text-center px-3 py-2">{{ $awayQ[0] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $awayQ[1] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $awayQ[2] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $awayQ[3] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $awayQ[4] ?? 0 }}</td>
                            <td class="text-center px-3 py-2 font-semibold">{{ array_sum($awayQ) }}</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-semibold">{{ $homeName }}</td>
                            <td class="text-center px-3 py-2">{{ $homeQ[0] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $homeQ[1] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $homeQ[2] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $homeQ[3] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $homeQ[4] ?? 0 }}</td>
                            <td class="text-center px-3 py-2 font-semibold">{{ array_sum($homeQ) }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Scoring Summary --}}
            <div class="rounded-lg border border-white/10 overflow-hidden">
                <div class="bg-white/5 px-4 py-2 font-semibold">Scoring Summary</div>
                <div class="p-4 space-y-2 text-sm">
                    @forelse($scoring as $p)
                        <div class="flex items-start justify-between gap-4 border-b border-white/10 pb-2">
                            <div class="text-gray-300">
                                <span class="font-semibold">Q{{ $p->quarter ?? '?' }}</span>
                                <span class="opacity-70">#{{ $p->seq }}</span>
                            </div>
                            <div class="flex-1">
                                <div class="font-medium">{{ $p->summary }}</div>
                                <div class="text-xs text-gray-400">
                                    Before: {{ $p->side_before }} {{ $p->yardline_before }} — After: {{ $p->side_after }} {{ $p->yardline_after }}
                                </div>
                            </div>
                            <div class="text-right font-semibold">
                                @if((int)($p->points ?? 0) !== 0)
                                    +{{ (int)$p->points }}
                                @elseif((int)($p->touchdown ?? 0) === 1)
                                    TD
                                @else
                                    &nbsp;
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-gray-400">No scoring plays recorded.</div>
                    @endforelse
                </div>
            </div>

            {{-- Team Stats --}}
            <div class="rounded-lg border border-white/10 overflow-hidden">
                <div class="bg-white/5 px-4 py-2 font-semibold">Team Stats</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-gray-300 border-b border-white/10">
                        <tr>
                            <th class="text-left px-4 py-2">Stat</th>
                            <th class="text-center px-3 py-2">{{ $awayName }}</th>
                            <th class="text-center px-3 py-2">{{ $homeName }}</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                        @php
                            $A = $teamStats['away'] ?? [];
                            $H = $teamStats['home'] ?? [];
                            $fmtPA = fn($r) => ($r['pass_comp'] ?? 0).'-'.($r['pass_att'] ?? 0);
                            $fmtRY = fn($r) => ($r['rush_att'] ?? 0).' for '.($r['rush_yds'] ?? 0);
                            $fmtPY = fn($r) => $fmtPA($r).' for '.($r['pass_yds'] ?? 0);
                        @endphp

                        <tr>
                            <td class="px-4 py-2">First Downs</td>
                            <td class="text-center px-3 py-2">{{ $A['first_downs'] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $H['first_downs'] ?? 0 }}</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2">Rushing</td>
                            <td class="text-center px-3 py-2">{{ $fmtRY($A) }}</td>
                            <td class="text-center px-3 py-2">{{ $fmtRY($H) }}</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2">Passing</td>
                            <td class="text-center px-3 py-2">{{ $fmtPY($A) }}</td>
                            <td class="text-center px-3 py-2">{{ $fmtPY($H) }}</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2">Turnovers</td>
                            <td class="text-center px-3 py-2">{{ $A['turnovers'] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $H['turnovers'] ?? 0 }}</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2">Sacks Taken</td>
                            <td class="text-center px-3 py-2">{{ $A['sacks_taken'] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $H['sacks_taken'] ?? 0 }}</td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2">TDs</td>
                            <td class="text-center px-3 py-2">{{ $A['tds'] ?? 0 }}</td>
                            <td class="text-center px-3 py-2">{{ $H['tds'] ?? 0 }}</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Individual Player Stats --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                @foreach(['AWAY' => $awayName, 'HOME' => $homeName] as $side => $label)
                    @php $ps = $playerStats[$side] ?? []; @endphp

                    <div class="rounded-lg border border-white/10 overflow-hidden">
                        <div class="bg-white/5 px-4 py-2 font-semibold">{{ $label }} — Individual Stats</div>

                        <div class="p-4 space-y-5">

                            {{-- Passing --}}
                            <div>
                                <div class="text-sm font-semibold text-gray-200 mb-2">Passing</div>
                                <div class="overflow-x-auto rounded border border-white/10">
                                    <table class="min-w-full text-sm">
                                        <thead class="text-gray-300 bg-white/5">
                                        <tr>
                                            <th class="text-left px-3 py-2">Player</th>
                                            <th class="text-center px-3 py-2">C/A</th>
                                            <th class="text-center px-3 py-2">Yds</th>
                                            <th class="text-center px-3 py-2">TD</th>
                                            <th class="text-center px-3 py-2">INT</th>
                                        </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/10">
                                        @forelse(($ps['passing'] ?? []) as $r)
                                            <tr>
                                                <td class="px-3 py-2">{{ $r['name'] ?? '—' }}</td>
                                                <td class="text-center px-3 py-2">{{ ($r['cmp'] ?? 0) }}/{{ ($r['att'] ?? 0) }}</td>
                                                <td class="text-center px-3 py-2">{{ $r['yds'] ?? 0 }}</td>
                                                <td class="text-center px-3 py-2">{{ $r['td'] ?? 0 }}</td>
                                                <td class="text-center px-3 py-2">{{ $r['int'] ?? 0 }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="px-3 py-2 text-gray-400">No passing stats.</td></tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {{-- Rushing --}}
                            <div>
                                <div class="text-sm font-semibold text-gray-200 mb-2">Rushing</div>
                                <div class="overflow-x-auto rounded border border-white/10">
                                    <table class="min-w-full text-sm">
                                        <thead class="text-gray-300 bg-white/5">
                                        <tr>
                                            <th class="text-left px-3 py-2">Player</th>
                                            <th class="text-center px-3 py-2">Att</th>
                                            <th class="text-center px-3 py-2">Yds</th>
                                            <th class="text-center px-3 py-2">TD</th>
                                        </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/10">
                                        @forelse(($ps['rushing'] ?? []) as $r)
                                            <tr>
                                                <td class="px-3 py-2">{{ $r['name'] ?? '—' }}</td>
                                                <td class="text-center px-3 py-2">{{ $r['att'] ?? 0 }}</td>
                                                <td class="text-center px-3 py-2">{{ $r['yds'] ?? 0 }}</td>
                                                <td class="text-center px-3 py-2">{{ $r['td'] ?? 0 }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="px-3 py-2 text-gray-400">No rushing stats.</td></tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {{-- Receiving --}}
                            <div>
                                <div class="text-sm font-semibold text-gray-200 mb-2">Receiving</div>
                                <div class="overflow-x-auto rounded border border-white/10">
                                    <table class="min-w-full text-sm">
                                        <thead class="text-gray-300 bg-white/5">
                                        <tr>
                                            <th class="text-left px-3 py-2">Player</th>
                                            <th class="text-center px-3 py-2">Rec</th>
                                            <th class="text-center px-3 py-2">Yds</th>
                                            <th class="text-center px-3 py-2">TD</th>
                                        </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/10">
                                        @forelse(($ps['receiving'] ?? []) as $r)
                                            <tr>
                                                <td class="px-3 py-2">{{ $r['name'] ?? '—' }}</td>
                                                <td class="text-center px-3 py-2">{{ $r['rec'] ?? 0 }}</td>
                                                <td class="text-center px-3 py-2">{{ $r['yds'] ?? 0 }}</td>
                                                <td class="text-center px-3 py-2">{{ $r['td'] ?? 0 }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="px-3 py-2 text-gray-400">No receiving stats.</td></tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {{-- Defense --}}
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <div class="text-sm font-semibold text-gray-200 mb-2">Tackles</div>
                                    <div class="overflow-x-auto rounded border border-white/10">
                                        <table class="min-w-full text-sm">
                                            <thead class="text-gray-300 bg-white/5">
                                            <tr>
                                                <th class="text-left px-3 py-2">Player</th>
                                                <th class="text-center px-3 py-2">Tkl</th>
                                            </tr>
                                            </thead>
                                            <tbody class="divide-y divide-white/10">
                                            @forelse(($ps['defense']['tackles'] ?? []) as $r)
                                                <tr>
                                                    <td class="px-3 py-2">{{ $r['name'] ?? '—' }}</td>
                                                    <td class="text-center px-3 py-2">{{ $r['tkl'] ?? 0 }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="2" class="px-3 py-2 text-gray-400">No tackles logged.</td></tr>
                                            @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div>
                                    <div class="text-sm font-semibold text-gray-200 mb-2">Interceptions</div>
                                    <div class="overflow-x-auto rounded border border-white/10">
                                        <table class="min-w-full text-sm">
                                            <thead class="text-gray-300 bg-white/5">
                                            <tr>
                                                <th class="text-left px-3 py-2">Player</th>
                                                <th class="text-center px-3 py-2">INT</th>
                                            </tr>
                                            </thead>
                                            <tbody class="divide-y divide-white/10">
                                            @forelse(($ps['defense']['ints'] ?? []) as $r)
                                                <tr>
                                                    <td class="px-3 py-2">{{ $r['name'] ?? '—' }}</td>
                                                    <td class="text-center px-3 py-2">{{ $r['int'] ?? 0 }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="2" class="px-3 py-2 text-gray-400">No interceptions.</td></tr>
                                            @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Play by Play --}}
            <div class="rounded-lg border border-white/10 overflow-hidden">
                <div class="bg-white/5 px-4 py-2 font-semibold">Play-by-Play</div>
                <div class="p-4 space-y-2 max-h-[520px] overflow-y-auto">
                    @forelse($plays as $p)
                        <div class="text-sm border-b border-white/10 pb-2">
                            <div class="text-gray-400">
                                #{{ $p->seq }}
                                — {{ $p->possession_before }}
                                — {{ $p->side_before }} {{ $p->yardline_before }}
                                — {{ $p->down_before }}&amp;{{ $p->togo_before }}
                            </div>
                            <div class="font-medium">{{ $p->summary }}</div>
                            <div class="text-gray-400">
                                After: {{ $p->possession_after }} — {{ $p->side_after }} {{ $p->yardline_after }}
                                — {{ $p->down_after }}&amp;{{ $p->togo_after }}
                            </div>
                        </div>
                    @empty
                        <div class="text-gray-400">No plays yet.</div>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</x-layouts.app>
