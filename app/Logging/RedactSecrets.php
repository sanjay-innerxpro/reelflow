<?php

namespace App\Logging;

use App\Support\SecretRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Monolog tap: strips tokens, keys and signed-URL signatures from every log
 * message and its context before it is written.
 */
class RedactSecrets
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: SecretRedactor::redactString($record->message),
                context: SecretRedactor::redact($record->context),
                extra: SecretRedactor::redact($record->extra),
            );
        });
    }
}
