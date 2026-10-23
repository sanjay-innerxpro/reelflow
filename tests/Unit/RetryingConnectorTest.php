<?php

namespace Tests\Unit;

use App\Database\RetryingMySqlConnector;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class RetryingConnectorTest extends TestCase
{
    private function connector(array &$attempts, int $failures, string $message): RetryingMySqlConnector
    {
        return new class($attempts, $failures, $message) extends RetryingMySqlConnector
        {
            public function __construct(private array &$attempts, private int $failures, private string $message) {}

            protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
            {
                $this->attempts[] = microtime(true);
                if (count($this->attempts) <= $this->failures) {
                    throw new PDOException($this->message);
                }

                return new PDO('sqlite::memory:');
            }
        };
    }

    public function test_transient_connect_refusals_are_retried(): void
    {
        $attempts = [];
        $pdo = $this->connector($attempts, 2, 'SQLSTATE[HY000] [2002] Operation not permitted')->createConnection('mysql:', [], []);

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertCount(3, $attempts);
    }

    public function test_other_errors_fail_immediately(): void
    {
        $attempts = [];
        $this->expectException(PDOException::class);

        try {
            $this->connector($attempts, 5, "SQLSTATE[HY000] [1049] Unknown database 'nope'")->createConnection('mysql:', [], []);
        } finally {
            $this->assertCount(1, $attempts);
        }
    }
}
