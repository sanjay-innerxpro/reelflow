<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleVideoRequest extends FormRequest
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
        return [
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', 'exists:social_accounts,id'],
            'scheduled_at' => ['nullable', 'date'],
            'allow_reuse' => ['boolean'],
        ];
    }
}
