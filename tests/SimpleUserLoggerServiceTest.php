<?php
// tests/SimpleUserLoggerServiceTest.php

namespace App\Tests\Service;

use App\Service\SimpleUserLoggerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SQLite3;

class SimpleUserLoggerServiceTest extends TestCase
{
    private SQLite3 $db;
    private $loggerMock;

    protected function setUp(): void
    {
        $this->db = new SQLite3(':memory:');
        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testLogVisitUpsert(): void
    {
        $service = new SimpleUserLoggerService($this->db, $this->loggerMock);

        // 1. Insert
        $service->logVisit('v1', '127.0.0.1', 'UA', '0771234567');

        $count = $this->db->querySingle("SELECT COUNT(*) FROM logs");
        $this->assertEquals(1, $count);

        // 2. Upsert (same unique key)
        $service->logVisit('v1', '127.0.0.1', 'UA', '0771234567');

        $count = $this->db->querySingle("SELECT COUNT(*) FROM logs");
        $this->assertEquals(1, $count);
    }

    public function testLogVisitQueryFailure(): void
    {
        // A DB that creates the schema but throws on prepare
        $dbMock = $this->createMock(SQLite3::class);
        $dbMock->method('exec')->willReturn(true);
        $dbMock->method('prepare')->willThrowException(new \Exception('Query failed'));

        $service = new SimpleUserLoggerService($dbMock, $this->loggerMock);

        $this->loggerMock->expects($this->once())->method('error')->with($this->stringContains('logVisit'));

        $service->logVisit('v1', '127.0.0.1', 'UA');
    }
}
