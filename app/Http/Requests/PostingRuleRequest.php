<?php

namespace App\Http\Requests;

use App\Models\PostingRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostingRuleRequest extends FormRequest
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
        $time = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

        return [
            'enabled' => ['boolean'],
            'timezone' => ['required', 'timezone:all'],
            'daily_limit' => ['required', 'integer', 'min:0', 'max:50'],
            'weekend_daily_limit' => ['nullable', 'integer', 'min:0', 'max:50'],
            'window_start' => ['required', $time],
            'window_end' => ['required', $time],
            'min_interval_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'slot_times' => ['nullable', 'string', 'max:500'],
            'weekend_slot_times' => ['nullable', 'string', 'max:500'],
            'active_weekdays' => ['nullable', 'array'],
            'active_weekdays.*' => ['integer', 'between:1,7'],
            'max_posts_per_period' => ['nullable', 'integer', 'min:1', 'max:100', 'required_with:period_hours'],
            'period_hours' => ['nullable', 'integer', 'min:1', 'max:168', 'required_with:max_posts_per_period'],
            'auto_retry' => ['boolean'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:20'],
            'allow_reuse' => ['boolean'],
            'selection_order' => ['required', Rule::in([PostingRule::ORDER_OLDEST, PostingRule::ORDER_NEWEST, PostingRule::ORDER_RANDOM])],
            'folder_filter' => ['nullable', 'string', 'max:2000'],
            'privacy_status' => ['nullable', Rule::in(['public', 'unlisted', 'private'])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        $data = $this->validated();
        $list = fn (?string $v) => collect(preg_split('/[\s,]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t))->unique()->sort()->values()->all();

        return [
            'enabled' => $this->boolean('enabled'),
            'timezone' => $data['timezone'],
            'daily_limit' => $data['daily_limit'],
            'weekend_daily_limit' => $data['weekend_daily_limit'] ?? null,
            'window_start' => $data['window_start'],
            'window_end' => $data['window_end'],
            'min_interval_minutes' => $data['min_interval_minutes'],
            'slot_times' => $list($data['slot_times'] ?? null),
            'weekend_slot_times' => $list($data['weekend_slot_times'] ?? null) ?: null,
            'active_weekdays' => array_map('intval', $data['active_weekdays'] ?? []),
            'max_posts_per_period' => $data['max_posts_per_period'] ?? null,
            'period_hours' => $data['period_hours'] ?? null,
            'auto_retry' => $this->boolean('auto_retry'),
            'max_attempts' => $data['max_attempts'],
            'allow_reuse' => $this->boolean('allow_reuse'),
            'selection_order' => $data['selection_order'],
            'folder_filter' => collect(preg_split('/\r?\n/', (string) ($data['folder_filter'] ?? '')))->map(fn ($f) => trim($f, " /\t"))->filter()->values()->all() ?: null,
            'privacy_status' => $data['privacy_status'] ?? null,
        ];
    }
}
