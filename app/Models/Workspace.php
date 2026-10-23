<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer: their videos, connected accounts, rules, posts and settings.
 * Limits of 0 mean unlimited.
 */
class Workspace extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'owner_id', 'storage_limit_mb', 'max_accounts', 'daily_ai_limit', 'suspended_at'];

    protected function casts(): array
    {
        return [
            'storage_limit_mb' => 'integer',
            'max_accounts' => 'integer',
            'daily_ai_limit' => 'integer',
            'suspended_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('suspended_at');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function storageUsedBytes(): int
    {
        return (int) Video::allWorkspaces()->where('workspace_id', $this->id)
            ->whereNotNull('storage_key')->whereNull('storage_deleted_at')->sum('size_bytes');
    }

    public function storageLimitBytes(): ?int
    {
        return $this->storage_limit_mb > 0 ? $this->storage_limit_mb * 1024 * 1024 : null;
    }

    /**
     * Whether $bytes more fit within the storage limit.
     */
    public function hasStorageFor(int $bytes): bool
    {
        $limit = $this->storageLimitBytes();

        return $limit === null || $this->storageUsedBytes() + $bytes <= $limit;
    }

    public function aiGenerationsToday(): int
    {
        return AiGeneration::allWorkspaces()->where('workspace_id', $this->id)->where('created_at', '>=', now()->startOfDay())->count();
    }

    public function aiLimitReached(): bool
    {
        return $this->daily_ai_limit > 0 && $this->aiGenerationsToday() >= $this->daily_ai_limit;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
