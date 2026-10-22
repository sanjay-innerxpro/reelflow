<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\Platform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SocialAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'platform', 'external_id', 'name', 'username', 'account_type', 'parent_account_id',
        'access_token', 'refresh_token', 'token_expires_at', 'scopes', 'status', 'status_reason', 'paused_until',
        'last_authenticated_at', 'last_error_at', 'auth_problem_notified_at', 'metadata',
    ];

    /**
     * Tokens never leave the server: hidden from serialisation and encrypted at rest.
     */
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => AccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'metadata' => 'array',
            'paused_until' => 'datetime',
            'last_authenticated_at' => 'datetime',
            'last_error_at' => 'datetime',
            'auth_problem_notified_at' => 'datetime',
        ];
    }

    public function isPublishable(): bool
    {
        return $this->status->canPublish()
            && ($this->paused_until === null || $this->paused_until->isPast());
    }

    public function tokenExpiresSoon(int $minutes = 5): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->lte(now()->addMinutes($minutes));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function scopePublishable(Builder $query): Builder
    {
        return $query->where('status', AccountStatus::Connected->value)
            ->where(fn (Builder $q) => $q->whereNull('paused_until')->orWhere('paused_until', '<=', now()));
    }

    public function displayName(): string
    {
        return $this->name.($this->username ? ' (@'.$this->username.')' : '');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function postingRule(): HasOne
    {
        return $this->hasOne(PostingRule::class);
    }

    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }
}
