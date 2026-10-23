<?php

namespace App\Database;

use Throwable;

/**
 * Shared hosting briefly refuses new MySQL connections under load
 * ("[2002] Operation not permitted", "Too many connections"). Retry the
 * connect a few times before failing the request or job.
 */
trait RetriesTransientConnectErrors
{
    private const TRANSIENT = ['[2002]', 'Operation not permitted', 'Too many connections', 'max_user_connections', '[1040]', '[1203]'];

    protected function tryAgainIfCausedByLostConnection(Throwable $e, $dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        if ($this->causedByLostConnection($e)) {
            return $this->createPdoConnection($dsn, $username, $password, $options);
        }

        for ($attempt = 1; $attempt <= 4 && $this->isTransient($e); $attempt++) {
            usleep(250_000 * $attempt);
            try {
                return $this->createPdoConnection($dsn, $username, $password, $options);
            } catch (Throwable $retry) {
                $e = $retry;
            }
        }

        throw $e;
    }

    private function isTransient(Throwable $e): bool
    {
        foreach (self::TRANSIENT as $needle) {
            if (str_contains($e->getMessage(), $needle)) {
                return true;
            }
        }

        return false;
    }
}
