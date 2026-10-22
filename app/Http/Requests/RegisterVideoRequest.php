<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterVideoRequest extends FormRequest
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
            'filename' => ['required', 'string', 'max:255', 'not_regex:/[\/\\\\\x00]/'],
            'folder_label' => ['required', 'string', 'max:120'],
            'relative_path' => ['required', 'string', 'max:1000'],
            'size' => ['required', 'integer', 'min:1'],
            'checksum_sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
        ];
    }
}
