<?php

namespace App\Services\Publishing;

use App\Enums\ErrorCategory;
use App\Enums\Platform;
use Carbon\CarbonImmutable;

class RetryPolicy
{
    /**
     * Exponential backoff with ±20% jitter, honouring platform Retry-After hints.
     */
    public function nextAttemptAt(int $attempt, PublishException $e, Platform $platform): CarbonImmutable
    {
        if ($e->category === ErrorCategory::QuotaExhausted && $platform === Platform::YouTube) {
            return $this->youtubeQuotaReset();
        }

        $base = config('reelflow.publishing.retry_base_seconds');
        $max = config('reelflow.publishing.retry_max_seconds');
        $delay = min($max, $base * (2 ** max(0, $attempt - 1)));
        $delay = (int) round($delay * (random_int(80, 120) / 100));

        if ($e->retryAfterSeconds) {
            $delay = max($delay, $e->retryAfterSeconds);
        }

        return CarbonImmutable::now()->addSeconds(max(15, $delay));
    }

    /**
     * YouTube Data API quotas reset at midnight Pacific Time.
     */
    public function youtubeQuotaReset(): CarbonImmutable
    {
        return CarbonImmutable::now(config('reelflow.google.quota_reset_timezone'))
            ->addDay()->startOfDay()->addMinutes(10)->utc();
    }
}
