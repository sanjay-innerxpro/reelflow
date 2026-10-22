<?php

namespace App\Models;

use App\Enums\ErrorCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishAttempt extends Model
{
    public const RETRY = 'retry';

    public const FAIL = 'fail';

    public const POLL = 'poll';

    public const NONE = 'none';

    protected $fillable = [
        'scheduled_post_id', 'attempt_number', 'stage', 'started_at', 'completed_at', 'http_status', 'response_meta',
        'outcome', 'error_category', 'error_message', 'retry_decision', 'retry_at',
    ];

    protected function casts(): array
    {
        return [
            'error_category' => ErrorCategory::class,
            'response_meta' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'retry_at' => 'datetime',
        ];
    }

    public function scheduledPost(): BelongsTo
    {
        return $this->belongsTo(ScheduledPost::class);
    }
}
