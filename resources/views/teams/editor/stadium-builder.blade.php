<label class="block">Stadium name<input name="team_settings[stadium][name]" maxlength="80" value="{{ old('team_settings.stadium.name', $team->team_settings['stadium']['name'] ?? '') }}" placeholder="{{ $team->name }} Stadium" class="block w-full border rounded p-2"></label>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <label>Seating tiers<select name="team_settings[stadium][tiers]" class="block w-full border rounded p-2">
        <option value="">Style default</option>
        @foreach([1 => 'Lower deck', 2 => 'Lower + middle decks', 3 => 'Lower + middle + upper decks'] as $value => $label)<option value="{{ $value }}" @selected((string) old('team_settings.stadium.tiers', $team->team_settings['stadium']['tiers'] ?? '') === (string) $value)>{{ $label }}</option>@endforeach
    </select></label>
    <label>Rows per tier<input type="number" min="4" max="12" step="1" name="team_settings[stadium][rows]" value="{{ old('team_settings.stadium.rows', $team->team_settings['stadium']['rows'] ?? '') }}" placeholder="Style default" class="block w-full border rounded p-2"></label>
    <label>Bowl rise per row (yards)<input type="number" min="0.75" max="1.75" step="0.05" name="team_settings[stadium][row_height]" value="{{ old('team_settings.stadium.row_height', $team->team_settings['stadium']['row_height'] ?? '') }}" placeholder="Style default" class="block w-full border rounded p-2"></label>
</div>
<input type="hidden" name="team_settings[stadium][one_color]" value="0">
<label class="block"><input data-stadium-one-color type="checkbox" name="team_settings[stadium][one_color]" value="1" @checked(old('team_settings.stadium.one_color', $team->team_settings['stadium']['one_color'] ?? true))> Use one color for all tiers (Seats color below)</label>
<div class="grid grid-cols-3 gap-4">
    @foreach(['lower' => 'Lower deck', 'middle' => 'Middle deck', 'upper' => 'Upper deck'] as $key => $label)
    <label>{{ $label }}<input data-tier-color type="color" name="team_settings[stadium][tier_colors][{{ $key }}]" value="{{ old('team_settings.stadium.tier_colors.'.$key, $team->team_settings['stadium']['tier_colors'][$key] ?? $team->stadium_seat_color ?? $team->team_color1 ?? '#315b85') }}" class="block w-full h-10 border rounded disabled:opacity-50"></label>
    @endforeach
</div>
<input type="hidden" name="team_settings[stadium][wall_one_color]" value="0">
<label class="block"><input data-stadium-one-wall-color type="checkbox" name="team_settings[stadium][wall_one_color]" value="1" @checked(old('team_settings.stadium.wall_one_color', $team->team_settings['stadium']['wall_one_color'] ?? true))> Use one wall color for all tiers (Walls / structure color below)</label>
<div class="grid grid-cols-3 gap-4">
    @foreach(['lower' => 'Lower deck walls', 'middle' => 'Middle deck walls', 'upper' => 'Upper deck walls'] as $key => $label)
    <label>{{ $label }}<input data-tier-wall-color type="color" name="team_settings[stadium][tier_wall_colors][{{ $key }}]" value="{{ old('team_settings.stadium.tier_wall_colors.'.$key, $team->team_settings['stadium']['tier_wall_colors'][$key] ?? $team->stadium_wall_color ?? '#657587') }}" class="block w-full h-10 border rounded disabled:opacity-50"></label>
    @endforeach
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @foreach(['exterior_color' => ['Exterior walls', '#a8a29e'], 'field_wall_color' => ['Field-facing walls', $team->team_color1 ?: '#315b85'], 'home_tunnel_color' => ['Home tunnel', $team->team_color1 ?: '#315b85'], 'away_tunnel_color' => ['Visitor tunnel', $team->stadium_wall_color ?: '#657587']] as $key => [$label, $default])
    <label>{{ $label }}<input type="color" name="team_settings[stadium][{{ $key }}]" value="{{ old('team_settings.stadium.'.$key, $team->team_settings['stadium'][$key] ?? $default) }}" class="block w-full h-10 border rounded"></label>
    @endforeach
</div>
<p class="text-sm">Exterior walls default to stone gray. Field walls and the home tunnel default to the primary team color. Visitor tunnels default to the structure color.</p>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    @foreach(['field_wall' => 'Field wall graphic', 'home_tunnel' => 'Home tunnel graphic', 'away_tunnel' => 'Visitor tunnel graphic'] as $part => $label)
    <div data-stadium-art>
        <label>{{ $label }}<input type="file" name="stadium_art_uploads[{{ $part }}]" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm"></label>
        @if($team->exists && !empty($team->team_settings['stadium'][$part.'_art']))
        <img src="{{ route('teams.stadium-art', ['team' => $team, 'file' => basename($team->team_settings['stadium'][$part.'_art'])]) }}" alt="{{ $label }}" class="w-full h-20 object-contain border">
        <label><input type="checkbox" name="stadium_art_clear[{{ $part }}]" value="1"> Remove graphic</label>
        @endif
    </div>
    @endforeach
</div>
<p class="text-sm">PNG, JPEG or WebP up to 5 MB and 4096 pixels per side. Wall graphics use a wide banner panel on each sideline; tunnel graphics fit the roof. Without custom artwork, walls show the team wordmark and the home tunnel shows the team logo. Uploading a new graphic replaces the current one.</p>
<input type="hidden" name="team_settings[stadium][tunnels]" value="0">
<label class="block"><input data-stadium-tunnels type="checkbox" name="team_settings[stadium][tunnels]" value="1" @checked(old('team_settings.stadium.tunnels', $team->team_settings['stadium']['tunnels'] ?? true))> Show home and visitor tunnels</label>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    @foreach(['scoreboards' => ['Scoreboard placement', 'both', ['both' => 'Both end zones', 'left' => 'Left end zone', 'right' => 'Right end zone']], 'scoreboard_size' => ['Scoreboard size', 'medium', ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large']], 'lighting' => ['Lighting fixtures', 'corners', ['corners' => 'Corner towers', 'rim' => 'Around the stadium rim']]] as $key => [$label, $default, $choices])
    <label>{{ $label }}<select name="team_settings[stadium][{{ $key }}]" class="block w-full border rounded p-2">
        @foreach($choices as $value => $title)<option value="{{ $value }}" @selected(old('team_settings.stadium.'.$key, $team->team_settings['stadium'][$key] ?? $default) === $value)>{{ $title }}</option>@endforeach
    </select></label>
    @endforeach
</div>
<p class="text-sm">Lighting fixtures are decorative. Tunnel colors and graphics do not change player entrances.</p>
<div class="flex flex-wrap gap-3 items-end">
    <label class="flex-1">Copy stadium from another team<select data-stadium-copy-team class="block w-full border rounded p-2"><option value="">Choose a team in this save</option>@foreach($stadiumCopies ?? [] as $source)<option value="{{ $source['id'] }}">{{ $source['label'] }}</option>@endforeach</select></label>
    <button type="button" data-stadium-copy class="border rounded px-4 py-2">Load design</button>
    <button type="button" data-stadium-preview class="border border-blue-500 rounded px-4 py-2">Preview stadium in 3D</button>
</div>
<p data-stadium-copy-status role="status" class="text-sm">Copy loads structure and stadium colors into this form. Your stadium name and team artwork stay yours. Save the team when ready.</p>
<script type="application/json" data-stadium-copy-data>@json($stadiumCopies ?? [])</script>
