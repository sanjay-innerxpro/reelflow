<?php

namespace App\Http\Requests;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $youtube = $this->route('platform') === Platform::YouTube->value;
        $instagram = $this->route('platform') === Platform::Instagram->value;

        return [
            'title' => [$youtube ? 'required' : 'nullable', 'string', 'max:100', 'not_regex:/[<>]/'],
            'caption' => [$youtube ? 'nullable' : 'required', 'string', 'max:'.($instagram ? 2200 : 10000)],
            'description' => ['nullable', 'string', 'max:4800', 'not_regex:/[<>]/'],
            'hashtags' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'string', 'max:500'],
            'approve' => ['nullable', 'boolean'],
        ];
    }
}
