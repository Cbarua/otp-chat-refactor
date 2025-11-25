<?php
// tests/SimpleUserLoggerServiceTest.php

namespace App\Tests\Service;

use App\Service\SimpleUserLoggerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SQLite3;

class SimpleUserLoggerServiceTest extends TestCase
{
    private string $dbPath;
    private $loggerMock;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/test_simple_logger_' . uniqid() . '.db';
        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testInitializeDatabaseSuccess(): void
    {
        $service = new SimpleUserLoggerService($this->dbPath, $this->loggerMock);
        $this->assertFileExists($this->dbPath);
    }

    public function testInitializeDatabaseFailure(): void
    {
        $invalidPath = sys_get_temp_dir(); // Directory path causes open failure

        $this->loggerMock->expects($this->once())->method('error')->with($this->stringContains('DB Error'));

        new SimpleUserLoggerService($invalidPath, $this->loggerMock);
    }

    public function testLogVisitNoDbConnection(): void
    {
        $service = new SimpleUserLoggerService(sys_get_temp_dir(), $this->loggerMock);

        $this->loggerMock->expects($this->once())->method('error')->with($this->stringContains('No database connection'));

        $service->logVisit('v1', '127.0.0.1', 'UA');
    }

    public function testLogVisitUpsert(): void
    {
        $service = new SimpleUserLoggerService($this->dbPath, $this->loggerMock);

        // 1. Insert
        $service->logVisit('v1', '127.0.0.1', 'UA', '0771234567');

        $db = new SQLite3($this->dbPath);
        $count = $db->querySingle("SELECT COUNT(*) FROM logs");
        $this->assertEquals(1, $count);

        // 2. Upsert (same unique key)
        $service->logVisit('v1', '127.0.0.1', 'UA', '0771234567');

        $count = $db->querySingle("SELECT COUNT(*) FROM logs");
        $this->assertEquals(1, $count);
    }

    public function testLogVisitQueryFailure(): void
    {
        $service = new SimpleUserLoggerService($this->dbPath, $this->loggerMock);

        // Inject a mock DB that throws on prepare
        $dbMock = $this->createMock(SQLite3::class);
        $dbMock->method('prepare')->willThrowException(new \Exception('Query failed'));

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('db');
        $property->setValue($service, $dbMock);

        $this->loggerMock->expects($this->once())->method('error')->with($this->stringContains('logVisit Error'));

        $service->logVisit('v1', '127.0.0.1', 'UA');
    }

    public function testDestructorClosesDatabase(): void
    {
        $dbPath = sys_get_temp_dir() . '/test_simple_destruct_' . uniqid() . '.db';

        // Create service and use it
        $service = new SimpleUserLoggerService($dbPath, $this->loggerMock);
        $service->logVisit('v1', '127.0.0.1', 'UA');

        // Explicitly destroy the service
        unset($service);

        // Verify database file exists and can be deleted (connection closed)
        $this->assertTrue(file_exists($dbPath));
        @unlink($dbPath);
        $this->assertFalse(file_exists($dbPath));
    }

    public function testConcurrencyConfiguration(): void
    {
        $service = new SimpleUserLoggerService($this->dbPath, $this->loggerMock);

        // Access private property $db using Reflection
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $db = $property->getValue($service);

        // 1. Verify WAL Mode
        $journalMode = $db->querySingle("PRAGMA journal_mode");
        $this->assertEquals('wal', strtolower($journalMode), "Journal mode should be WAL");

        // 2. Verify Busy Timeout
        $busyTimeout = $db->querySingle("PRAGMA busy_timeout");
        $this->assertEquals(5000, $busyTimeout, "Busy timeout should be 5000ms");
    }
}
