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
            'start' => ['contract_version' => ['sometimes', Rule::in(['live-recording-1', 'live-recording-2'])], 'call' => ['required_if:contract_version,live-recording-2', Rule::in(\App\Services\Arcade\FullTeamSetup::CALLS)], 'version' => ['required', 'integer', 'min:0'], 'kind' => ['required', Rule::in(['run', 'pass'])], 'tempo' => ['required', Rule::in(['normal', 'hurry', 'drain'])]],
            'submit' => ['envelope' => ['required', 'array'], 'recording' => ['required', 'array']],
            default => [],
        };
    }

    public function messages(): array
    {
        return ['version.required' => 'Reload this exhibition before starting a play.', 'recording.required' => 'The actual live recording is required.'];
    }
}
