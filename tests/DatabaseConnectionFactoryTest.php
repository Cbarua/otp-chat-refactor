<?php
// tests/DatabaseConnectionFactoryTest.php

use PHPUnit\Framework\TestCase;
use App\Database\DatabaseConnectionFactory;
use SQLite3;

class DatabaseConnectionFactoryTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/test_factory_' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testCreateConfiguresWalModeAndBusyTimeout(): void
    {
        $db = DatabaseConnectionFactory::create($this->dbPath, 3000);
        $this->assertInstanceOf(SQLite3::class, $db);

        $journalMode = $db->querySingle("PRAGMA journal_mode");
        $this->assertEquals('wal', strtolower($journalMode));

        $busyTimeout = $db->querySingle("PRAGMA busy_timeout");
        $this->assertEquals(3000, $busyTimeout);

        $db->close();
    }

    public function testCreateInNestedDirectory(): void
    {
        $nestedPath = sys_get_temp_dir() . '/nested_' . uniqid() . '/db/test.sqlite';
        $db = DatabaseConnectionFactory::create($nestedPath, 5000);
        $this->assertInstanceOf(SQLite3::class, $db);
        $db->close();

        @unlink($nestedPath);
        @rmdir(dirname($nestedPath));
        @rmdir(dirname(dirname($nestedPath)));
    }
}
