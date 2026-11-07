<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'packages.preview' => ['package' => ['required', 'file', 'max:102400', 'extensions:zip,csv']],
            'packages.import' => ['token' => ['required', 'uuid'], 'year' => ['required', 'integer', 'between:1900,2200'],
                'world_name' => ['nullable', 'string', 'max:255'], 'team_id' => ['nullable', 'integer'],
                'mode' => ['required', 'in:add,replace']],
            'packages.share' => ['world_id' => ['nullable', 'integer', 'required_without:team_id'],
                'team_id' => ['nullable', 'integer', 'required_without:world_id', 'prohibits:world_id'],
                'title' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:2000'],
                'year' => ['nullable', 'integer', 'between:1900,2200']],
            default => [],
        };
    }

    public function messages(): array
    {
        return ['package.max' => 'Choose a ZIP smaller than 100 MB, or a roster CSV smaller than 5 MB.',
            'package.extensions' => 'Choose a team/world ZIP or a roster CSV.',
            'year.between' => 'Choose a roster year between 1900 and 2200.'];
    }
}
