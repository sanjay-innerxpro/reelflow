<?php

namespace App\Services\Publishing;

use App\Enums\ErrorCategory;

/**
 * The outcome of a publish call cannot be determined. The post must not be
 * retried automatically; a person has to check the platform.
 */
class ReviewRequiredException extends PublishException
{
    public static function because(string $message): self
    {
        return new self(ErrorCategory::Ambiguous, $message);
    }
}
