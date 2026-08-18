<?php
// src/Database/DatabaseConnectionFactory.php
declare(strict_types=1);

namespace App\Database;

use SQLite3;
use Exception;

class DatabaseConnectionFactory
{
    /**
     * Creates and configures an SQLite3 connection with WAL mode and busy timeout.
     *
     * @param string $dbPath The filesystem path to the SQLite database.
     * @param int $busyTimeoutMs Timeout in milliseconds to wait on locks.
     * @return SQLite3 The configured SQLite connection.
     * @throws Exception If the connection cannot be created.
     */
    public static function create(string $dbPath, int $busyTimeoutMs = 5000): SQLite3
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new Exception("Failed to create database directory: {$dir}");
        }

        $db = new SQLite3($dbPath);
        $db->busyTimeout($busyTimeoutMs);
        $db->exec('PRAGMA journal_mode = WAL;');

        return $db;
    }
}
