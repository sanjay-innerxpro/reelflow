<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Runtime-editable settings of the current workspace, stored in
 * app_settings, with defaults. Secrets never live here; they stay in the
 * environment.
 */
class Settings
{
    private const CACHE_KEY = 'reelflow.settings';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $owner = Tenant::workspace()?->owner?->email ?? config('reelflow.admin_email');

        return [
            'notifications.recipients' => array_values(array_filter([$owner])),
            'notifications.on_success' => true,
            'notifications.on_failure' => true,
            'notifications.on_auth_problem' => true,
            'notifications.on_device_offline' => true,
            'notifications.on_upload_failures' => true,
            'notifications.daily_summary' => true,
            'notifications.daily_summary_time' => '21:30',

            'ai.auto_generate' => true,
            'ai.language' => 'auto',
            'ai.audience' => '',
            'ai.tone' => 'friendly and authentic',
            'ai.brand_context' => '',
            'ai.custom_prompt' => null,
            'ai.custom_prompt_version' => null,
            'ai.transcribe_audio' => false,

            'publishing.require_approval' => false,
            'publishing.cross_post_all' => true,
            'publishing.paused' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        // Outside a workspace (system context) only the defaults apply.
        $stored = Tenant::id() === null ? [] : Cache::remember($this->cacheKey(), 300, fn () => AppSetting::query()->pluck('value', 'key')->all());

        return array_merge(self::defaults(), $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default ?? (self::defaults()[$key] ?? null));
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        if (Tenant::id() === null) {
            throw new LogicException('Settings can only be saved inside a workspace.');
        }

        foreach ($values as $key => $value) {
            AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY.'.'.Tenant::id();
    }

    /**
     * @return array<int, string>
     */
    public function recipients(): array
    {
        return array_values(array_filter((array) $this->get('notifications.recipients'), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}
