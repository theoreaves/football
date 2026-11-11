<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ArcadeFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'command_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'],
            'flow_revision' => ['required', 'integer', 'min:0'], 'state_hash' => ['required', 'string', 'size:64'],
            'action' => ['required', Rule::in(['preferences', 'advance', 'fast', 'timeout', 'cpu_timeout', 'spike', 'intro_seen', 'play', 'penalty', 'coin', 'ot_call', 'ot_choice', 'lineup'])],
            'human_side' => ['sometimes', Rule::in(['home', 'away'])], 'defense_mode' => ['sometimes', Rule::in(['coach', 'watch', 'fast'])],
        ];
    }
}
