<?php

namespace App\Enums;

enum ErrorCategory: string
{
    case Network = 'network';
    case RateLimit = 'rate_limit';
    case UploadInterrupted = 'upload_interrupted';
    case MediaProcessing = 'media_processing';
    case InvalidVideo = 'invalid_video';
    case TokenExpired = 'token_expired';
    case PermissionMissing = 'permission_missing';
    case AccountIneligible = 'account_ineligible';
    case QuotaExhausted = 'quota_exhausted';
    case InvalidMetadata = 'invalid_metadata';
    case Ambiguous = 'ambiguous';
    case Unknown = 'unknown';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::Network, self::RateLimit, self::UploadInterrupted, self::MediaProcessing,
            self::QuotaExhausted, self::Unknown => true,
            default => false,
        };
    }

    /**
     * Errors that make every post on the account fail until a human acts.
     */
    public function pausesAccount(): bool
    {
        return in_array($this, [self::TokenExpired, self::PermissionMissing, self::AccountIneligible], true);
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
