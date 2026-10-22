<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $action, ?Model $subject = null, ?string $message = null, array $properties = [], string $level = 'info'): ?ActivityLog
    {
        try {
            $userId = Auth::id();

            return ActivityLog::create([
                'user_id' => $userId,
                'actor_type' => $userId ? 'user' : (app()->runningInConsole() ? 'system' : 'device'),
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'level' => $level,
                'message' => $message !== null ? SecretRedactor::truncate($message, 500) : null,
                'properties' => $properties ? SecretRedactor::redact($properties) : null,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Audit logging must never break the operation being audited.
            Log::warning('Activity log write failed', ['error' => $e->getMessage(), 'action' => $action]);

            return null;
        }
    }

    public function statusChanged(Model $subject, string $from, string $to, ?string $reason = null): void
    {
        $entry = $this->log(
            strtolower(class_basename($subject)).'.status_changed',
            $subject,
            $reason ?? sprintf('%s → %s', $from, $to),
        );

        $entry?->forceFill(['from_status' => $from, 'to_status' => $to])->save();
    }
}
