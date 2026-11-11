<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArcadeExhibitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(config('arcade.enabled'), 404);
        abort_if(strlen($this->getContent()) > (int) config('arcade.max_request_bytes', 4194304), 413, 'Arcade recording is too large.');

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'start' => ['version' => ['required', 'integer', 'min:0'], 'kind' => ['required', Rule::in(['run', 'pass'])], 'tempo' => ['required', Rule::in(['normal', 'hurry', 'drain'])]],
            'submit' => ['envelope' => ['required', 'array'], 'recording' => ['required', 'array']],
            default => [],
        };
    }

    public function messages(): array
    {
        return ['version.required' => 'Reload this exhibition before starting a play.', 'recording.required' => 'The actual live recording is required.'];
    }
}
