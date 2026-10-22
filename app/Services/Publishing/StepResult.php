<?php

namespace App\Services\Publishing;

/**
 * Outcome of a status check (processing, confirmation or reconciliation).
 */
final class StepResult
{
    public const DONE = 'done';

    public const PENDING = 'pending';

    public const NOT_PUBLISHED = 'not_published';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $state,
        public readonly ?string $externalId = null,
        public readonly ?string $url = null,
        public readonly ?string $note = null,
    ) {}

    public static function done(?string $externalId = null, ?string $url = null, ?string $note = null): self
    {
        return new self(self::DONE, $externalId, $url, $note);
    }

    public static function pending(?string $note = null): self
    {
        return new self(self::PENDING, note: $note);
    }

    public static function notPublished(?string $note = null): self
    {
        return new self(self::NOT_PUBLISHED, note: $note);
    }

    public static function unknown(string $note): self
    {
        return new self(self::UNKNOWN, note: $note);
    }

    public function is(string $state): bool
    {
        return $this->state === $state;
    }
}
