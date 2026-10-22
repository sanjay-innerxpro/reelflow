<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidStateTransition extends RuntimeException
{
    public static function make(string $model, int|string $id, string $from, string $to): self
    {
        return new self(sprintf('%s #%s cannot move from "%s" to "%s".', $model, $id, $from, $to));
    }

    public static function stale(string $model, int|string $id, string $expected): self
    {
        return new self(sprintf('%s #%s is no longer in status "%s" (changed concurrently).', $model, $id, $expected));
    }
}
