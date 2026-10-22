<?php

namespace App\Services\Ai;

use RuntimeException;

class InvalidAiResponse extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
