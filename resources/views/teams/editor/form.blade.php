    <x-layouts.app>
<div class="max-w-5xl mx-auto p-6 bg-white text-gray-900">
        @if(session('status'))
            <div class="mb-4 p-3 border rounded bg-green-50">
                {{ session('status') }}
            </div>
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

        <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <h1 class="text-2xl font-semibold">
                {{ $mode === 'create' ? 'Add Team' : 'Edit Team' }}
            </h1>
            <a href="{{ route('teams.editor.index') }}" class="underline text-gray-700">Back to Teams</a>

            @if($mode === 'edit')
                <a href="{{ route('simulation-ratings.edit', $team) }}" class="text-blue-700 underline">Engine ratings</a>
                <a
                    href="{{ route('teams.editor.teams.players.index', $team) }}"
                    class="px-4 py-2 rounded border text-sm"
                >
                    Manage Players
                </a>
            @endif
        </div>


            <form
            method="POST"
            enctype="multipart/form-data"
            action="{{ $mode === 'create' ? route('teams.editor.store') : route('teams.editor.update', $team) }}"
            class="space-y-6"
            data-team-editor
            data-error-fields="{{ json_encode($errors->keys()) }}"
        >
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            <div role="tablist" aria-label="Team settings" class="flex flex-wrap gap-2 border-b pb-3">
                @foreach(['team' => 'Team', 'uniforms' => 'Uniforms', 'stadium' => 'Stadium', 'championship' => 'Championship', 'audio' => 'Audio', 'cheerleaders' => 'Cheerleaders', 'mascot' => 'Mascot', 'coaching' => 'Coaching', 'specialists' => 'Specialists'] as $key => $label)
                    <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}" aria-selected="{{ $key === 'team' ? 'true' : 'false' }}" data-team-tab="{{ $key }}" class="px-4 py-2 rounded border">{{ $label }}</button>
                @endforeach
            </div>
            <section role="tabpanel" id="panel-team" aria-labelledby="tab-team" data-team-panel="team" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach(['city' => 'City', 'name' => 'Team name', 'abbr' => 'Abbreviation', 'conference' => 'Conference', 'division' => 'Division'] as $field => $label)
                <label>{{ $label }}<input name="{{ $field }}" value="{{ old($field, $team->{$field}) }}" class="block w-full border rounded p-2" @required(in_array($field, ['city', 'name']))></label>
                @endforeach
                @foreach(['team_color1' => 'Primary team color', 'team_color2' => 'Secondary team color'] as $field => $label)
                <label>{{ $label }}<input type="color" name="{{ $field }}" value="{{ old($field, $team->{$field} ?? '#174880') }}" class="block w-full h-10 border rounded"></label>
                @endforeach
            </div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
