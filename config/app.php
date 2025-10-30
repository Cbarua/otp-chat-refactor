<?php
// config/app.php

// This assumes .env is in the parent directory
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Set error logging based on environment
if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'production') {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    // Note: LOG_PATH from .env is not easily accessible here
    // We'll set the error_log path in public/index.php
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// Timezone
date_default_timezone_set('Asia/Colombo');

return [
    'env' => $_ENV['APP_ENV'] ?? 'development',
    'log_path' => [
        'error' => $_ENV['ERROR_LOG_PATH'] ?? __DIR__ . "/../logs/error.log",
        'app' => $_ENV['APP_LOG_PATH'] ?? __DIR__ . "/../logs/app.log",
        'capi' => $_ENV['CAPI_LOG_PATH'] ?? __DIR__ . "/../logs/capi.log",
    ],
    'db' => [
        'path' => $_ENV['DB_PATH'] ?? __DIR__ . "/../logs/userlog.sqlite",
    ],
    'api' => [
        'ideamart' => $_ENV['IDEAMART_URL'],
        'mspace' => $_ENV['MSPACE_URL'],
        'bdapps' => $_ENV['BDAPPS_URL'] ?? '', // Future proofing
    ],
    'facebook' => [
        'pixel_id' => $_ENV['PIXEL_ID'],
        'capi_token' => $_ENV['FBCAPI_TOKEN'],
        'test_event_code' => $_ENV['TEST_EVENT_CODE'] ?? null,
    ],
    'content' => [
        'img_url' => $_ENV['IMG_URL'],
        'img_alt' => $_ENV['IMG_ALT'],
        'charge_text' => $_ENV['CHARGE_TEXT'],
    ],
];