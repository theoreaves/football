@if(session('status'))<p class="season-notice" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p class="season-notice text-red-300" role="alert">{{ $errors->first() }}</p>@endif
<section class="season-panel">
    <h2>Season specialists</h2>
    <p>Assignments use this team’s {{ $season->year }} roster and apply to newly started season games. Games already started keep their saved assignments.</p>
    <form method="POST" action="{{ route('seasons.specialists', [$season, $team]) }}" class="season-fields mt-4">
        @csrf @method('PUT')
        @foreach(['third_down_back' => 'Third-down / short-yardage back', 'fourth_down_back' => 'Fourth-down back', 'kick_returner' => 'Kick returner', 'punt_returner' => 'Punt returner'] as $role => $label)
            @php
                $overrides = $season->settings['specialists'][$team->id] ?? [];
                $selection = old($role, array_key_exists($role, $overrides) ? $overrides[$role] : 'default');
                $defaultId = $team->team_settings['specialists'][$role] ?? null;
                $defaultPlayer = $players->firstWhere('id', $defaultId);
                $allowed = $role === 'fourth_down_back' ? array_unique(array_merge(...array_values(\App\Services\Simulation\RosterBuilder::GROUPS))) : ($role === 'third_down_back' ? ['RB', 'FB'] : ['RB', 'FB', 'WR', 'TE', 'CB', 'DB', 'S', 'FS', 'SS']);
            @endphp
            <label>{{ $label }}<select name="{{ $role }}">
                <option value="default" @selected($selection === 'default')>Team default · {{ $defaultPlayer ? $defaultPlayer->firstname.' '.$defaultPlayer->lastname : 'Automatic' }}</option>
                <option value="" @selected($selection === null || $selection === '')>Automatic / normal depth chart</option>
                @foreach($players as $player)
                    @if(in_array(strtoupper($player->pivot->position ?: $player->position), $allowed, true))
                        <option value="{{ $player->id }}" @selected((string) $selection === (string) $player->id)>#{{ $player->pivot->jersey_number }} {{ $player->firstname }} {{ $player->lastname }} · {{ $player->pivot->position }}</option>
                    @endif
                @endforeach
            </select></label>
        @endforeach
        <button class="landing-button">Save specialists</button>
    </form>
    <p class="mt-4">The situational back plays every third down and whenever two yards or fewer remain. An in-game manual RB selection takes priority. Injury and fatigue safeguards still apply.</p>
    <p>Team default follows general team settings. Automatic explicitly uses normal selection for this season. Other seasons and exhibitions are unchanged.</p>
<p class="mt-4">A fourth-down back may come from any position, including defense, and takes priority over the short-yardage back on fourth down. He lines up and receives credit as the running back.</p>
</section>
