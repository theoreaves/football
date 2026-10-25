<section role="tabpanel" id="panel-special-teams" aria-labelledby="tab-special-teams" data-team-panel="special-teams" hidden class="space-y-4">
    <h2 class="text-xl font-semibold">Special Teams Depth Charts</h2>
    <p class="text-sm text-gray-600">Assign players to the six special-teams units. Leave a slot set to Automatic to use the game engine's selection. Assignments are saved with the team and captured for new games; existing games and replays do not change.</p>
    <p class="text-sm text-amber-800">ST1b stores and validates assignments. Unit-specific formations and live on-field substitution arrive in ST1c. Current K, P, and returner specialists remain configured on the Specialists tab.</p>
    @php
        $stUnits = [
            'punt_coverage' => ['Punt Coverage', ['P','LS','PP','G1','G2','T1','T2','WL','WR','GL','GR']],
            'punt_return' => ['Punt Return', ['PR','R1','R2','R3','B1','B2','B3','B4','B5','B6','B7']],
            'kickoff_coverage' => ['Kickoff Coverage', ['K','C1','C2','C3','C4','C5','C6','C7','C8','C9','C10']],
            'kickoff_return' => ['Kickoff Return', ['KR','B1','B2','B3','B4','B5','B6','B7','B8','B9','B10']],
            'field_goal' => ['Field Goal / Extra Point', ['K','LS','H','LG','RG','LT','RT','W1','W2','B1','B2']],
            'field_goal_block' => ['Field Goal Block', ['R1','R2','R3','R4','R5','R6','R7','R8','R9','R10','R11']],
        ];
        $stPlayers = $specialistPlayers ?? collect();
    @endphp
    @foreach($stUnits as $unit => [$title, $slots])
        <details class="border rounded-lg p-3" @if($loop->first) open @endif>
            <summary class="cursor-pointer font-semibold">{{ $title }} <span class="text-xs font-normal text-gray-500">(11 positions)</span></summary>
            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($slots as $slot)
                    <label class="block text-sm font-medium">{{ $slot }}
                        <select name="team_settings[special_teams][{{ $unit }}][{{ $slot }}]" class="mt-1 block w-full border rounded p-2">
                            <option value="">Auto Assign</option>
                            @foreach($stPlayers as $player)
                                <option value="{{ $player->id }}" @selected((string) old('team_settings.special_teams.'.$unit.'.'.$slot, $team->team_settings['special_teams'][$unit][$slot] ?? '') === (string) $player->id)>
                                    #{{ $player->pivot->jersey_number }} {{ $player->firstname }} {{ $player->lastname }} · {{ $player->pivot->position ?: $player->position }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
        </details>
    @endforeach
    @if(!$team->exists)
        <p class="text-sm text-gray-600">Save the team and add players before assigning special-teams units.</p>
    @endif
</section>
