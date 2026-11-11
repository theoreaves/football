<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArcadeDrillRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(config('arcade.enabled'), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['nullable', 'string', 'in:running,passing'],
            'home' => ['nullable', 'integer', 'min:1'],
            'away' => ['nullable', 'integer', 'min:1', 'different:home'],
            'runner' => ['nullable', 'integer', 'min:1'],
            'quarterback' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'mode.string' => 'Choose a running or passing drill.',
            'mode.in' => 'Choose a running or passing drill.',
            'away.different' => 'Choose two different teams for the '.($this->input('mode') === 'passing' ? 'passing' : 'running').' drill.',
            '*.integer' => 'Choose a player or team from the available list.',
            '*.min' => 'Choose a player or team from the available list.',
        ];
    }
}
