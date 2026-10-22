<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('device') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent_version' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'max:120'],
            'folders' => ['nullable', 'array', 'max:50'],
            'folders.*.label' => ['required', 'string', 'max:120'],
            'folders.*.files' => ['nullable', 'integer', 'min:0'],
            'pending' => ['nullable', 'integer', 'min:0'],
            'failed' => ['nullable', 'integer', 'min:0'],
            'last_error' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
