<?php

namespace App\Services\Publishing;

use App\Enums\ErrorCategory;
use App\Support\SecretRedactor;
use Illuminate\Http\Client\Response;

/**
 * Maps Graph API error payloads to error categories.
 * Reference: https://developers.facebook.com/docs/graph-api/guides/error-handling
 */
class MetaErrorClassifier
{
    private const RATE_LIMIT_CODES = [4, 17, 32, 341, 613, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014];

    public static function fromResponse(Response $response, string $context): PublishException
    {
        $error = (array) $response->json('error', []);
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $message = (string) ($error['error_user_msg'] ?? $error['message'] ?? 'HTTP '.$response->status());
        $status = $response->status();

        $category = self::category($status, $code, $subcode, (bool) ($error['is_transient'] ?? false));

        $meta = SecretRedactor::redact([
            'context' => $context,
            'code' => $code ?: null,
            'subcode' => $subcode ?: null,
            'type' => $error['type'] ?? null,
            'fbtrace_id' => $error['fbtrace_id'] ?? null,
            'is_transient' => $error['is_transient'] ?? null,
        ]);

        $retryAfter = $category === ErrorCategory::RateLimit ? self::retryAfter($response) : null;
        $reset = in_array($subcode, [2207008, 2207020, 2207032], true);

        return new PublishException($category, "{$context}: {$message}", $status, array_filter($meta, fn ($v) => $v !== null), $retryAfter, $reset);
    }

    public static function category(int $status, int $code, int $subcode, bool $transient): ErrorCategory
    {
        return match (true) {
            $code === 190 || $code === 102 || $code === 463 || $code === 467 => ErrorCategory::TokenExpired,
            $subcode === 2207042 => ErrorCategory::QuotaExhausted,
            $subcode === 2207026 || $subcode === 2207004 || $subcode === 2207006 => ErrorCategory::InvalidVideo,
            $subcode === 2207050 || $subcode === 2207051 || $code === 368 => ErrorCategory::AccountIneligible,
            in_array($subcode, [2207001, 2207003], true) => ErrorCategory::Network,
            in_array($subcode, [2207052, 2207053], true) => ErrorCategory::UploadInterrupted,
            in_array($subcode, [2207008, 2207020, 2207032], true) => ErrorCategory::MediaProcessing,
            in_array($code, self::RATE_LIMIT_CODES, true) || $status === 429 => ErrorCategory::RateLimit,
            $code === 10 || ($code >= 200 && $code <= 299) || ($code === 100 && $subcode === 33) => ErrorCategory::PermissionMissing,
            $code === 100 => ErrorCategory::InvalidMetadata,
            $transient || $code === 1 || $code === 2 || $status >= 500 => ErrorCategory::Network,
            $status === 401 => ErrorCategory::TokenExpired,
            $status === 403 => ErrorCategory::PermissionMissing,
            default => ErrorCategory::Unknown,
        };
    }

    private static function retryAfter(Response $response): ?int
    {
        // X-Business-Use-Case-Usage / X-App-Usage carry estimated_time_to_regain_access (minutes).
        foreach (['x-business-use-case-usage', 'x-app-usage'] as $header) {
            $value = json_decode((string) $response->header($header), true);
            if (! is_array($value)) {
                continue;
            }
            $minutes = 0;
            array_walk_recursive($value, function ($v, $k) use (&$minutes) {
                if ($k === 'estimated_time_to_regain_access') {
                    $minutes = max($minutes, (int) $v);
                }
            });
            if ($minutes > 0) {
                return $minutes * 60;
            }
        }

        return null;
    }
}
