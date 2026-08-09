<?php
// bootstrap/app.php

/**
 * ----------------------------------------------------------------
 * APPLICATION BOOTSTRAP
 * ----------------------------------------------------------------
 *
 * This file bootstraps the application by:
 * 1. Starting the session.
 * 2. Loading the Composer autoloader.
 * 3. Creating the Pimple DI container.
 * 4. Loading all configurations (.env, app.php, carriers.php).
 * 5. Registering all services (Logger, Otp, DB, CAPI).
 * 6. Managing the long-lived visitor_id.
 * 7. Registering all controllers.
 *
 * @return \Pimple\Container The fully configured DI container.
 */

// Import all necessary classes
use Pimple\Container;
use App\Controller\FormController;
use App\Controller\OtpController;
use App\Controller\ThankYouController;
use App\Service\OtpApiService;
use App\Service\SessionService;
use App\Service\SimpleUserLoggerService;
use App\Service\FacebookCapiService;
use App\Service\SessionIdProcessor;
use App\Service\UserInfoService;
use App\Service\CsrfService;
use App\Service\RateLimiterService;
use App\Service\UrlRotationService;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use App\Utils\OrderedJsonFormatter;
use GuzzleHttp\Client;

// 1. Start Session
// Must be called before any output.
session_start();

// 2. Load Composer Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// 3. Create the DI Container
$container = new Container();

// 4. Load Configuration
$container['config'] = require_once __DIR__ . '/../config/app.php';
$container['carrierConfig'] = require_once __DIR__ . '/../config/carriers.php';

// 5. Register Services

// --- Core Services ---

// Session Service (Singleton)
// Must be registered first as other services depend on it.
$container['SessionService'] = function ($c) {
    return new SessionService();
};

// User Info Service
$container['UserInfoService'] = function ($c) {
    return new UserInfoService();
};

// CSRF Service
$container['CsrfService'] = function ($c) {
    return new CsrfService($c['SessionService']);
};

// --- Infrastructure Services ---

// Guzzle HTTP Client
$container['GuzzleClient'] = function ($c) {
    return new Client([
        'timeout' => 30, // total timeout
        'connect_timeout' => 10, // 10 seconds to establish a connection
        'headers' => [
            'Content-Type' => 'application/json',
            'Connection' => 'close' // Disable keep-alive
        ]
    ]);
};

// App Logger (JSON, Rotating)
$container['Logger'] = function ($c) {
    $filename = $c['config']['log_path']['app_filename'] ?? 'app.log';
    $handler = new RotatingFileHandler($c['config']['log_path']['app_dir'] . '/' . $filename, 10, Level::Debug);
    $handler->setFormatter(new OrderedJsonFormatter());

    $log = new Logger('app');
    $log->pushHandler($handler);
    $log->pushProcessor(new SessionIdProcessor($c['SessionService']));

    return $log;
};

// CAPI Logger (JSON, Rotating)
$container['CapiLogger'] = function ($c) {
    $filename = $c['config']['log_path']['capi_filename'] ?? 'capi.log';
    $handler = new RotatingFileHandler($c['config']['log_path']['capi_dir'] . '/' . $filename, 10, Level::Debug);
    $handler->setFormatter(new OrderedJsonFormatter());

    $log = new Logger('capi');
    $log->pushHandler($handler);
    $log->pushProcessor(new SessionIdProcessor($c['SessionService']));

    return $log;
};

// --- Domain Services ---

// OTP API Service
$container['OtpApiService'] = function ($c) {
    return new OtpApiService($c['config']['api'], $c['Logger'], $c['GuzzleClient']);
};

// User Logger Service (Database)
$container['UserLoggerService'] = function ($c) {
    return new SimpleUserLoggerService($c['config']['db']['path'], $c['Logger']);
};

// Rate Limiter Service (Database)
$container['RateLimiterService'] = function ($c) {
    // Use the same DB path as UserLogger but a different table
    return new RateLimiterService($c['config']['db']['path'], $c['Logger']);
};

// Url Rotation Service
$container['UrlRotationService'] = function ($c) {
    return new UrlRotationService($c['config']['db']['path'], $c['Logger']);
};

// Facebook CAPI Service
$container['FacebookCapiService'] = function ($c) {
    if (empty($c['config']['facebook']['capi_token'])) {
        return null;
    }
    return new FacebookCapiService($c['config'], $c['CapiLogger']);
};


// 6. Manage Long-Lived Visitor ID
/** @var \App\Service\SessionService $session */
$session = $container['SessionService'];
$visitorId = null;
if ($session->has('visitor_id')) {
    // Priority 1: Trust the server-side session first.
    $visitorId = $session->get('visitor_id');
} elseif (isset($_COOKIE['visitor_id'])) {
    // Priority 2: Trust the long-term cookie.
    $visitorId = $_COOKIE['visitor_id'];
} else {
    // Priority 3: This is a brand new user.
    try {
        $visitorId = 'v_' . bin2hex(random_bytes(16));
    } catch (\Exception $e) {
        // Fallback if random_bytes fails (unlikely)
        $visitorId = uniqid('v_', true);
    }
    setcookie(
        'visitor_id',
        $visitorId,
        [
            'expires' => time() + (3600 * 24 * 365), // 1 year
            'path' => '/',
            'secure' => $container['config']['env'] === 'production',
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}
// Store in session for easy access during this single request
$session->set('visitor_id', $visitorId);


// 7. Register Controllers

$container['FormController'] = function ($c) {
    return new FormController(
        $c['config'],
        $c['carrierConfig'],
        $c['OtpApiService'],
        $c['UserLoggerService'],
        $c['FacebookCapiService'],
        $c['Logger'],
        $c['UserInfoService'],
        $c['SessionService'],
        $c['CsrfService'],
        $c['RateLimiterService'],
        $c['UrlRotationService']
    );
};

$container['OtpController'] = function ($c) {
    return new OtpController(
        $c['config'],
        $c['OtpApiService'],
        $c['FacebookCapiService'],
        $c['Logger'],
        $c['UserInfoService'],
        $c['SessionService'],
        $c['CsrfService'],
        $c['RateLimiterService']
    );
};

$container['ThankYouController'] = function ($c) {
    return new ThankYouController(
        $c['config'],
        $c['FacebookCapiService'],
        $c['Logger'],
        $c['UserInfoService'],
        $c['SessionService']
    );
};

// 8. Return the configured container to the front controller
return $container;