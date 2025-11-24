<?php
// tests/EnrichingUserLoggerServiceTest.php

namespace App\Tests\Service;

use App\Service\EnrichingUserLoggerService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SQLite3;

class EnrichingUserLoggerServiceTest extends TestCase
{
    private string $dbPath;
    private $loggerMock;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/test_enriching_logger_' . uniqid() . '.db';
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
        $service = new EnrichingUserLoggerService($this->dbPath, $this->loggerMock);
        $this->assertFileExists($this->dbPath);
    }

    public function testInitializeDatabaseFailure(): void
    {
        // Use a path that is a directory to cause failure
        $invalidPath = sys_get_temp_dir();

        $this->loggerMock->expects($this->once())->method('error')->with($this->stringContains('DB Error'));

        new EnrichingUserLoggerService($invalidPath, $this->loggerMock);
    }

    public function testLogVisitNoDbConnection(): void
    {
        // Force failure by using invalid path
        $service = new EnrichingUserLoggerService(sys_get_temp_dir(), $this->loggerMock);

        $this->loggerMock->expects($this->once())->method('error')->with($this->stringContains('No database connection'));

        $service->logVisit('v1', '127.0.0.1', 'UA');
    }

    public function testLogVisitNewInsert(): void
    {
        $service = new EnrichingUserLoggerService($this->dbPath, $this->loggerMock);

        $service->logVisit('v1', '127.0.0.1', 'UA', '0771234567');

        $db = new SQLite3($this->dbPath);
        $result = $db->querySingle("SELECT * FROM logs WHERE visitor_id = 'v1'", true);

        $this->assertEquals('v1', $result['visitor_id']);
        $this->assertEquals('0771234567', $result['phonenumber']);
    }

    public function testLogVisitEnrichment(): void
    {
        $service = new EnrichingUserLoggerService($this->dbPath, $this->loggerMock);

        // 1. Log initial visit without phone
        $service->logVisit('v1', '127.0.0.1', 'UA', null);

        // 2. Log visit with phone - should update the existing record
        $service->logVisit('v1', '127.0.0.1', 'UA', '0771234567');

        $db = new SQLite3($this->dbPath);
        $count = $db->querySingle("SELECT COUNT(*) FROM logs");
        $row = $db->querySingle("SELECT * FROM logs WHERE visitor_id = 'v1'", true);

        $this->assertEquals(1, $count); // Should still be 1 record
        $this->assertEquals('0771234567', $row['phonenumber']);
    }

    public function testLogVisitExactMatchUpdate(): void
    {
        $service = new EnrichingUserLoggerService($this->dbPath, $this->loggerMock);

        // 1. Log visit
        $service->logVisit('v1', '127.0.0.1', 'UA', '0771234567');
        sleep(1); // Ensure timestamp difference

        // 2. Log same visit again
        $service->logVisit('v1', '127.0.0.1', 'UA', '0771234567');

        $db = new SQLite3($this->dbPath);
        $count = $db->querySingle("SELECT COUNT(*) FROM logs");

        $this->assertEquals(1, $count);
    }

    public function testLogVisitTransactionFailure(): void
    {
        $service = new EnrichingUserLoggerService($this->dbPath, $this->loggerMock);

        // Inject a mock DB that throws on exec
        $dbMock = $this->createMock(SQLite3::class);
        $dbMock->method('exec')->willReturnOnConsecutiveCalls(
            $this->throwException(new \Exception('Transaction failed')),
            true
        );

        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('db');
        $property->setValue($service, $dbMock);

        $this->loggerMock->expects($this->once())->method('error')->with($this->stringContains('logVisit Error'));

        $service->logVisit('v1', '127.0.0.1', 'UA');
    }

    public function testDestructorClosesDatabase(): void
    {
        $dbPath = sys_get_temp_dir() . '/test_enriching_destruct_' . uniqid() . '.db';

        // Create service and use it
        $service = new EnrichingUserLoggerService($dbPath, $this->loggerMock);
        $service->logVisit('v1', '127.0.0.1', 'UA');

        // Explicitly destroy the service
        unset($service);

        // Verify database file exists and can be deleted (connection closed)
        $this->assertTrue(file_exists($dbPath));
        @unlink($dbPath);
        $this->assertFalse(file_exists($dbPath));
    }
}
