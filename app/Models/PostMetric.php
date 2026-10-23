<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMetric extends Model
{
    use BelongsToWorkspace;

    public const FIELDS = ['views', 'likes', 'comments', 'shares', 'saves', 'reach', 'avg_watch_seconds', 'total_watch_seconds'];

    protected $fillable = [
        'scheduled_post_id', 'captured_at', 'views', 'likes', 'comments', 'shares', 'saves', 'reach',
        'avg_watch_seconds', 'total_watch_seconds', 'raw',
    ];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'raw' => 'array', 'avg_watch_seconds' => 'float'];
    }

    public function scheduledPost(): BelongsTo
    {
        return $this->belongsTo(ScheduledPost::class);
    }
}
