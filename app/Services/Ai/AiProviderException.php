<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

class AiProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
