<?php

declare(strict_types=1);

namespace Tests\Support;

class TestLogHelper
{
    private static ?string $sharedDir = null;
    private static bool $initialized = false;

    /**
     * Retrieves (and creates if necessary) the shared log directory for the current test run.
     */
    public static function getLogDir(): string
    {
        if (self::$sharedDir !== null) {
            return self::$sharedDir;
        }

        $baseLogsDir = dirname(__DIR__, 2) . '/logs';
        $acceptanceLogsDir = $baseLogsDir . '/acceptance_tests';
        $tempFile = $baseLogsDir . '/.acceptance_test_dir';

        // If this is the start of a new CLI runner session, delete the old tracking file
        if (!self::$initialized && php_sapi_name() === 'cli') {
            self::$initialized = true;
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        // Reuse directory if it is recorded in the temporary file
        if (file_exists($tempFile)) {
            $dir = trim((string)file_get_contents($tempFile));
            if ($dir && is_dir($dir)) {
                self::$sharedDir = $dir;
                return $dir;
            }
        }

        // Create new timestamp directory under logs/acceptance_tests/
        $dateTime = date('Y-m-d_H-i-s');
        $dirPath = $acceptanceLogsDir . "/{$dateTime}";

        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0777, true);
        }

        file_put_contents($tempFile, $dirPath);
        self::$sharedDir = $dirPath;

        return $dirPath;
    }

    public static function getLogFiles(string $testClassName): array
    {
        $dir = self::getLogDir() . '/' . $testClassName;
        
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $today = date('Y-m-d');
        return [
            'app_dir' => $dir,
            'app_file' => 'app.log',
            // The file we read/write directly in the test has the date suffix appended
            'app_full' => "{$dir}/app-{$today}.log",
            
            'capi_dir' => $dir,
            'capi_file' => 'capi.log',
            'capi_full' => "{$dir}/capi-{$today}.log",
            
            'mock_api' => "{$dir}/mock_api.log",
        ];
    }
}
