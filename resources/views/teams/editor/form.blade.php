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

        <div class="flex items-center justify-between mb-6">
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
        >
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach(['city' => 'City', 'name' => 'Team name', 'abbr' => 'Abbreviation', 'conference' => 'Conference', 'division' => 'Division'] as $field => $label)
                <label>{{ $label }}<input name="{{ $field }}" value="{{ old($field, $team->{$field}) }}" class="block w-full border rounded p-2" @required(in_array($field, ['city', 'name']))></label>
                @endforeach
                @foreach(['team_color1' => 'Primary team color', 'team_color2' => 'Secondary team color'] as $field => $label)
                <label>{{ $label }}<input type="color" name="{{ $field }}" value="{{ old($field, $team->{$field} ?? '#174880') }}" class="block w-full h-10 border rounded"></label>
                @endforeach
            </div>
            <section class="border rounded p-4 space-y-4">
                <h2 class="text-xl font-semibold">3D uniforms and home field</h2>
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
                <input type="hidden" name="endzone_transparent" value="0">
                <label class="block"><input type="checkbox" name="endzone_transparent" value="1" @checked(old('endzone_transparent', $team->endzone_transparent))> Grass end zones (transparent background)</label>
                <p class="text-sm">End zone art: transparent PNG, recommended 1600 × 300 pixels. Any aspect ratio fits without stretching. An uploaded logo replaces lettering on that end. Helmet uploads are independent: leave either side empty for no logo; images are not automatically mirrored.</p>
                <label class="block">End zone text<input name="endzone_text" maxlength="40" value="{{ old('endzone_text', $team->endzone_text ?? $team->name) }}" class="block w-full border rounded p-2"></label>
                <div class="grid grid-cols-2 gap-4">
                    <label>End zone background<input type="color" name="endzone_background" value="{{ old('endzone_background', $team->endzone_background ?? $team->team_color1 ?? '#174880') }}" class="block w-full h-10 border rounded"></label>
                    <label>End zone lettering<input type="color" name="endzone_text_color" value="{{ old('endzone_text_color', $team->endzone_text_color ?? '#ffffff') }}" class="block w-full h-10 border rounded"></label>
                </div>
                <p class="text-sm text-gray-600">Preview these settings on the practice field. Both end zones use the home team's design.</p>
            </section>
            @php
                $uploadFields = [
                    'team_logo' => 'Team Logo',
                    'midfield_logo' => 'Midfield Logo',
                    'endzone_logo_left' => 'End zone logo (left)',
                    'endzone_logo_right' => 'End zone logo (right)',
                    'helmet_logo_right' => 'Helmet Logo (Right)',
                    'helmet_logo_left' => 'Helmet Logo (Left)',
                ];
            @endphp

            <div class="border rounded p-4">
                <div class="font-semibold mb-3">Logos / Field Art</div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($uploadFields as $field => $label)
                            <label class="block font-medium mb-2">{{ $label }}</label>
                            @if($team->{$field})<label class="block text-sm"><input type="checkbox" name="clear_{{ $field }}" value="1"> Remove this image</label>@endif

                            @if($mode === 'edit' && $team->{$field})
                                <div class="mb-2">
                                    <img
                                        src="{{ in_array($field, ['team_logo', 'helmet_logo_left', 'helmet_logo_right', 'midfield_logo', 'endzone_logo_left', 'endzone_logo_right']) ? route('teams.art', [$team, $field]) : Storage::disk('public')->url($team->{$field}) }}"
                                        alt="{{ $label }}"
                                        class="max-h-28 border rounded"
                                    />
                                    <div class="text-xs text-gray-600 mt-1">
                                        {{ $team->{$field} }}
                                    </div>
                                </div>
                            @endif

                            <input type="file" name="{{ $field }}" accept="image/*" class="w-full" />
                            <div class="text-xs text-gray-600 mt-1">
                                Uploading a new file replaces the existing one.
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

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
