<?php

namespace App\Services\Sync;

use RuntimeException;

class UploadException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $errorCode = 'upload_error')
    {
        parent::__construct($message);
    }
}
