<section role="tabpanel" id="panel-cheerleaders" aria-labelledby="tab-cheerleaders" data-team-panel="cheerleaders" hidden class="space-y-4">
    <h2 class="text-xl font-semibold">Cheerleader colors</h2>
    <p>Save the team's outfit colors here, ready for cheerleaders to be added to the stadium.</p>
    <div class="grid grid-cols-3 gap-4">
        @foreach(['primary' => ['Primary', $team->team_color1 ?: '#174880'], 'secondary' => ['Secondary', $team->team_color2 ?: '#ffffff'], 'accent' => ['Accent / pom-poms', '#ffffff']] as $part => [$label, $fallback])
            <label>{{ $label }}<input type="color" name="team_settings[cheerleaders][{{ $part }}]" value="{{ old('team_settings.cheerleaders.'.$part, $team->team_settings['cheerleaders'][$part] ?? $fallback) }}" class="block w-full h-10 border rounded"></label>
        @endforeach
    </div>
</section>
<section role="tabpanel" id="panel-mascot" aria-labelledby="tab-mascot" data-team-panel="mascot" hidden class="space-y-4">
    <h2 class="text-xl font-semibold">Mascot</h2>
    <p>Choose a mascot character, give it a name, and set its body and outfit colors. Your mascot walks and waves behind the home bench and cheers for home touchdowns, takeaways, and big plays.</p>
    <label class="block">Mascot name<input name="team_settings[mascot][name]" maxlength="80" value="{{ old('team_settings.mascot.name', $team->team_settings['mascot']['name'] ?? '') }}" class="block w-full border rounded p-2"></label>
    <label class="block">Character<select name="team_settings[mascot][character]" class="block w-full border rounded p-2">
        @foreach(config('team-settings.mascot_characters') as $choice => $label)<option value="{{ $choice }}" @selected(old('team_settings.mascot.character', $team->team_settings['mascot']['character'] ?? 'bear') === $choice)>{{ $label }}</option>@endforeach
    </select></label>
    <button type="button" data-mascot-preview class="border border-blue-500 rounded px-4 py-2">Preview mascot in 3D</button>
    <p class="text-sm">The preview uses your current character, colors, and team logo, including unsaved changes.</p>
    <div class="grid grid-cols-2 gap-4">
        @foreach(['fur' => ['Body / skin / fur', '#b9825b'], 'shirt' => ['Team outfit', $team->team_color1 ?: '#174880']] as $part => [$label, $fallback])
            <label>{{ $label }}<input type="color" name="team_settings[mascot][{{ $part }}]" value="{{ old('team_settings.mascot.'.$part, $team->team_settings['mascot'][$part] ?? $fallback) }}" class="block w-full h-10 border rounded"></label>
        @endforeach
    </div>
</section>
