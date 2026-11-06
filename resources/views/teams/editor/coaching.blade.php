<section role="tabpanel" id="panel-coaching" aria-labelledby="tab-coaching" data-team-panel="coaching" hidden class="space-y-6" data-coaching-editor data-coaching-presets="{{ json_encode(config('team-settings.coaching_presets')) }}" data-coaching-saved="{{ json_encode(collect(config('team-settings.coaching'))->mapWithKeys(fn ($labels, $key) => [$key => max(0, min(100, (int) ($team->team_settings['coaching'][$key] ?? 50)))])->all()) }}">
    <h2 class="text-xl font-semibold">Coaching</h2>
    <p>These tendencies influence CPU and Ask Coach decisions. Down, distance, personnel, score and time still matter. Settings apply to newly started games.</p>
    <div data-coaching-tools hidden class="border rounded p-4 space-y-3">
        <label class="block font-semibold" for="coaching-preset">Coaching preset</label>
        <div class="flex flex-wrap items-center gap-3">
            <select id="coaching-preset" data-coaching-preset aria-describedby="coaching-preset-description" class="border rounded p-2">
                <option value="">Custom</option>
                @foreach(config('team-settings.coaching_presets') as $key => $preset)
                    <option value="{{ $key }}">{{ $preset['label'] }}</option>
                @endforeach
            </select>
            <button type="button" data-coaching-apply class="border rounded px-4 py-2">Apply preset</button>
            <button type="button" data-coaching-restore class="border rounded px-4 py-2">Restore saved tendencies</button>
        </div>
        <p id="coaching-preset-description" data-coaching-description class="text-sm text-gray-600"></p>
        <p data-coaching-status role="status" aria-live="polite" class="text-sm"></p>
        <p class="text-sm text-gray-600">Applying a preset replaces all nine tendencies. Adjust any slider afterward to customize it. Choose Balanced to reset all tendencies to 50, then save the team.</p>
    </div>
    <div class="grid md:grid-cols-2 gap-6">
        @foreach(config('team-settings.coaching') as $key => [$label, $low, $high])
            @php($value = old('team_settings.coaching.'.$key, $team->team_settings['coaching'][$key] ?? 50))
            <label class="block">{{ $label }} <output data-range-value>{{ $value }}</output>
                <input aria-label="{{ $label }}" id="coaching-{{ $key }}" data-coaching-key="{{ $key }}" aria-describedby="coaching-help-{{ $key }}" type="range" name="team_settings[coaching][{{ $key }}]" min="0" max="100" value="{{ $value }}" class="block w-full mt-2">
                <span class="flex justify-between text-sm text-gray-600"><span>{{ $low }}</span><span>{{ $high }}</span></span>
                <span id="coaching-help-{{ $key }}" class="block mt-2 text-sm text-gray-600">{{ config('team-settings.coaching_help.'.$key) }}</span>
            </label>
        @endforeach
    </div>
    <p class="text-sm text-gray-600">RB workload at 50 targets 65/30/5 across the top three available backs in short stretches. Specialist and manual choices take priority; fewer backs redistribute the remaining snaps.</p>
    <p class="text-sm text-gray-600">50 gives balanced coaching tendencies. Values are tendencies, not guaranteed play percentages.</p>
    @foreach(config('team-settings.staff') as $role => $label)
        <fieldset class="border rounded p-4 space-y-4">
            <legend class="font-semibold px-2">{{ $label }}</legend>
            <label class="block">Name<input name="team_settings[staff][{{ $role }}][name]" maxlength="80" value="{{ old('team_settings.staff.'.$role.'.name', $team->team_settings['staff'][$role]['name'] ?? '') }}" class="block w-full border rounded p-2"></label>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                @foreach(['skin' => ['Skin tone', '#b9825b'], 'hair_color' => ['Hair color', '#30251f'], 'shirt' => ['Team shirt', $team->team_color1 ?: '#174880'], 'pants' => ['Pants', '#303b48'], 'hat_color' => ['Cap color', $team->team_color1 ?: '#174880']] as $part => [$title, $fallback])
                    <label>{{ $title }}<input type="color" name="team_settings[staff][{{ $role }}][{{ $part }}]" value="{{ old('team_settings.staff.'.$role.'.'.$part, $team->team_settings['staff'][$role][$part] ?? $fallback) }}" class="block w-full h-10 border rounded"></label>
                @endforeach
                <label>Hair<select name="team_settings[staff][{{ $role }}][hair]" class="block w-full border rounded p-2">
                    @foreach(['bald', 'buzz', 'short', 'curly', 'long'] as $choice)<option value="{{ $choice }}" @selected(old('team_settings.staff.'.$role.'.hair', $team->team_settings['staff'][$role]['hair'] ?? 'short') === $choice)>{{ ucfirst($choice) }}</option>@endforeach
                </select></label>
                <label>Build<select name="team_settings[staff][{{ $role }}][build]" class="block w-full border rounded p-2">
                    @foreach(['slim', 'average', 'stocky'] as $choice)<option value="{{ $choice }}" @selected(old('team_settings.staff.'.$role.'.build', $team->team_settings['staff'][$role]['build'] ?? 'average') === $choice)>{{ ucfirst($choice) }}</option>@endforeach
                </select></label>
            </div>
            <input type="hidden" name="team_settings[staff][{{ $role }}][hat]" value="0">
            <label class="block"><input type="checkbox" name="team_settings[staff][{{ $role }}][hat]" value="1" @checked(old('team_settings.staff.'.$role.'.hat', $team->team_settings['staff'][$role]['hat'] ?? false))> Wear a team cap</label>
            <p class="text-sm text-gray-600">The team logo appears on the shirt and cap when a team logo is available.</p>
            <button type="button" data-coach-preview="{{ $role }}" class="border rounded px-4 py-2">Preview {{ strtolower($label) }}</button>
        </fieldset>
    @endforeach
</section>
