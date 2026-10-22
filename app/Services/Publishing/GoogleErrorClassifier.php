<?php

namespace App\Services\Publishing;

use App\Enums\ErrorCategory;
use Illuminate\Http\Client\Response;

/**
 * Maps YouTube Data API errors to error categories.
 * Reference: https://developers.google.com/youtube/v3/docs/errors
 */
class GoogleErrorClassifier
{
    public static function fromResponse(Response $response, string $context): PublishException
    {
        $status = $response->status();
        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? '');
        $message = (string) ($response->json('error.message') ?? $response->json('error_description') ?? 'HTTP '.$status);

        $category = self::category($status, $reason);
        $retryAfter = $response->header('Retry-After') !== '' ? (int) $response->header('Retry-After') : null;

        return new PublishException(
            $category,
            "{$context}: {$message}",
            $status,
            array_filter(['context' => $context, 'reason' => $reason ?: null]),
            $retryAfter,
        );
    }

    public static function category(int $status, string $reason): ErrorCategory
    {
        return match (true) {
            in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'uploadLimitExceeded'], true) => ErrorCategory::QuotaExhausted,
            in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'RESOURCE_EXHAUSTED'], true) || $status === 429 => ErrorCategory::RateLimit,
            $reason === 'youtubeSignupRequired' || $reason === 'channelNotFound' => ErrorCategory::AccountIneligible,
            in_array($reason, ['invalidTitle', 'invalidDescription', 'invalidTags', 'invalidCategoryId', 'invalidVideoMetadata', 'invalidPublishAt', 'invalidRecordingDetails'], true) => ErrorCategory::InvalidMetadata,
            in_array($reason, ['mediaBodyRequired', 'invalidFilename', 'uploadAborted'], true) => ErrorCategory::UploadInterrupted,
            $status === 401 || $reason === 'authError' || $reason === 'invalid_grant' => ErrorCategory::TokenExpired,
            in_array($reason, ['forbidden', 'insufficientPermissions', 'accessNotConfigured', 'PERMISSION_DENIED'], true) || $status === 403 => ErrorCategory::PermissionMissing,
            $status === 404 || $status === 410 => ErrorCategory::UploadInterrupted,
            $status >= 500 => ErrorCategory::Network,
            $status === 400 => ErrorCategory::InvalidMetadata,
            default => ErrorCategory::Unknown,
        };
    }
}
