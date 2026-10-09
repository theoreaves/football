<section data-player-appearance class="player-appearance" data-profile="{{ json_encode(['number' => $pivot['jersey_number'] ?? null, 'lastname' => $player->lastname ?? '']) }}" data-kit="{{ json_encode(app(\App\Http\Controllers\PracticeController::class)->appearance($team, 'home')['uniform'] ?? []) }}">
    @php
        $profileName = trim((old('firstname', $player->firstname ?? '')).' '.(old('lastname', $player->lastname ?? '')));
        $profileHeight = old('height_inches', $player->height_inches ?? null);
        $profileWeight = old('weight_pounds', $player->weight_pounds ?? null);
    @endphp
    <div class="flex flex-wrap items-start gap-6">
        <div class="player-portrait-block shrink-0">
            <div data-player-portrait class="player-portrait" aria-label="Player portrait without helmet"></div>
            <button type="button" data-appearance-open class="appearance-button">Edit appearance</button>
        </div>
        <div class="flex-1 min-w-[220px]">
            <h2 class="text-2xl font-semibold">{{ $profileName !== '' ? $profileName : 'New Player' }}</h2>
            <p class="mt-1 text-gray-600">{{ old('position', $player->position ?? 'Position not set') }}</p>
            <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Jersey</dt><dd class="font-medium">#{{ old('jersey_number', $pivot['jersey_number'] ?? '—') }}</dd></div>
                <div><dt class="text-gray-500">Age</dt><dd class="font-medium">{{ old('age', $player->age ?? '—') }}</dd></div>
                <div><dt class="text-gray-500">Height</dt><dd class="font-medium">{{ is_numeric($profileHeight) ? floor($profileHeight / 12)."′".($profileHeight % 12)."″" : '—' }}</dd></div>
                <div><dt class="text-gray-500">Weight</dt><dd class="font-medium">{{ is_numeric($profileWeight) ? $profileWeight.' lbs' : '—' }}</dd></div>
            </dl>
        </div>
    </div>
    <div data-appearance-panel hidden class="appearance-panel">
        <div><h2>Player appearance</h2><p>Rotate and zoom the model. Use appearance keeps your changes in this form; Save player commits them.</p><div data-player-body class="player-body-preview" aria-label="Full player model without helmet"></div></div>
        <div class="appearance-options">
            <div class="appearance-fields">
            <label>Skin tone<input type="color" name="skin_tone" value="{{ old('skin_tone', $player->skin_tone ?? '#c78e61') }}"></label>
            <label>Eye color<input type="color" name="appearance[eye_color]" value="{{ old('appearance.eye_color', $player->appearance['eye_color'] ?? '#60452f') }}"></label>
            <label>Hair / brow / beard color<input type="color" name="appearance[hair_color]" value="{{ old('appearance.hair_color', $player->appearance['hair_color'] ?? '#25201e') }}"></label>
            <label>Height (inches)<input type="number" name="height_inches" min="48" max="96" value="{{ old('height_inches', $player->height_inches ?? 72) }}"></label>
            <label>Weight (pounds)<input type="number" name="weight_pounds" min="90" max="450" value="{{ old('weight_pounds', $player->weight_pounds ?? 215) }}"></label>
            </div>
            @foreach(['head_shape' => ['round','oval','square','wide','long'], 'hair' => ['bald','buzz','short','curly','long'], 'brow' => ['straight','angled','thick','arched'], 'nose' => ['standard','small','wide','long'], 'mouth' => ['neutral','wide','thin','smile'], 'beard' => ['none','stubble','moustache','goatee','full']] as $feature => $choices)
                <fieldset><legend>{{ ucfirst($feature === 'brow' ? 'Eyebrows' : str_replace('_', ' ', $feature)) }}</legend><div class="appearance-choices">@foreach($choices as $choice)<label class="appearance-choice"><input type="radio" name="appearance[{{ $feature }}]" value="{{ $choice }}" @checked(old('appearance.'.$feature, $player->appearance[$feature] ?? $choices[0]) === $choice)><span data-face-choice data-feature="{{ $feature }}" data-choice="{{ $choice }}"></span><span>{{ ucfirst($choice) }}</span></label>@endforeach</div></fieldset>
            @endforeach
            <div class="appearance-actions"><button type="button" data-appearance-apply class="appearance-button">Use appearance</button><button type="button" data-appearance-cancel class="appearance-button secondary">Cancel</button></div>
        </div>
    </div>
</section>
