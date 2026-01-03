    <x-layouts.app>
<div class="max-w-5xl mx-auto p-6 bg-white">
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

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block font-medium mb-1">City</label>
                    <input
                        name="city"
                        value="{{ old('city', $team->city) }}"
                        class="w-full border rounded p-2"
                        required
                    />
                </div>

                <div>
                    <label class="block font-medium mb-1">Name</label>
                    <input
                        name="name"
                        value="{{ old('name', $team->name) }}"
                        class="w-full border rounded p-2"
                        required
                    />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block font-medium mb-1">Playcalling Behind</label>
                    <input type="number" min="-10" name="playcalling_behind"
                           value="{{ old('playcalling_behind', $team->playcalling_behind ?? 0) }}"
                           class="w-full border rounded p-2" required />
                </div>
                <div>
                    <label class="block font-medium mb-1">Playcalling Tied</label>
                    <input type="number" min="-10" name="playcalling_tied"
                           value="{{ old('playcalling_tied', $team->playcalling_tied ?? 0) }}"
                           class="w-full border rounded p-2" required />
                </div>
                <div>
                    <label class="block font-medium mb-1">Playcalling Ahead</label>
                    <input type="number" min="-10" name="playcalling_ahead"
                           value="{{ old('playcalling_ahead', $team->playcalling_ahead ?? 0) }}"
                           class="w-full border rounded p-2" required />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block font-medium mb-1">OL Rush</label>
                    <input type="number" min="0" name="ol_rush"
                           value="{{ old('ol_rush', $team->ol_rush ?? 0) }}"
                           class="w-full border rounded p-2" required />
                </div>
                <div>
                    <label class="block font-medium mb-1">OL Power</label>
                    <input type="number" min="0" name="ol_power"
                           value="{{ old('ol_power', $team->ol_power ?? 0) }}"
                           class="w-full border rounded p-2" required />
                </div>
                <div>
                    <label class="block font-medium mb-1">OL Pass</label>
                    <input type="number" min="0" name="ol_pass"
                           value="{{ old('ol_pass', $team->ol_pass ?? 0) }}"
                           class="w-full border rounded p-2" required />
                </div>
                <div>
                    <label class="block font-medium mb-1">OL Protect</label>
                    <input type="number" min="0" name="ol_protect"
                           value="{{ old('ol_protect', $team->ol_protect ?? 0) }}"
                           class="w-full border rounded p-2" required />
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block font-medium mb-1">Team Color 1</label>
                    <div class="flex items-center gap-3">
                        <input type="color"
                               value="{{ old('team_color1', $team->team_color1 ?? '#000000') }}"
                               oninput="document.getElementById('team_color1').value=this.value"
                               class="h-10 w-14 border rounded" />
                        <input id="team_color1"
                               name="team_color1"
                               value="{{ old('team_color1', $team->team_color1) }}"
                               placeholder="#RRGGBB"
                               class="w-full border rounded p-2" />
                    </div>
                </div>

                <div>
                    <label class="block font-medium mb-1">Team Color 2</label>
                    <div class="flex items-center gap-3">
                        <input type="color"
                               value="{{ old('team_color2', $team->team_color2 ?? '#FFFFFF') }}"
                               oninput="document.getElementById('team_color2').value=this.value"
                               class="h-10 w-14 border rounded" />
                        <input id="team_color2"
                               name="team_color2"
                               value="{{ old('team_color2', $team->team_color2) }}"
                               placeholder="#RRGGBB"
                               class="w-full border rounded p-2" />
                    </div>
                </div>
            </div>

            @php
                $uploadFields = [
                    'team_logo' => 'Team Logo',
                    'helmet_logo_right' => 'Helmet Logo (Right)',
                    'helmet_logo_left' => 'Helmet Logo (Left)',
                    'midfield_logo' => 'Midfield Logo',
                    'endzone_logo_right' => 'Endzone Logo (Right)',
                    'endzone_logo_left' => 'Endzone Logo (Left)',
                ];
            @endphp

            <div class="border rounded p-4">
                <div class="font-semibold mb-3">Logos / Field Art</div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($uploadFields as $field => $label)
                        <div class="border rounded p-3">
                            <label class="block font-medium mb-2">{{ $label }}</label>

                            @if($mode === 'edit' && $team->{$field})
                                <div class="mb-2">
                                    <img
                                        src="{{ Storage::disk('public')->url($team->{$field}) }}"
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

{{--                @if($mode === 'edit')--}}
{{--                    <form method="POST" action="{{ route('teams.editor.destroy', $team) }}"--}}
{{--                          onsubmit="return confirm('Delete this team? This cannot be undone.');">--}}
{{--                        @csrf--}}
{{--                        @method('DELETE')--}}
{{--                        <button type="submit" class="px-4 py-2 rounded border">--}}
{{--                            Delete Team--}}
{{--                        </button>--}}
{{--                    </form>--}}
{{--                @endif--}}
            </div>
        </form>
    </div>
    </x-layouts.app>
