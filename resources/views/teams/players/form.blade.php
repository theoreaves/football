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
                    <div>
                        <label class="block font-medium mb-1">College</label>
                        <input name="college" value="{{ old('college', $player->college) }}" class="w-full border rounded p-2" />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">High School</label>
                        <input name="high_school" value="{{ old('high_school', $player->high_school) }}" class="w-full border rounded p-2" />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Sleeper #</label>
                        <input name="sleeper_id" value="{{ old('sleeper_id', $player->sleeper_id) }}" class="w-full border rounded p-2" />
                    </div>
                    <div>
                        <label class="block font-medium mb-1">ESPN #</label>
                        <input name="espn_id" value="{{ old('espn_id', $player->espn_id) }}" class="w-full border rounded p-2" />
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


            @php
                $pos = strtoupper(trim($player->position ?? ''));

                $passPos = ['QB'];
                $skillPos = ['QB','RB','WR','TE']; // rushing/receiving/returns
                $defPos = ['DE','DL','DB','LB','CB','S'];
                $kickPos = ['K','P'];

                $showPass = in_array($pos, $passPos, true);
                $showSkill = in_array($pos, $skillPos, true);
                $showDef = in_array($pos, $defPos, true);
                $showKick = in_array($pos, $kickPos, true);
            @endphp
            <div class="rounded-lg border border-white/10 overflow-hidden">
                <div class="bg-white/5 px-4 py-3 font-semibold">
                    Season Stats (Regular Season)
                    <span class="text-gray-800 font-normal ml-2">• {{ $pos }}</span>
                </div>

                @php
                    $tot = [
                        'games' => 0,

                        'pass_completions' => 0,
                        'pass_attempts' => 0,
                        'pass_yards' => 0,
                        'pass_tds' => 0,
                        'interceptions_thrown' => 0,

                        'rush_attempts' => 0,
                        'rush_yards' => 0,
                        'rush_tds' => 0,

                        'receptions' => 0,
                        'receiving_yards' => 0,
                        'receiving_tds' => 0,

                        'kick_returns' => 0,
                        'kick_return_yards' => 0,
                        'kick_return_tds' => 0,

                        'punt_returns' => 0,
                        'punt_return_yards' => 0,
                        'punt_return_tds' => 0,

                        'tackles_total' => 0,
                        'tackles_solo' => 0,
                        'tackles_assist' => 0,
                        'sacks' => 0,
                        'tfl' => 0,
                        'qb_hits' => 0,
                        'def_interceptions' => 0,
                        'passes_defended' => 0,
                        'forced_fumbles' => 0,
                        'fumble_recoveries' => 0,
                        'def_tds' => 0,

                        'fg_made' => 0,
                        'fg_attempts' => 0,
                        'xp_made' => 0,
                        'xp_attempts' => 0,
                        'punts' => 0,
                        'punt_yards' => 0,
                        'punts_inside_20' => 0,
                        'punt_touchbacks' => 0,
                        'punt_blocked' => 0,
                    ];

                    foreach ($player->seasonStats as $s) {
                        foreach ($tot as $k => $v) {
                            $tot[$k] += (int) ($s->{$k} ?? 0);
                        }
                    }
                @endphp




                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-gray-800 border-b border-white/10">
                        <tr>
                            <th class="text-left px-4 py-3">Year</th>
                            <th class="text-right px-4 py-3">Ratings</th>
                            <th class="text-center px-4 py-3">GP</th>


                        @if($showPass)
                                <th class="text-center px-4 py-3">Cmp</th>
                                <th class="text-center px-4 py-3">Att</th>
                                <th class="text-center px-4 py-3">Pass Yds</th>
                                <th class="text-center px-4 py-3">Pass TD</th>
                                <th class="text-center px-4 py-3">INT</th>
                            @endif

                            @if($showSkill)
                                <th class="text-center px-4 py-3">Rush Att</th>
                                <th class="text-center px-4 py-3">Rush Yds</th>
                                <th class="text-center px-4 py-3">Rush TD</th>

                                <th class="text-center px-4 py-3">Rec</th>
                                <th class="text-center px-4 py-3">Rec Yds</th>
                                <th class="text-center px-4 py-3">Rec TD</th>

                                <th class="text-center px-4 py-3">KR</th>
                                <th class="text-center px-4 py-3">KR Yds</th>
                                <th class="text-center px-4 py-3">KRTD</th>

                                <th class="text-center px-4 py-3">PR</th>
                                <th class="text-center px-4 py-3">PR Yds</th>
                                <th class="text-center px-4 py-3">PRTD</th>
                            @endif

                            @if($showDef)
                                <th class="text-center px-4 py-3">Tkl</th>
                                <th class="text-center px-4 py-3">Solo</th>
                                <th class="text-center px-4 py-3">Ast</th>
                                <th class="text-center px-4 py-3">Sck</th>
                                <th class="text-center px-4 py-3">TFL</th>
                                <th class="text-center px-4 py-3">QB Hits</th>
                                <th class="text-center px-4 py-3">INT</th>
                                <th class="text-center px-4 py-3">PD</th>
                                <th class="text-center px-4 py-3">FF</th>
                                <th class="text-center px-4 py-3">FR</th>
                                <th class="text-center px-4 py-3">TD</th>
                            @endif

                            @if($showKick)
                                <th class="text-center px-4 py-3">FG</th>
                                <th class="text-center px-4 py-3">XP</th>
                                <th class="text-center px-4 py-3">Punts</th>
                                <th class="text-center px-4 py-3">Punt Yds</th>
                                <th class="text-center px-4 py-3">In20</th>
                                <th class="text-center px-4 py-3">TB</th>
                                <th class="text-center px-4 py-3">Blk</th>
                            @endif
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-white/10">
                        @forelse($player->seasonStats as $s)
                            <tr class="bg-gray-100">
                                <td class="px-4 py-3 font-semibold">{{ $s->season_year }}</td>

                                <td class="text-right px-4 py-3">
                                    <form method="POST"
                                          action="{{ route('teams.editor.teams.players.ratings.fromSeason', [$team, $player, $s->season_year, 'year' => $year]) }}"
                                          onsubmit="return confirm('Generate ratings from {{ $s->season_year }} stats? This will overwrite current ratings.');">
                                        @csrf
                                        <button type="submit"
                                                class="px-1 py-1 rounded bg-indigo-600 text-white hover:bg-indigo-500">
                                            Generate
                                        </button>
                                    </form>
                                </td>

                                <td class="text-center px-4 py-3">{{ $s->games }}</td>

                                @if($showPass)
                                    <td class="text-center px-4 py-3">{{ $s->pass_completions }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->pass_attempts }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($s->pass_yards) }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->pass_tds }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->interceptions_thrown }}</td>
                                @endif

                                @if($showSkill)
                                    <td class="text-center px-4 py-3">{{ $s->rush_attempts }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($s->rush_yards) }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->rush_tds }}</td>

                                    <td class="text-center px-4 py-3">{{ $s->receptions }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($s->receiving_yards) }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->receiving_tds }}</td>

                                    <td class="text-center px-4 py-3">{{ $s->kick_returns }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($s->kick_return_yards) }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->kick_return_tds }}</td>

                                    <td class="text-center px-4 py-3">{{ $s->punt_returns }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($s->punt_return_yards) }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->punt_return_tds }}</td>
                                @endif

                                @if($showDef)
                                    <td class="text-center px-4 py-3">{{ $s->tackles_total }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->tackles_solo }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->tackles_assist }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->sacks }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->tfl }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->qb_hits }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->def_interceptions }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->passes_defended }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->forced_fumbles }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->fumble_recoveries }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->def_tds }}</td>
                                @endif

                                @if($showKick)
                                    <td class="text-center px-4 py-3">{{ $s->fg_made }}-{{ $s->fg_attempts }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->xp_made }}-{{ $s->xp_attempts }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->punts }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($s->punt_yards) }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->punts_inside_20 }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->punt_touchbacks }}</td>
                                    <td class="text-center px-4 py-3">{{ $s->punt_blocked }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="40" class="px-4 py-6 text-center text-gray-400">
                                    No season stats found for this player yet.
                                </td>
                            </tr>
                        @endforelse


                        @if($player->seasonStats->count())
                            <tr class="bg-indigo-100 font-semibold border-t-2 border-indigo-300">
                                <td class="px-4 py-3">Totals</td>

                                <td class="px-4 py-3"></td> {{-- Ratings column spacer --}}

                                <td class="text-center px-4 py-3">{{ $tot['games'] }}</td>

                                @if($showPass)
                                    <td class="text-center px-4 py-3">{{ $tot['pass_completions'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['pass_attempts'] }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($tot['pass_yards']) }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['pass_tds'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['interceptions_thrown'] }}</td>
                                @endif

                                @if($showSkill)
                                    <td class="text-center px-4 py-3">{{ $tot['rush_attempts'] }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($tot['rush_yards']) }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['rush_tds'] }}</td>

                                    <td class="text-center px-4 py-3">{{ $tot['receptions'] }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($tot['receiving_yards']) }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['receiving_tds'] }}</td>

                                    <td class="text-center px-4 py-3">{{ $tot['kick_returns'] }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($tot['kick_return_yards']) }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['kick_return_tds'] }}</td>

                                    <td class="text-center px-4 py-3">{{ $tot['punt_returns'] }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($tot['punt_return_yards']) }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['punt_return_tds'] }}</td>
                                @endif

                                @if($showDef)
                                    <td class="text-center px-4 py-3">{{ $tot['tackles_total'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['tackles_solo'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['tackles_assist'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['sacks'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['tfl'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['qb_hits'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['def_interceptions'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['passes_defended'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['forced_fumbles'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['fumble_recoveries'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['def_tds'] }}</td>
                                @endif

                                @if($showKick)
                                    <td class="text-center px-4 py-3">{{ $tot['fg_made'] }}-{{ $tot['fg_attempts'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['xp_made'] }}-{{ $tot['xp_attempts'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['punts'] }}</td>
                                    <td class="text-center px-4 py-3">{{ number_format($tot['punt_yards']) }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['punts_inside_20'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['punt_touchbacks'] }}</td>
                                    <td class="text-center px-4 py-3">{{ $tot['punt_blocked'] }}</td>
                                @endif
                            </tr>
                        @endif



                        </tbody>
                    </table>
                </div>
            </div>


    </div>
</x-layouts.app>
