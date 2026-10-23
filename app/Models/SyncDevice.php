<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SyncDevice extends Model
{
    use BelongsToWorkspace;
    use HasFactory;

    protected $fillable = [
        'user_id', 'device_uuid', 'name', 'token_hash', 'token_hint', 'status', 'agent_version',
        'platform_info', 'last_ip', 'last_seen_at', 'revoked_at', 'last_error', 'last_error_at', 'offline_notified_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_error_at' => 'datetime',
            'offline_notified_at' => 'datetime',
        ];
    }

    /**
     * Create a device and return the plaintext credential exactly once.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, ?int $userId = null): array
    {
        $token = config('reelflow.devices.token_prefix').Str::random(48);

        $device = self::create([
            'user_id' => $userId,
            'device_uuid' => (string) Str::uuid(),
            'name' => $name,
            'token_hash' => self::hashToken($token),
            'token_hint' => substr($token, -6),
            'status' => DeviceStatus::Active,
        ]);

        return [$device, $token];
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public static function findByToken(string $token): ?self
    {
        return self::allWorkspaces()->where('token_hash', self::hashToken($token))->first();
    }

    public function isActive(): bool
    {
        return $this->status === DeviceStatus::Active;
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subMinutes(config('reelflow.devices.offline_after_minutes')));
    }

    public function revoke(): void
    {
        $this->update(['status' => DeviceStatus::Revoked, 'revoked_at' => now()]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function uploadSessions(): HasMany
    {
        return $this->hasMany(UploadSession::class);
    }
}
