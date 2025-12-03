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

// 4. Return Configuration Array
return [
    'env' => $_ENV['APP_ENV'] ?? 'development',

    'log_path' => [
        'error' => $errorLogPath,
        'app_dir' => $_ENV['APP_LOG_DIR'] ?? __DIR__ . "/../logs/app",
        'capi_dir' => $_ENV['CAPI_LOG_DIR'] ?? __DIR__ . "/../logs/capi",
    ],

    'db' => [
        'path' => $_ENV['DB_PATH'] ?? __DIR__ . "/../logs/userlog.sqlite",
    ],

    'api' => [
        'ideamart' => $safeJsonDecode('IDEAMART_URLS'),
        'mspace' => $safeJsonDecode('MSPACE_URLS'),
        'bdapps' => $safeJsonDecode('BDAPPS_URLS'),
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
        'img_url' => $_ENV['IMG_URL'] ?? __DIR__ . "/../public/assets/images/Girl in a salwar.jpeg",
        'img_alt' => $_ENV['IMG_ALT'] ?? 'Girl in a salwar',
        'charge_text' => $_ENV['CHARGE_TEXT'] ?? '',
    ],
];