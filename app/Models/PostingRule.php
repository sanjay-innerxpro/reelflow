<?php

namespace App\Models;

use App\Enums\Platform;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostingRule extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    public const ORDER_OLDEST = 'oldest_first';

    public const ORDER_NEWEST = 'newest_first';

    public const ORDER_RANDOM = 'random';

    protected $fillable = [
        'social_account_id', 'platform', 'enabled', 'timezone', 'daily_limit', 'window_start', 'window_end',
        'min_interval_minutes', 'slot_times', 'active_weekdays', 'weekend_daily_limit', 'weekend_slot_times',
        'max_posts_per_period', 'period_hours', 'auto_retry', 'max_attempts', 'allow_reuse', 'selection_order',
        'folder_filter', 'privacy_status',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'enabled' => 'boolean',
            'daily_limit' => 'integer',
            'min_interval_minutes' => 'integer',
            'slot_times' => 'array',
            'active_weekdays' => 'array',
            'weekend_daily_limit' => 'integer',
            'weekend_slot_times' => 'array',
            'max_posts_per_period' => 'integer',
            'period_hours' => 'integer',
            'auto_retry' => 'boolean',
            'max_attempts' => 'integer',
            'allow_reuse' => 'boolean',
            'folder_filter' => 'array',
        ];
    }

    /**
     * Sensible defaults for a newly connected account (disabled until the user enables it).
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(SocialAccount $account): array
    {
        return [
            'platform' => $account->platform,
            'enabled' => false,
            'timezone' => config('reelflow.display_timezone'),
            'daily_limit' => config('reelflow.scheduling.default_daily_limit'),
            'window_start' => '08:00',
            'window_end' => '21:00',
            'min_interval_minutes' => config('reelflow.scheduling.default_min_interval_minutes'),
            'slot_times' => array_values(config('reelflow.scheduling.default_slots')),
            'active_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'auto_retry' => true,
            'max_attempts' => config('reelflow.publishing.max_attempts'),
            'allow_reuse' => false,
            'selection_order' => self::ORDER_OLDEST,
            'privacy_status' => $account->platform === Platform::YouTube ? 'public' : null,
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
