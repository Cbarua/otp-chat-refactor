<?php
// src/Service/SimpleUserLoggerService.php
declare(strict_types=1);

namespace App\Service;

use SQLite3;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Manages logging user visits with the fast "UPSERT" method.
 *
 * This service uses a single INSERT ON CONFLICT query.
 * It only updates the timestamp if an *exact match* is found.
 *
 * PRO: Very fast, single database query, simple code.
 * CON: This will *NOT* enrich records. If a record with a NULL phone exists,
 * this service will *insert a new row* when a phone number is provided.
 *
 * !! IMPORTANT !!
 * This service REQUIRES a different database schema.
 * It will automatically create a UNIQUE INDEX if one doesn't exist.
 * This index is necessary for the ON CONFLICT clause to work.
 */
class SimpleUserLoggerService
{
    /**
     * @param SQLite3 $db The shared connection from DatabaseConnectionFactory.
     * @param LoggerInterface $logger
     */
    public function __construct(private readonly SQLite3 $db, private readonly LoggerInterface $logger)
    {
        $this->ensureSchema();
    }

    /**
     * Ensures logs table and unique index exist.
     */
    private function ensureSchema(): void
    {
        try {
            // Create the table (this is the same as your original)
            $createTableSQL = "CREATE TABLE IF NOT EXISTS logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                visitor_id TEXT,
                lastaccesstime TEXT,
                ipaddress TEXT,
                phonenumber TEXT,
                useragent TEXT
            )";

            if (!$this->db->exec($createTableSQL)) {
                throw new Exception("Failed to create logs table: " . $this->db->lastErrorMsg());
            }

            // --- THIS IS THE CRITICAL PART FOR THIS SERVICE ---
            // Create a UNIQUE INDEX. This is required for ON CONFLICT to work.
            // It treats NULL phone numbers as a single "empty" value.
            // This will safely run on an existing DB, *if* it doesn't have duplicates.
            $createIndexSQL = "CREATE UNIQUE INDEX IF NOT EXISTS idx_log_unique_visit
                               ON logs (visitor_id, ipaddress, useragent, COALESCE(phonenumber, ''))";

            if (!$this->db->exec($createIndexSQL)) {
                throw new Exception("Failed to create unique index: " . $this->db->lastErrorMsg());
            }
            // -------------------------------------------------
        } catch (Exception $e) {
            $this->logger->error("SimpleUserLoggerService Schema Error", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Logs a user visit using the fast "UPSERT" method.
     */
    public function logVisit(string $visitorId, string $ip, string $userAgent, ?string $phoneNumber = null): void
    {
        $timestamp = date('Y-m-d H:i:s');

        try {
            // This is one single, atomic query.
            // It attempts to INSERT. If the UNIQUE INDEX (idx_log_unique_visit)
            // detects a conflict, it will run the DO UPDATE command instead.
            $stmt = $this->db->prepare("
                INSERT INTO logs (visitor_id, lastaccesstime, ipaddress, phonenumber, useragent)
                VALUES (:vid, :ts, :ip, :phone, :ua)
                ON CONFLICT(visitor_id, ipaddress, useragent, COALESCE(phonenumber, ''))
                DO UPDATE SET lastaccesstime = :ts
            ");

            $stmt->bindValue(':vid', $visitorId, SQLITE3_TEXT);
            $stmt->bindValue(':ts', $timestamp, SQLITE3_TEXT);
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':phone', $phoneNumber, SQLITE3_TEXT);
            $stmt->bindValue(':ua', $userAgent, SQLITE3_TEXT);

            $result = @$stmt->execute();
            if ($result === false) {
                $this->logger->error("SimpleUserLoggerService logVisit Error", [
                    'error' => $this->db->lastErrorMsg(),
                    'code' => $this->db->lastErrorCode()
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error("SimpleUserLoggerService logVisit Exception", ['error' => $e->getMessage()]);
        }
    }
}
