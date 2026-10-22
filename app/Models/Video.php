<?php

namespace App\Models;

use App\Enums\AiStatus;
use App\Enums\Platform;
use App\Enums\VideoStatus;
use App\Models\Concerns\HasStateMachine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    use HasFactory;
    use HasStateMachine;

    protected $fillable = [
        'user_id', 'sync_device_id', 'original_filename', 'folder', 'checksum_sha256', 'storage_disk', 'storage_key',
        'mime_type', 'size_bytes', 'width', 'height', 'duration_seconds', 'frame_rate', 'video_codec', 'audio_codec',
        'has_audio', 'thumbnail_disk', 'thumbnail_key', 'language', 'category', 'topic', 'status', 'ai_status',
        'ai_confidence', 'allow_reuse', 'processing_error', 'validation_warnings', 'upload_failures', 'uploaded_at',
        'processing_started_at', 'processed_at', 'analyzed_at', 'storage_deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'ai_status' => AiStatus::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'float',
            'frame_rate' => 'float',
            'has_audio' => 'boolean',
            'ai_confidence' => 'float',
            'allow_reuse' => 'boolean',
            'validation_warnings' => 'array',
            'uploaded_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'storage_deleted_at' => 'datetime',
        ];
    }

    public function aspectRatio(): ?float
    {
        return $this->width && $this->height ? round($this->width / $this->height, 4) : null;
    }

    public function isVertical(): bool
    {
        $ratio = $this->aspectRatio();

        return $ratio !== null && $ratio < 1;
    }

    public function hasStoredFile(): bool
    {
        return $this->storage_key !== null && $this->storage_deleted_at === null && $this->status->hasStoredFile();
    }

    public function currentContent(Platform $platform): ?AiContent
    {
        return $this->aiContents()
            ->where('platform', $platform->value)
            ->where('is_current', true)
            ->latest('id')
            ->first();
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', VideoStatus::Ready->value);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('original_filename', 'like', $like)
            ->orWhere('folder', 'like', $like)
            ->orWhere('topic', 'like', $like)
            ->orWhere('category', 'like', $like));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function syncDevice(): BelongsTo
    {
        return $this->belongsTo(SyncDevice::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(VideoSource::class);
    }

    public function uploadSessions(): HasMany
    {
        return $this->hasMany(UploadSession::class);
    }

    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    public function aiContents(): HasMany
    {
        return $this->hasMany(AiContent::class);
    }

    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }
}
