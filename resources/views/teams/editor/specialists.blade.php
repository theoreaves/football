<section role="tabpanel" id="panel-specialists" aria-labelledby="tab-specialists" data-team-panel="specialists" hidden class="space-y-4">
    <h2 class="text-xl font-semibold">Specialists</h2>
    <p class="text-sm text-gray-600">Optional assignments from the latest roster year. New games capture these choices. Existing games keep their saved assignments. A choice is used only when that player is in the game’s captured roster.</p>
    @foreach(['third_down_back' => 'Third-down / short-yardage back', 'kick_returner' => 'Kick returner', 'punt_returner' => 'Punt returner'] as $role => $label)
        <label class="block">{{ $label }}
            <select name="team_settings[specialists][{{ $role }}]" class="block w-full border rounded p-2 mt-1">
                <option value="">Automatic / normal depth chart</option>
                @foreach($specialistPlayers ?? [] as $player)
                    @if(in_array(strtoupper($player->pivot->position ?: $player->position), $role === 'third_down_back' ? ['RB', 'FB'] : ['RB', 'FB', 'WR', 'TE', 'CB', 'DB', 'S', 'FS', 'SS'], true))
                        <option value="{{ $player->id }}" @selected((string) old('team_settings.specialists.'.$role, $team->team_settings['specialists'][$role] ?? '') === (string) $player->id)>#{{ $player->pivot->jersey_number }} {{ $player->firstname }} {{ $player->lastname }} · {{ $player->pivot->position ?: $player->position }}</option>
                    @endif
                @endforeach
            </select>
        </label>
    @endforeach
    <p class="text-sm text-gray-600">The situational back plays every third down and on first, second or fourth down with two yards or fewer to go. An in-game RB override takes priority. Injured or fatigued specialists use the normal depth chart.</p>
    @if(!$team->exists)<p class="text-sm">Save the team and add players before assigning specialists.</p>@endif
</section>
