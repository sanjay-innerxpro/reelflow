<?php

namespace App\Models;

use App\Enums\ErrorCategory;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PublishStage;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasStateMachine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduledPost extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasStateMachine;

    protected $fillable = [
        'video_id', 'social_account_id', 'ai_content_id', 'platform', 'reuse_sequence', 'scheduled_at', 'status', 'stage',
        'attempt_count', 'max_attempts', 'next_attempt_at', 'claim_token', 'claimed_until', 'external_upload_id',
        'external_container_id', 'upload_session_url', 'external_post_id', 'post_url', 'last_error_category',
        'last_error_message', 'requires_review', 'processing_started_at', 'publishing_started_at', 'published_at',
        'confirmed_at', 'cancelled_at', 'metrics_synced_at',
    ];

    protected $hidden = ['upload_session_url'];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => PostStatus::class,
            'stage' => PublishStage::class,
            'last_error_category' => ErrorCategory::class,
            // A YouTube resumable session URI grants upload access on its own.
            'upload_session_url' => 'encrypted',
            'requires_review' => 'boolean',
            'scheduled_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'claimed_until' => 'datetime',
            'processing_started_at' => 'datetime',
            'publishing_started_at' => 'datetime',
            'published_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metrics_synced_at' => 'datetime',
        ];
    }

    /**
     * Persist remote identifiers immediately, outside any surrounding
     * transaction concerns, so a crash right after a remote call still
     * leaves enough information to reconcile.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function checkpoint(array $attributes): void
    {
        $this->forceFill($attributes)->save();
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function aiContent(): BelongsTo
    {
        return $this->belongsTo(AiContent::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PublishAttempt::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(PostMetric::class);
    }
}
