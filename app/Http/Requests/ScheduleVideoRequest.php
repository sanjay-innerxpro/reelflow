<?php

namespace App\Http\Requests;

use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'account_ids.*' => ['integer', Rule::exists('social_accounts', 'id')->where('workspace_id', Tenant::id())],
            'scheduled_at' => ['nullable', 'date'],
            'allow_reuse' => ['boolean'],
        ];
    }
}
