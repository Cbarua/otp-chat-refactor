<?php
declare(strict_types=1);

namespace App\Service;

use SQLite3;
use Exception;
use Psr\Log\LoggerInterface;

class UrlRotationService
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
            $this->db->busyTimeout(5000);
            $this->db->exec('PRAGMA journal_mode = WAL;');

            $createTableSQL = "CREATE TABLE IF NOT EXISTS global_counters (
                key_name TEXT PRIMARY KEY,
                counter_value INTEGER DEFAULT 0
            )";

            if (!$this->db->exec($createTableSQL)) {
                throw new Exception("Failed to create global_counters table: " . $this->db->lastErrorMsg());
            }
        } catch (Exception $e) {
            $this->logger->error("UrlRotationService DB Error", ['error' => $e->getMessage()]);
            $this->db = null;
        }
    }

    public function incrementSubmissionCount(string $platform): void
    {
        if (!$this->db) {
            return;
        }

        try {
            $key = 'submission_count_' . $platform;
            $stmt = $this->db->prepare("
                INSERT INTO global_counters (key_name, counter_value)
                VALUES (:key, 1)
                ON CONFLICT(key_name)
                DO UPDATE SET counter_value = counter_value + 1
            ");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $stmt->execute();
        } catch (Exception $e) {
            $this->logger->error("UrlRotationService increment Error", ['error' => $e->getMessage()]);
        }
    }

    public function shouldRotate(string $platform): bool
    {
        if (!$this->db) {
            return false;
        }

        try {
            $key = 'submission_count_' . $platform;
            $stmt = $this->db->prepare("SELECT counter_value FROM global_counters WHERE key_name = :key");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);

            if ($row) {
                $count = $row['counter_value'];
                // Rotate every nth submission
                $rotationInterval = $_ENV['URL_ROTATION_INTERVAL'] ?? 3;
                return ($count % $rotationInterval) === 0;
            }
        } catch (Exception $e) {
            $this->logger->error("UrlRotationService check Error", ['error' => $e->getMessage()]);
        }

        return false;
    }

    public function getRotatedUrls(array $defaultUrls, array $priorityNames): array
    {
        if (empty($priorityNames) || empty($defaultUrls)) {
            return $defaultUrls;
        }

        // Map URLs to their "names" (assuming the URL contains the name or we match by some logic)
        // The requirement says: "match the names to urls and sort them like url2, url3, url1"
        // The .env example: IDEAMART_URLS='[".../fail1", ".../fail2", ".../success"]'
        // Priority: '["fail2", "fail1"]'

        // Strategy:
        // 1. Find URLs that contain the priority strings.
        // 2. Place them at the top in the order of priorityNames.
        // 3. Append the rest of the URLs.

        $sortedUrls = [];
        $remainingUrls = $defaultUrls;

        foreach ($priorityNames as $name) {
            foreach ($remainingUrls as $key => $url) {
                // Check if URL contains the name (simple string match)
                if (strpos($url, $name) !== false) {
                    $sortedUrls[] = $url;
                    unset($remainingUrls[$key]);
                    break; // One URL per name as requested
                }
            }
        }

        // Append remaining URLs
        return array_merge($sortedUrls, array_values($remainingUrls));
    }

    public function __destruct()
    {
        $this->db?->close();
    }
}
