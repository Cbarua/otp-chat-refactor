<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\TestLogHelper;

final class UrlRotationCest
{
    private static bool $logsCleared = false;
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/app.php';
    }

    public function _before(AcceptanceTester $I): void
    {
        $I->amOnPage('/');
        
        $logDir = TestLogHelper::getLogDir();
        $I->setCookie('APP_ENV', 'testing');
        $_COOKIE['APP_ENV'] = 'testing';
        $I->setCookie('TEST_LOG_DIR', $logDir);
        $_COOKIE['TEST_LOG_DIR'] = $logDir;
        $I->setCookie('TEST_CLASS_NAME', 'UrlRotationCest');
        $_COOKIE['TEST_CLASS_NAME'] = 'UrlRotationCest';

        // Set custom IDEAMART_URLS via cookie
        $customUrls = json_encode([
            "http://localhost:8081/fail1",
            "http://localhost:8081/fail2",
            "http://localhost:8081/success",
            "http://localhost:8081/success2",
        ]);
        $I->setCookie('TEST_IDEAMART_URLS', rawurlencode($customUrls));
        $_COOKIE['TEST_IDEAMART_URLS'] = $customUrls;

        $this->config = require __DIR__ . '/../../config/app.php';

        if (!self::$logsCleared) {
            $this->clearLogFiles();
            self::$logsCleared = true;
        }

        $this->resetDatabase();
    }

    private function clearLogFiles(): void
    {
        $files = TestLogHelper::getLogFiles('UrlRotationCest');
        $logFiles = [
            $files['app_full'],
            $files['capi_full'],
            $files['mock_api'],
        ];

        foreach ($logFiles as $file) {
            $timestamp = date('Y-m-d H:i:s');
            $separator = "\n" . str_repeat('=', 80) . "\n";
            $message = "{$separator}=== ACCEPTANCE TEST START ===\nTime: {$timestamp}{$separator}";
            @file_put_contents($file, $message);
        }
    }

    private function resetDatabase(): void
    {
        $dbPath = __DIR__ . '/../../logs/userlog.sqlite';
        if (!file_exists($dbPath)) {
            return;
        }

        try {
            $db = new \SQLite3($dbPath);
            $db->busyTimeout(5000);

            // Reset global counters for ideamart
            $checkTable = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='global_counters'");
            if ($checkTable) {
                $db->exec("DELETE FROM global_counters WHERE key_name = 'submission_count_ideamart'");
            }

            // Clear rate limits
            $checkRateTable = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='rate_limits'");
            if ($checkRateTable) {
                $db->exec("DELETE FROM rate_limits");
            }

            $db->close();
        } catch (\Exception $e) {
            // Ignore DB errors during test cleanup
        }
    }

    private function triggerRotation(AcceptanceTester $I, string $phoneBase, int $count = 3): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $I->amOnPage('/');
            $I->fillField('mobile', $phoneBase . str_pad((string) $i, 3, '0', STR_PAD_LEFT));
            $I->click('Register');
            $I->wait(2);
        }
    }

    private function logTestStart(AcceptanceTester $I, string $description): void
    {
        $separator = "\n" . str_repeat('=', 80) . "\n";
        $timestamp = date('Y-m-d H:i:s');
        $message = "{$separator}{$description}\nTime: {$timestamp}{$separator}";

        $files = TestLogHelper::getLogFiles('UrlRotationCest');
        $logFiles = [
            $files['app_full'],
            $files['capi_full'],
            $files['mock_api'],
        ];

        foreach ($logFiles as $file) {
            if (file_exists($file)) {
                @file_put_contents($file, $message, FILE_APPEND);
            }
        }

        $I->wantTo($description);
    }

    public function testUrlRotationLog(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test URL rotation triggers on every nth submission');

        $rotationInterval = (int) ($_ENV['URL_ROTATION_INTERVAL'] ?? 3);
        $this->triggerRotation($I, '0771234', $rotationInterval);

        // Check logs for "URL Rotation triggered"
        $files = TestLogHelper::getLogFiles('UrlRotationCest');
        $logFile = file_exists($files['app_full']) ? $files['app_full'] : __DIR__ . '/../../logs/app/app-' . date('Y-m-d') . '.log';
        $logContent = file_exists($logFile) ? file_get_contents($logFile) : '';
        codecept_debug("Reading log file: $logFile");
        codecept_debug("Log content length: " . strlen($logContent));
        $I->assertStringContainsString('URL Rotation triggered', $logContent);

        // Verify excluded phone does not trigger rotation and does not increment counter
        $countBefore = $this->getSubmissionCount('ideamart');

        $excludedPhone = '0771234568';
        $I->amOnPage('/');
        $I->fillField('mobile', $excludedPhone);
        $I->click('Register');

        $countAfter = $this->getSubmissionCount('ideamart');
        $I->assertEquals($countBefore, $countAfter, 'Submission count should not increment for excluded phone');
    }

    public function testPriorityUrlUsage(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test OTP is requested from and submitted to the priority URL');

        $rotationInterval = (int) ($_ENV['URL_ROTATION_INTERVAL'] ?? 3);
        $this->triggerRotation($I, '0771234', $rotationInterval);

        // Verify Verification Request
        $I->seeInCurrentUrl('/otp');
        $I->fillField('otp', '123456'); // Mock OTP
        $I->click('Verify');
        $I->wait(2);
        
        // Check logs for verification request to success2
        $files = TestLogHelper::getLogFiles('UrlRotationCest');
        $logFile = file_exists($files['app_full']) ? $files['app_full'] : __DIR__ . '/../../logs/app/app-' . date('Y-m-d') . '.log';
        $logContentAfter = file_exists($logFile) ? file_get_contents($logFile) : '';
        $I->assertStringContainsString('success2/verifyOtp.php', $logContentAfter);
    }

    private function getSubmissionCount(string $platform): int
    {
        $dbPath = __DIR__ . '/../../logs/userlog.sqlite';
        if (!file_exists($dbPath)) {
            return 0;
        }

        try {
            $db = new \SQLite3($dbPath);
            $db->busyTimeout(5000);
            $count = 0;

            $checkTable = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='global_counters'");
            if ($checkTable) {
                $result = $db->querySingle("SELECT counter_value FROM global_counters WHERE key_name = 'submission_count_$platform'");
                if ($result !== null) {
                    $count = (int) $result;
                }
            }

            $db->close();
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
