<?php
// public/backend-test-api/index.php

// 1. Parse the Request URI
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];

// Remove the script path (e.g., /otp-chat-refactor/public/backend-test-api/index.php) to get the relative path
$basePath = dirname($scriptName);
$relativePath = str_replace($basePath, '', $requestUri);

// Clean up query strings
$path = parse_url($relativePath, PHP_URL_PATH);

// 2. Determine Action (getOtp or verifyOtp)
$isGetOtp = strpos($path, 'getOtp.php') !== false;
$isVerifyOtp = strpos($path, 'verifyOtp.php') !== false;

// 3. Determine "Gateway" (url1, url2, etc.) for simulation logic
// use regex to get gateway name
// Matches /url1/getOtp.php or /index.php/url1/getOtp.php
$regex_url = '/(?:\/index\.php)?\/([a-zA-Z0-9]+)\/(?:getOtp|verifyOtp)\.php$/';
preg_match($regex_url, $path, $matches);
$url = $matches[1] ?? '';

// 4. Route to Logic
require_once __DIR__ . '/MockApiController.php';

$controller = new MockApiController();

// Map "getOtp.php" to "getOtp" action, etc.
$action = '';
if ($isGetOtp) {
    $action = 'getOtp';
} elseif ($isVerifyOtp) {
    $action = 'verifyOtp';
}

$controller->handleRequest($url, $action);