@foreach(['team_logo' => 'Team logo'] as $field => $label)
@include('teams.editor.art')
@endforeach
</div>
            </section>
            <section role="tabpanel" id="panel-audio" aria-labelledby="tab-audio" data-team-panel="audio" hidden class="space-y-4">
                @include('teams.editor.audio')
            </section>
            <section role="tabpanel" id="panel-uniforms" aria-labelledby="tab-uniforms" data-team-panel="uniforms" hidden class="space-y-4">
                <h2 class="text-xl font-semibold">Uniforms</h2>
                <input type="hidden" name="team_settings[white_at_home]" value="0">
                <label class="block"><input type="checkbox" name="team_settings[white_at_home]" value="1" @checked(old('team_settings.white_at_home', $team->team_settings['white_at_home'] ?? false))> Wear white (away) uniform at home</label>
                <p class="text-sm text-gray-600">When enabled, the home team wears its away uniform and the visitor wears its home uniform.</p>
                <div class="grid md:grid-cols-2 gap-4">
                @foreach(['home' => 'Home', 'away' => 'Away'] as $venue => $label)
                    <label>{{ $label }} helmet design<select name="team_settings[helmets][{{ $venue }}][design]" class="block w-full border rounded p-2">
                        @foreach(['standard' => 'Standard / center stripe', 'cat_stripes' => 'Cat stripes', 'ram_horns' => 'Ram horns'] as $design => $title)
                        <option value="{{ $design }}" @selected(old('team_settings.helmets.'.$venue.'.design', $team->team_settings['helmets'][$venue]['design'] ?? 'standard') === $design)>{{ $title }}</option>
                        @endforeach
                    </select></label>
                @endforeach
                </div>
                <p class="text-sm text-gray-600">Cat stripes and ram horns use the helmet stripe color below and replace the center stripe. Uploaded side logos remain independent.</p>
                <div class="flex flex-wrap gap-4">
                @foreach(['left', 'right'] as $side)
                    <label><input type="hidden" name="team_settings[helmets][flip_{{ $side }}]" value="0"><input type="checkbox" name="team_settings[helmets][flip_{{ $side }}]" value="1" @checked(old('team_settings.helmets.flip_'.$side, $team->team_settings['helmets']['flip_'.$side] ?? false))> Mirror {{ $side }} helmet decal</label>
                @endforeach
                    <label>Helmet decal color (blank keeps artwork)<input name="team_settings[helmets][logo_color]" placeholder="#ffffff" value="{{ old('team_settings.helmets.logo_color', $team->team_settings['helmets']['logo_color'] ?? '') }}" class="block border rounded p-2" maxlength="7"></label>
                </div>
                <div class="flex gap-3">
                    <button type="button" data-uniform-preview="home" class="border rounded px-4 py-2">Preview home uniform</button>
                    <button type="button" data-uniform-preview="away" class="border rounded px-4 py-2">Preview away uniform</button>
                </div>
                @foreach(['home' => 'Home uniform', 'away' => 'Away uniform'] as $venue => $label)
                    <h3 class="font-semibold">{{ $label }}</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        @foreach(['helmet', 'facemask', 'shirt', 'pants', 'socks', 'number', 'number_outline'] as $part)
                            @php
                                $field = "uniform_{$venue}_{$part}";
                                $default = $part === 'shirt' ? ($venue === 'home' ? ($team->team_color1 ?? '#3997ff') : '#ffffff') : '#e2e8f0';
                                if ($part === 'number' || $part === 'number_outline') {
                                    $default = $part === 'number' ? '#ffffff' : '#111111';
                                }
                                if ($part === 'facemask') { $default = '#17202b'; }
                            @endphp
                            <label>{{ $part === 'number' ? 'Number color' : ($part === 'number_outline' ? 'Number outline' : ($part === 'facemask' ? 'Face mask' : ucfirst($part))) }}<input type="color" name="{{ $field }}" value="{{ old($field, $team->{$field} ?? $default) }}" class="block w-full h-10 border rounded mt-1"></label>
                        @endforeach
                    </div>
                    @php
                        $nameEnabled = "uniform_{$venue}_name_enabled";
                        $nameColor = "uniform_{$venue}_name_color";
                    @endphp
                    <div class="flex flex-wrap gap-4 items-center">
                        <label><input type="hidden" name="{{ $nameEnabled }}" value="0"><input type="checkbox" name="{{ $nameEnabled }}" value="1" @checked(old($nameEnabled, $team->{$nameEnabled}))> Show last names on jersey backs</label>
                        <label>Jersey name color<input type="color" name="{{ $nameColor }}" value="{{ old($nameColor, $team->{$nameColor} ?: ($team->{"uniform_{$venue}_number"} ?: '#ffffff')) }}" class="block w-24 h-10 border rounded"></label>
                    </div>
                    <div class="grid grid-cols-3 gap-4 mt-3">
                    @foreach(['helmet', 'shoulder', 'pants'] as $part)
                        @php
                            $stripe = "uniform_{$venue}_{$part}_stripe";
                        @endphp
                        <label><input type="hidden" name="{{ $stripe }}_enabled" value="0"><input type="checkbox" name="{{ $stripe }}_enabled" value="1" @checked(old($stripe.'_enabled', $team->{$stripe.'_enabled'}))> {{ ucfirst($part) }} stripe
                        <input type="color" name="{{ $stripe }}" value="{{ old($stripe, $team->{$stripe} ?? '#ffffff') }}" class="block w-full h-10 border rounded"></label>
                    @endforeach
                    </div>
                @endforeach
                <h3 class="font-semibold">Helmet logos</h3>
                <p class="text-sm">Left and right logos apply to both uniforms. Leave either side empty for no logo.</p>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
