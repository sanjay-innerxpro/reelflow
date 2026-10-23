<?php

namespace App\Database;

use Illuminate\Database\Connectors\MariaDbConnector;

class RetryingMariaDbConnector extends MariaDbConnector
{
    use RetriesTransientConnectErrors;
}
