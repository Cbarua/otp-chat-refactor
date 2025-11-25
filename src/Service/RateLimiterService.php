<?php
// src/Service/RateLimiterService.php

namespace App\Service;

use SQLite3;
use Exception;
use Psr\Log\LoggerInterface;

class RateLimiterService
{
    private ?SQLite3 $db = null;
    private string $dbPath;
    private LoggerInterface $logger;

    public function __construct(string $dbPath, LoggerInterface $logger)
    {
        $this->dbPath = $dbPath;
        $this->logger = $logger;
        $this->initializeDatabase();
    }

    private function initializeDatabase(): void
    {
        try {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $this->db = new SQLite3($this->dbPath);

            // Mitigate "database is locked" errors:
            $this->db->busyTimeout(5000);
            $this->db->exec('PRAGMA journal_mode = WAL;');

            // Create table for rate limits
            $createTableSQL = "CREATE TABLE IF NOT EXISTS rate_limits (
                key TEXT PRIMARY KEY,
                count INTEGER DEFAULT 0,
                reset_at TEXT
            )";

            if (!$this->db->exec($createTableSQL)) {
                throw new Exception("Failed to create rate_limits table: " . $this->db->lastErrorMsg());
            }

        } catch (Exception $e) {
            $this->logger->error("RateLimiterService DB Error", ['error' => $e->getMessage()]);
            $this->db = null;
        }
    }

    /**
     * Checks if the key has exceeded the limit.
     * 
     * @param string $key Unique identifier (e.g., 'ip:127.0.0.1:otp_req')
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $windowSeconds Time window in seconds
     * @return bool True if request is allowed, False if blocked
     */
    public function check(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if (!$this->db) {
            return true; // Fail open if DB is down
        }

        $now = time();

        try {
            // 1. Cleanup old entries (lazy expiration)
            // We do this specifically for the requested key to handle reset logic
            $stmt = $this->db->prepare("SELECT count, reset_at FROM rate_limits WHERE key = :key");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);

            if (!$row) {
                // New entry
                $this->reset($key, $windowSeconds);
                return true;
            }

            // Handle both legacy (integer) and new (string) formats
            $resetAt = $row['reset_at'];
            $expiryTime = is_numeric($resetAt) ? (int) $resetAt : strtotime($resetAt);

            if ($now > $expiryTime) {
                // Window expired, reset
                $this->reset($key, $windowSeconds);
                return true;
            }

            if ($row['count'] >= $maxAttempts) {
                return false; // Blocked
            }

            return true; // Allowed

        } catch (Exception $e) {
            $this->logger->error("RateLimiterService Check Error", ['error' => $e->getMessage()]);
            return true; // Fail open
        }
    }

    /**
     * Increments the counter for the key.
     */
    public function increment(string $key): void
    {
        if (!$this->db) {
            return;
        }

        try {
            $stmt = $this->db->prepare("UPDATE rate_limits SET count = count + 1 WHERE key = :key");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->execute();
        } catch (Exception $e) {
            $this->logger->error("RateLimiterService Increment Error", ['error' => $e->getMessage()]);
        }
    }

    private function reset(string $key, int $windowSeconds): void
    {
        // Store as human-readable DATETIME (e.g., "2025-11-25 12:30:00")
        $resetAt = date('Y-m-d H:i:s', time() + $windowSeconds);

        $stmt = $this->db->prepare("INSERT INTO rate_limits (key, count, reset_at) VALUES (:key, 0, :reset)
                                    ON CONFLICT(key) DO UPDATE SET count = 0, reset_at = :reset");
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':reset', $resetAt, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function __destruct()
    {
        $this->db?->close();
    }
}
