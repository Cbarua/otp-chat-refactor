<?php
// config/app.php

// 1. Load Environment Variables
// This assumes .env is in the parent directory
$envFile = '.env';

// Only allow switching via cookie if NOT in production (safety check)
// OR if you are sure your .env.test is safe to expose logic-wise.
if (isset($_COOKIE['APP_ENV']) && $_COOKIE['APP_ENV'] === 'testing') {
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

// Get cookie value
$disableSmsFallback = isset($_COOKIE['DISABLE_SMS_FALLBACK']) && $_COOKIE['DISABLE_SMS_FALLBACK'] === 'true';

$ideamartUrls = $safeJsonDecode('IDEAMART_URLS');
if (isset($_COOKIE['TEST_IDEAMART_URLS']) && isset($_COOKIE['APP_ENV']) && $_COOKIE['APP_ENV'] === 'testing') {
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

if (isset($_COOKIE['TEST_LOG_DIR']) && isset($_COOKIE['APP_ENV']) && $_COOKIE['APP_ENV'] === 'testing') {
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