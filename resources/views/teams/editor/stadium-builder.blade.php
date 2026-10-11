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
<div class="flex flex-wrap gap-3 items-end">
    <label class="flex-1">Copy stadium from another team<select data-stadium-copy-team class="block w-full border rounded p-2"><option value="">Choose a team in this save</option>@foreach($stadiumCopies ?? [] as $source)<option value="{{ $source['id'] }}">{{ $source['label'] }}</option>@endforeach</select></label>
    <button type="button" data-stadium-copy class="border rounded px-4 py-2">Load design</button>
    <button type="button" data-stadium-preview class="border border-blue-500 rounded px-4 py-2">Preview stadium in 3D</button>
</div>
<p data-stadium-copy-status role="status" class="text-sm">Copy loads structure and stadium colors into this form. Your stadium name and team artwork stay yours. Save the team when ready.</p>
<script type="application/json" data-stadium-copy-data>@json($stadiumCopies ?? [])</script>
