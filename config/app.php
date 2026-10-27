<?php
// config/app.php

// 1. Load Environment Variables
// This assumes .env is in the parent directory
$envFile = '.env';

// Test hooks: acceptance tests steer the app with cookies, honoured only when the server process sets
// TEST_HOOKS=1 (composer scripts, compose stack). Production never sets it, and a .env file can't:
// createImmutable() doesn't call putenv(), so getenv() only sees the real environment.
$testHooks = getenv('TEST_HOOKS') === '1';
$testEnv = $testHooks && ($_COOKIE['APP_ENV'] ?? null) === 'testing';
if ($testEnv) {
    $envFile = '.env.test';
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..', $envFile);
$dotenv->load();

// 2. Configure Environment & Error Logging
// Set timezone first
date_default_timezone_set('Asia/Colombo');

// Determine error log path
$errorLogPath = $_ENV['ERROR_LOG_PATH'] ?? __DIR__ . "/../logs/error.log";

// Configure PHP error handling
if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'production') {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', $errorLogPath);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    ini_set('error_log', $errorLogPath); // Ensure dev errors also go to file if needed
}

// 3. Helper Functions
/**
 * Safely decode JSON environment variables.
 * Logs an error if decoding fails.
 */
$safeJsonDecode = function ($key) {
    if (!isset($_ENV[$key])) {
        return [];
    }
    $decoded = json_decode($_ENV[$key], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Config Error: Failed to decode JSON for key '$key'. Error: " . json_last_error_msg());
        return [];
    }
    return $decoded ?? [];
};

$disableSmsFallback = $testHooks && ($_COOKIE['DISABLE_SMS_FALLBACK'] ?? null) === 'true';

$ideamartUrls = $safeJsonDecode('IDEAMART_URLS');
if ($testEnv && isset($_COOKIE['TEST_IDEAMART_URLS'])) {
    $decodedUrls = json_decode(rawurldecode($_COOKIE['TEST_IDEAMART_URLS']), true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $ideamartUrls = $decodedUrls;
    }
}

// 4. Return Configuration Array
$appLogDir = $_ENV['APP_LOG_DIR'] ?? __DIR__ . "/../logs/app";
$capiLogDir = $_ENV['CAPI_LOG_DIR'] ?? __DIR__ . "/../logs/capi";
$appFilename = 'app.log';
$capiFilename = 'capi.log';

if ($testEnv && isset($_COOKIE['TEST_LOG_DIR'])) {
    $testLogDir = $_COOKIE['TEST_LOG_DIR'];
    $testClass = $_COOKIE['TEST_CLASS_NAME'] ?? 'UnknownTest';
    $appLogDir = $testLogDir . '/' . $testClass;
    $capiLogDir = $testLogDir . '/' . $testClass;
    $appFilename = 'app.log';
    $capiFilename = 'capi.log';
}

return [
    'env' => $_ENV['APP_ENV'] ?? 'development',

    'log_path' => [
        'error' => $errorLogPath,
        'app_dir' => $appLogDir,
        'capi_dir' => $capiLogDir,
        'app_filename' => $appFilename,
        'capi_filename' => $capiFilename,
    ],

    'db' => [
        'path' => $_ENV['DB_PATH'] ?? __DIR__ . "/../logs/userlog.sqlite",
    ],

    'api' => [
        'ideamart' => $ideamartUrls,
        'mspace' => $safeJsonDecode('MSPACE_URLS'),
        'bdapps' => $safeJsonDecode('BDAPPS_URLS'),
    ],

    // Forwarded to the mock API so it logs under the test's folder.
    'api_test_cookies' => $testHooks ? array_intersect_key($_COOKIE, array_flip(['TEST_LOG_DIR', 'TEST_CLASS_NAME', 'APP_ENV'])) : [],

    'sms' => [
        'number' => $disableSmsFallback ? null : $_ENV['SMS_NUMBER'] ?? null,
        'keyword' => $disableSmsFallback ? null : $_ENV['SMS_KEYWORD'] ?? null,
    ],

    'facebook' => [
        'pixel_id' => $_ENV['PIXEL_ID'] ?? null,
        'capi_token' => $_ENV['FBCAPI_TOKEN'] ?? null,
        'test_event_code' => $_ENV['TEST_EVENT_CODE'] ?? null,
    ],

    'google' => [
        'ga_measurement_id' => $_ENV['GA_MEASUREMENT_ID'] ?? null,
    ],

    'content' => [
        'img_url' => $_ENV['IMG_URL'] ?? __DIR__ . "/../public/assets/images/Girl in a salwar.webp",
        'img_alt' => $_ENV['IMG_ALT'] ?? 'Girl in a salwar',
        'charge_text' => $_ENV['CHARGE_TEXT'] ?? '',
    ],
];