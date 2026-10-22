<?php

namespace Tests\Unit;

use App\Enums\ErrorCategory;
use App\Services\Publishing\GoogleErrorClassifier;
use App\Services\Publishing\MetaErrorClassifier;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErrorClassifierTest extends TestCase
{
    public static function metaCases(): array
    {
        return [
            'expired token' => [400, 190, 463, false, ErrorCategory::TokenExpired],
            'missing permission' => [403, 200, 0, false, ErrorCategory::PermissionMissing],
            'app rate limit' => [400, 4, 0, false, ErrorCategory::RateLimit],
            'page rate limit' => [400, 32, 0, false, ErrorCategory::RateLimit],
            'ig publish limit' => [400, 9, 2207042, false, ErrorCategory::QuotaExhausted],
            'ig unsupported video' => [400, 352, 2207026, false, ErrorCategory::InvalidVideo],
            'ig container expired' => [400, 9, 2207020, false, ErrorCategory::MediaProcessing],
            'transient' => [500, 2, 0, true, ErrorCategory::Network],
            'invalid param' => [400, 100, 0, false, ErrorCategory::InvalidMetadata],
            'blocked' => [400, 368, 0, false, ErrorCategory::AccountIneligible],
        ];
    }

    #[DataProvider('metaCases')]
    public function test_meta_error_categories(int $status, int $code, int $subcode, bool $transient, ErrorCategory $expected): void
    {
        $this->assertSame($expected, MetaErrorClassifier::category($status, $code, $subcode, $transient));
    }

    public function test_meta_response_parsing_redacts_and_reads_retry_hint(): void
    {
        $response = new Response(new Psr7Response(400, [
            'x-business-use-case-usage' => json_encode(['123' => [['type' => 'pages', 'estimated_time_to_regain_access' => 7]]]),
        ], json_encode(['error' => ['message' => 'Rate limited access_token=EAAsecretsecretsecretsecret1234', 'code' => 32, 'fbtrace_id' => 'abc']])));

        $e = MetaErrorClassifier::fromResponse($response, 'test');

        $this->assertSame(ErrorCategory::RateLimit, $e->category);
        $this->assertSame(420, $e->retryAfterSeconds);
        $this->assertStringNotContainsString('EAAsecret', $e->getMessage());
        $this->assertSame('abc', $e->responseMeta['fbtrace_id']);
    }

    public static function googleCases(): array
    {
        return [
            [403, 'quotaExceeded', ErrorCategory::QuotaExhausted],
            [403, 'uploadLimitExceeded', ErrorCategory::QuotaExhausted],
            [403, 'rateLimitExceeded', ErrorCategory::RateLimit],
            [401, 'authError', ErrorCategory::TokenExpired],
            [403, 'insufficientPermissions', ErrorCategory::PermissionMissing],
            [401, 'youtubeSignupRequired', ErrorCategory::AccountIneligible],
            [400, 'invalidTitle', ErrorCategory::InvalidMetadata],
            [503, 'backendError', ErrorCategory::Network],
            [404, '', ErrorCategory::UploadInterrupted],
        ];
    }

    #[DataProvider('googleCases')]
    public function test_google_error_categories(int $status, string $reason, ErrorCategory $expected): void
    {
        $this->assertSame($expected, GoogleErrorClassifier::category($status, $reason));
    }

    public function test_retryable_versus_permanent(): void
    {
        foreach ([ErrorCategory::Network, ErrorCategory::RateLimit, ErrorCategory::UploadInterrupted, ErrorCategory::MediaProcessing, ErrorCategory::QuotaExhausted] as $c) {
            $this->assertTrue($c->isRetryable(), $c->value);
        }
        foreach ([ErrorCategory::TokenExpired, ErrorCategory::PermissionMissing, ErrorCategory::AccountIneligible, ErrorCategory::InvalidVideo, ErrorCategory::InvalidMetadata, ErrorCategory::Ambiguous] as $c) {
            $this->assertFalse($c->isRetryable(), $c->value);
        }
        $this->assertTrue(ErrorCategory::TokenExpired->pausesAccount());
        $this->assertFalse(ErrorCategory::RateLimit->pausesAccount());
    }
}
