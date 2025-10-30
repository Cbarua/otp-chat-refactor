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
 * 8. Setting global error logging.
 *
 * @return \Pimple\Container The fully configured DI container.
 */

// Import all necessary classes
use Pimple\Container;
use App\Controller\FormController;
use App\Controller\OtpController;
use App\Controller\ThankYouController;
use App\Service\OtpApiService;
use App\Service\SimpleUserLoggerService;
use App\Service\FacebookCapiService;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

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

// Monolog Logger Service
$container['Logger'] = function ($c) {
    $logPath = $c['config']['log_path']['app'];
    // Format: [29-Oct-2025 23:15:32 Asia/Colombo]
    $dateFormat = "d-M-Y H:i:s T";
    $outputFormat = "[%datetime%] %channel%.%level_name%: %message% %context%\n";

    $formatter = new LineFormatter($outputFormat, $dateFormat);
    $handler = new StreamHandler($logPath, Level::Debug);
    $handler->setFormatter($formatter);

    $log = new Logger('app');
    $log->pushHandler($handler);
    return $log;
};

// OTP API Service
$container['OtpApiService'] = function ($c) {
    return new OtpApiService($c['config']);
};

// User Logger Service
$container['UserLoggerService'] = function ($c) {
    return new SimpleUserLoggerService($c['config']['db']['path']); 
};

// Facebook CAPI Service
$container['FacebookCapiService'] = function ($c) {
    return new FacebookCapiService($c['config']);
};


// 6. Manage Long-Lived Visitor ID
$visitorId = null;
if (isset($_COOKIE['visitor_id'])) {
    $visitorId = $_COOKIE['visitor_id'];
} else {
    $visitorId = uniqid('v_', true);
    // Set cookie before any output
    setcookie(
        'visitor_id',
        $visitorId,
        [
            'expires' => time() + (3600 * 24 * 365), // 1 year
            'path' => '/',
            // Send only over HTTPS
            'secure' => $container['config']['env'] === 'production',
            'httponly' => true,  // Not accessible by JavaScript
            'samesite' => 'Lax'
        ]
    );
}
// Store in session for easy access during this single request
$_SESSION['visitor_id'] = $visitorId;


// 7. Register Controllers
$container['FormController'] = function ($c) {
    return new FormController(
        $c['config'],
        $c['carrierConfig'],
        $c['OtpApiService'],
        $c['UserLoggerService'],
        $c['FacebookCapiService'],
        $c['Logger']
    );
};

$container['OtpController'] = function ($c) {
    return new OtpController(
        $c['config'],
        $c['OtpApiService'],
        $c['FacebookCapiService'],
        $c['Logger']
    );
};

$container['ThankYouController'] = function ($c) {
    return new ThankYouController(
        $c['config'],
        $c['FacebookCapiService']
    );
};

// 8. Set Global Error Logging
ini_set('error_log', $container['config']['log_path']['error']);

// 9. Return the configured container to the front controller
return $container;