@foreach(['helmet_logo_left' => 'Helmet logo (left)', 'helmet_logo_right' => 'Helmet logo (right)'] as $field => $label)
@include('teams.editor.art')
@endforeach
</div>
            </section>
            <section role="tabpanel" id="panel-stadium" aria-labelledby="tab-stadium" data-team-panel="stadium" hidden class="space-y-4">
                <h2 class="text-xl font-semibold">Stadium and field</h2>
                <p class="text-sm text-gray-600">Build your home stadium, preview it, or start from another team’s design. Leave structure overrides blank to use the selected style’s defaults.</p>
                @include('teams.editor.stadium-builder')
                <label class="block">Stadium style
                    <select name="stadium_style" class="block w-full border rounded p-2 mt-1">
                        @foreach(config('stadiums') as $value => $label)
                            <option value="{{ $value }}" @selected(old('stadium_style', $team->stadium_style ?? 'classic_oval') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid grid-cols-3 gap-4">
                    @foreach(['seat' => 'Seats', 'wall' => 'Walls / structure', 'roof' => 'Roof / canopy'] as $part => $label)
                        @php
                            $field = 'stadium_'.$part.'_color';
                        @endphp
                        <label>{{ $label }}<input type="color" name="{{ $field }}" value="{{ old($field, $team->{$field} ?: ($part === 'seat' ? ($team->team_color1 ?: '#315b85') : ($part === 'wall' ? '#657587' : '#cbd5e1'))) }}" class="block w-full h-10 border rounded"></label>
                    @endforeach
                </div>
                <input type="hidden" name="endzone_transparent" value="0">
                <label class="block"><input type="checkbox" name="endzone_transparent" value="1" @checked(old('endzone_transparent', $team->endzone_transparent))> Grass end zones (transparent background)</label>
                <p class="text-sm">End zone art: transparent PNG, recommended 1600 × 300 pixels. Any aspect ratio fits without stretching. An uploaded logo replaces lettering on that end. Helmet uploads are independent: leave either side empty for no logo; images are not automatically mirrored.</p>
                <label class="block">End zone text<input name="endzone_text" maxlength="40" value="{{ old('endzone_text', $team->endzone_text ?? $team->name) }}" class="block w-full border rounded p-2"></label>
                <div class="grid grid-cols-2 gap-4">
                    <label>End zone background<input type="color" name="endzone_background" value="{{ old('endzone_background', $team->endzone_background ?? $team->team_color1 ?? '#174880') }}" class="block w-full h-10 border rounded"></label>
                    <label>End zone lettering<input type="color" name="endzone_text_color" value="{{ old('endzone_text_color', $team->endzone_text_color ?? '#ffffff') }}" class="block w-full h-10 border rounded"></label>
                </div>
                <p class="text-sm text-gray-600">Preview these settings on the practice field. Both end zones use the home team's design.</p>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
@foreach(['midfield_logo' => 'Midfield logo', 'endzone_logo_left' => 'End zone logo (left)', 'endzone_logo_right' => 'End zone logo (right)'] as $field => $label)
@include('teams.editor.art')
@endforeach
</div>
            </section>
            <section role="tabpanel" id="panel-championship" aria-labelledby="tab-championship" data-team-panel="championship" hidden class="space-y-4">
                <h2 class="text-xl font-semibold">Championship End Zone</h2>
                <p class="text-sm text-gray-600">Independent from regular-season end zones. Leave everything unchanged to use the normal design. Changes apply only to championship games started afterward.</p>
                @php($champ = $team->team_settings['championship_endzone'] ?? [])
                <label class="block"><input type="hidden" name="team_settings[championship_endzone][enabled]" value="0"><input type="checkbox" name="team_settings[championship_endzone][enabled]" value="1" @checked(old('team_settings.championship_endzone.enabled', $champ['enabled'] ?? false))> Use custom championship end zone</label>
                <label class="block">End zone text<input name="team_settings[championship_endzone][text]" maxlength="40" value="{{ old('team_settings.championship_endzone.text', $champ['text'] ?? $team->endzone_text ?? $team->name) }}" class="block w-full border rounded p-2"></label>
                <div class="grid grid-cols-2 gap-4">
                    <label>Background color<input type="color" name="team_settings[championship_endzone][background]" value="{{ old('team_settings.championship_endzone.background', $champ['background'] ?? $team->endzone_background ?? '#174880') }}" class="block w-full h-10 border rounded"></label>
                    <label>Lettering color<input type="color" name="team_settings[championship_endzone][text_color]" value="{{ old('team_settings.championship_endzone.text_color', $champ['text_color'] ?? $team->endzone_text_color ?? '#ffffff') }}" class="block w-full h-10 border rounded"></label>
                </div>
                <label class="block"><input type="hidden" name="team_settings[championship_endzone][transparent]" value="0"><input type="checkbox" name="team_settings[championship_endzone][transparent]" value="1" @checked(old('team_settings.championship_endzone.transparent', $champ['transparent'] ?? $team->endzone_transparent))> Grass end zones (transparent background)</label>
                <p class="text-sm text-gray-600">Upload a transparent PNG for each side, as with regular end-zone artwork. Existing uploaded graphics are retained until replaced or cleared.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach(['left' => 'Left end-zone art', 'right' => 'Right end-zone art'] as $side => $label)
                        <div><label class="block">{{ $label }}<input type="file" name="championship_endzone_{{ $side }}" accept="image/png,image/jpeg,image/webp" class="block w-full border rounded p-2"></label>
                        @if(!empty($champ['art_'.$side]))<a class="text-blue-700 underline" href="{{ route('teams.championship-art', [$team, $side]) }}" target="_blank">View current artwork</a> <label><input type="checkbox" name="championship_endzone_clear[{{ $side }}]" value="1"> Clear artwork</label>@endif</div>
                    @endforeach
                </div>
            </section>
            @include('teams.editor.entertainment')
            @include('teams.editor.coaching')
            @include('teams.editor.specialists')
            <dialog data-team-preview class="rounded-xl p-4 w-full max-w-2xl backdrop:bg-black/70">
                <div class="flex justify-between items-center gap-3"><h2 data-preview-title class="font-semibold text-xl">3D preview</h2><button type="button" data-preview-close class="rounded border px-3 py-2">Close</button></div>
                <div data-team-preview-host style="height:440px" class="mt-3 rounded overflow-hidden"></div>
                <p class="mt-2 text-sm">Drag to rotate. Scroll to zoom. This preview uses your unsaved settings.</p>
            </dialog>
            <div class="flex items-center gap-3">
                <button class="px-5 py-2 rounded bg-blue-600 text-white" type="submit">
                    {{ $mode === 'create' ? 'Create Team' : 'Save Changes' }}
                </button>

                <a href="{{ route('teams.editor.index') }}" class="px-4 py-2 rounded border">
                    Cancel
                </a>

            </div>
        </form>
</div>
</x-layouts.app>
