<?php

namespace App\Services\Publishing;

use App\Enums\ErrorCategory;
use App\Support\SecretRedactor;
use RuntimeException;
use Throwable;

class PublishException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $responseMeta  already-sanitised API response details
     */
    public function __construct(
        public readonly ErrorCategory $category,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly array $responseMeta = [],
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $resetStage = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct(SecretRedactor::truncate($message, 1000), 0, $previous);
    }

    public static function make(ErrorCategory $category, string $message, ?int $status = null, array $meta = []): self
    {
        return new self($category, $message, $status, SecretRedactor::redact($meta));
    }

    public function withCategory(ErrorCategory $category, ?string $prefix = null): self
    {
        return new self($category, ($prefix ? $prefix.' ' : '').$this->getMessage(), $this->httpStatus, $this->responseMeta, $this->retryAfterSeconds, $this->resetStage, $this);
    }
}
