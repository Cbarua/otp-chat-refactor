<?php
// src/Service/EnrichingUserLoggerService.php
declare(strict_types=1);

namespace App\Service;

use SQLite3;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Manages logging user visits with "enrichment" logic.
 *
 * This service implements your original, complex logic:
 * 1. It finds exact matches and updates the timestamp.
 * 2. It finds records with NULL phone numbers and "enriches" them if a phone is provided.
 * 3. It inserts a new row only if no other rules match.
 *
 * PRO: High data quality, exactly matches your business rules.
 * CON: Slower performance (requires a SELECT, logic, then UPDATE/INSERT).
 */
class EnrichingUserLoggerService implements UserLoggerInterface
{
    private ?SQLite3 $db = null;
    private ?string $dbPath = null;
    private LoggerInterface $logger;
    private bool $ownsConnection = false;

    /**
     * @param SQLite3|string $dbOrPath The direct file path to the SQLite database or shared SQLite3 connection.
     * @param LoggerInterface $logger
     */
    public function __construct(SQLite3|string $dbOrPath, LoggerInterface $logger)
    {
        $this->logger = $logger;
        if ($dbOrPath instanceof SQLite3) {
            $this->db = $dbOrPath;
            $this->ownsConnection = false;
            $this->ensureSchema();
        } else {
            $this->dbPath = $dbOrPath;
            $this->ownsConnection = true;
            $this->initializeDatabase();
        }
    }

    /**
     * Opens the DB connection and ensures the table exists.
     * This schema works for this service.
     */
    private function initializeDatabase(): void
    {
        try {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                // Set permissions to 0775 for security (group can write, public cannot)
                mkdir($dir, 0775, true);
            }
            
            $this->db = new SQLite3($this->dbPath);
            
            // Mitigate "database is locked" errors:
            $this->db->busyTimeout(5000);
            $this->db->exec('PRAGMA journal_mode = WAL;');
            
            $this->ensureSchema();
        } catch (Exception $e) {
            $this->logger->error("EnrichingUserLogger: DB Error", ['error' => $e->getMessage()]);
            $this->db = null;
        }
    }

    /**
     * Ensures logs table exists.
     */
    private function ensureSchema(): void
    {
        if (!$this->db) {
            return;
        }

        try {
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
        } catch (Exception $e) {
            $this->logger->error("EnrichingUserLogger: Schema Error", ['error' => $e->getMessage()]);
            if ($this->ownsConnection) {
                $this->db = null;
            }
        }
    }

    /**
     * Logs a user visit or updates an existing record.
     * Logs a user visit using the "read-then-write" enrichment logic.
     *
     * @param string $visitorId   The visitor's unique ID (from cookie/session)
     * @param string $ip
     * @param string $userAgent
     * @param string|null $phoneNumber
     */
    public function logVisit(string $visitorId, string $ip, string $userAgent, ?string $phoneNumber = null): void
    {
        if (!$this->db) {
            $this->logger->error("EnrichingUserLogger: No database connection.");
            return;
        }

        $timestamp = date('Y-m-d H:i:s');

        try {
            // Use a transaction for safety against race conditions
            $this->db->exec('BEGIN TRANSACTION');

            // Find existing record for this visitor ID, IP, and UA
            $stmt = $this->db->prepare("
                SELECT id, phonenumber FROM logs
                WHERE visitor_id = :vid AND ipaddress = :ip AND useragent = :ua
                ORDER BY id DESC
            ");
            $stmt->bindValue(':vid', $visitorId, SQLITE3_TEXT);
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':ua', $userAgent, SQLITE3_TEXT);
            $result = $stmt->execute();

            $foundExactMatch = false;
            $foundNullPhone = null;

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['phonenumber'] === $phoneNumber) {
                    $foundExactMatch = $row['id'];
                    break;
                }
                if (empty($row['phonenumber'])) {
                    $foundNullPhone = $row['id'];
                }
            }

            if ($foundExactMatch) {
                // RULE 1: Exact match exists. Just update timestamp.
                $stmtUpdate = $this->db->prepare("UPDATE logs SET lastaccesstime = :ts WHERE id = :id");
                $stmtUpdate->bindValue(':ts', $timestamp, SQLITE3_TEXT);
                $stmtUpdate->bindValue(':id', $foundExactMatch, SQLITE3_INTEGER);
                $stmtUpdate->execute();

            } elseif ($foundNullPhone && $phoneNumber) {
                // RULE 2: Found a previous log with no phone. Update it.
                $stmtUpdate = $this->db->prepare("UPDATE logs SET phonenumber = :phone, lastaccesstime = :ts WHERE id = :id");
                $stmtUpdate->bindValue(':phone', $phoneNumber, SQLITE3_TEXT);
                $stmtUpdate->bindValue(':ts', $timestamp, SQLITE3_TEXT);
                $stmtUpdate->bindValue(':id', $foundNullPhone, SQLITE3_INTEGER);
                $stmtUpdate->execute();

            } else {
                // RULE 3: No match, or this visit has a different phone number. Insert new.
                $stmtInsert = $this->db->prepare("
                    INSERT INTO logs (visitor_id, lastaccesstime, ipaddress, phonenumber, useragent)
                    VALUES (:vid, :ts, :ip, :phone, :ua)
                ");
                $stmtInsert->bindValue(':vid', $visitorId, SQLITE3_TEXT);
                $stmtInsert->bindValue(':ts', $timestamp, SQLITE3_TEXT);
                $stmtInsert->bindValue(':ip', $ip, SQLITE3_TEXT);
                $stmtInsert->bindValue(':phone', $phoneNumber, SQLITE3_TEXT);
                $stmtInsert->bindValue(':ua', $userAgent, SQLITE3_TEXT);
                $stmtInsert->execute();
            }

            // Commit the changes
            $this->db->exec('COMMIT');

        } catch (Exception $e) {
            // Something went wrong, roll back
            $this->db?->exec('ROLLBACK');
            $this->logger->error("EnrichingUserLogger: logVisit Failed", ['error' => $e->getMessage()]);
        }
    }
    
    public function __destruct()
    {
        if ($this->ownsConnection) {
            $this->db?->close();
        }
    }
}
