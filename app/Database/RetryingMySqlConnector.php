<?php

namespace App\Database;

use Illuminate\Database\Connectors\MySqlConnector;

class RetryingMySqlConnector extends MySqlConnector
{
    use RetriesTransientConnectErrors;
}
