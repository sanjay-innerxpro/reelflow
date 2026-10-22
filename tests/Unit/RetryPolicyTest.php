<?php

namespace Tests\Unit;

use App\Enums\ErrorCategory;
use App\Enums\Platform;
use App\Services\Publishing\PublishException;
use App\Services\Publishing\RetryPolicy;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RetryPolicyTest extends TestCase
{
    public function test_exponential_backoff_with_jitter_and_cap(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 10:00:00');
        $policy = new RetryPolicy;
        $e = new PublishException(ErrorCategory::Network, 'x');

        $first = now()->diffInSeconds($policy->nextAttemptAt(1, $e, Platform::Facebook));
        $third = now()->diffInSeconds($policy->nextAttemptAt(3, $e, Platform::Facebook));
        $tenth = now()->diffInSeconds($policy->nextAttemptAt(10, $e, Platform::Facebook));

        $this->assertEqualsWithDelta(60, $first, 13);
        $this->assertEqualsWithDelta(240, $third, 49);
        $this->assertLessThanOrEqual(3600 * 1.2 + 1, $tenth);
    }

    public function test_retry_after_hint_is_honoured(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 10:00:00');
        $e = new PublishException(ErrorCategory::RateLimit, 'slow down', retryAfterSeconds: 1800);

        $this->assertGreaterThanOrEqual(1800, now()->diffInSeconds((new RetryPolicy)->nextAttemptAt(1, $e, Platform::Instagram)));
    }

    public function test_youtube_quota_waits_for_pacific_midnight(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22 15:00:00', 'America/Los_Angeles'));
        $next = (new RetryPolicy)->nextAttemptAt(1, new PublishException(ErrorCategory::QuotaExhausted, 'quota'), Platform::YouTube);

        $this->assertSame('2026-10-23 00:10', $next->setTimezone('America/Los_Angeles')->format('Y-m-d H:i'));
    }
}
