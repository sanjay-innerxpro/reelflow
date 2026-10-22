<?php

namespace App\Services\Publishing;

use App\Enums\AccountStatus;
use App\Enums\ErrorCategory;
use App\Models\SocialAccount;
use App\Services\Notifications\NotificationService;
use App\Support\ActivityLogger;
use Carbon\CarbonInterface;

class AccountHealthService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Stop publishing to an account after a permanent authorisation problem
     * and tell the user. Further posts wait until the account is reconnected.
     */
    public function flag(SocialAccount $account, ErrorCategory $category, string $reason): void
    {
        $status = match ($category) {
            ErrorCategory::TokenExpired => AccountStatus::Expired,
            ErrorCategory::PermissionMissing => AccountStatus::Revoked,
            default => AccountStatus::Ineligible,
        };

        $account->forceFill([
            'status' => $status,
            'status_reason' => mb_substr($reason, 0, 500),
            'last_error_at' => now(),
        ])->save();

        $this->activity->log('account.flagged', $account, "{$status->value}: {$reason}", level: 'error');

        if (! $account->auth_problem_notified_at || $account->auth_problem_notified_at->lt(now()->subHours(12))) {
            $account->forceFill(['auth_problem_notified_at' => now()])->save();
            $this->notifications->accountProblem($account);
        }
    }

    public function pauseUntil(SocialAccount $account, CarbonInterface $until, string $reason): void
    {
        $account->forceFill(['paused_until' => $until, 'status_reason' => mb_substr($reason, 0, 500)])->save();
        $this->activity->log('account.paused', $account, $reason, ['until' => $until->toIso8601String()], 'warning');
    }
}
