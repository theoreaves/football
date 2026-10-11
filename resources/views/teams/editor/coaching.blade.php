<section role="tabpanel" id="panel-coaching" aria-labelledby="tab-coaching" data-team-panel="coaching" hidden class="space-y-6">
    <h2 class="text-xl font-semibold">Coaching</h2>
    <p>These tendencies influence CPU and Ask Coach decisions. Down, distance, personnel, score and time still matter. Settings apply to newly started games.</p>
    <div class="grid md:grid-cols-2 gap-6">
        @foreach(config('team-settings.coaching') as $key => [$label, $low, $high])
            @php($value = old('team_settings.coaching.'.$key, $team->team_settings['coaching'][$key] ?? 50))
            <label class="block">{{ $label }} <output data-range-value>{{ $value }}</output>
                <input type="range" name="team_settings[coaching][{{ $key }}]" min="0" max="100" value="{{ $value }}" class="block w-full mt-2">
                <span class="flex justify-between text-sm text-gray-600"><span>{{ $low }}</span><span>{{ $high }}</span></span>
            </label>
        @endforeach
    </div>
    <p class="text-sm text-gray-600">50 keeps the existing balanced coaching behavior. Values are tendencies, not guaranteed play percentages.</p>
    @foreach(config('team-settings.staff') as $role => $label)
        <fieldset class="border rounded p-4 space-y-4">
            <legend class="font-semibold px-2">{{ $label }}</legend>
            <label class="block">Name<input name="team_settings[staff][{{ $role }}][name]" maxlength="80" value="{{ old('team_settings.staff.'.$role.'.name', $team->team_settings['staff'][$role]['name'] ?? '') }}" class="block w-full border rounded p-2"></label>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                @foreach(['skin' => ['Skin tone', '#b9825b'], 'hair_color' => ['Hair color', '#30251f'], 'shirt' => ['Team shirt', $team->team_color1 ?: '#174880'], 'pants' => ['Pants', '#303b48']] as $part => [$title, $fallback])
                    <label>{{ $title }}<input type="color" name="team_settings[staff][{{ $role }}][{{ $part }}]" value="{{ old('team_settings.staff.'.$role.'.'.$part, $team->team_settings['staff'][$role][$part] ?? $fallback) }}" class="block w-full h-10 border rounded"></label>
                @endforeach
                <label>Hair<select name="team_settings[staff][{{ $role }}][hair]" class="block w-full border rounded p-2">
                    @foreach(['bald', 'buzz', 'short', 'curly', 'long'] as $choice)<option value="{{ $choice }}" @selected(old('team_settings.staff.'.$role.'.hair', $team->team_settings['staff'][$role]['hair'] ?? 'short') === $choice)>{{ ucfirst($choice) }}</option>@endforeach
                </select></label>
                <label>Build<select name="team_settings[staff][{{ $role }}][build]" class="block w-full border rounded p-2">
                    @foreach(['slim', 'average', 'stocky'] as $choice)<option value="{{ $choice }}" @selected(old('team_settings.staff.'.$role.'.build', $team->team_settings['staff'][$role]['build'] ?? 'average') === $choice)>{{ ucfirst($choice) }}</option>@endforeach
                </select></label>
            </div>
            <button type="button" data-coach-preview="{{ $role }}" class="border rounded px-4 py-2">Preview {{ strtolower($label) }}</button>
        </fieldset>
    @endforeach
</section>
