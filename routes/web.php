<?php
// routes/web.php

/**
 * ----------------------------------------------------------------
 * WEB ROUTER
 * ----------------------------------------------------------------
 *
 * This file handles all incoming web requests.
 * It determines the URI and HTTP method, then routes the
 * request to the correct controller method from the DI container.
 *
 * @param \Pimple\Container $container The application's DI container.
 */

// 1. Get URI and Method
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// 2. Run the Router
try {
    switch ($uri) {
        case '/':
            /** @var \App\Controller\FormController $controller */
            $controller = $container['FormController'];
            if ($method === 'POST') {
                $controller->handlePhoneForm();
            } else {
                $controller->showPhoneForm();
            }
            break;

        case '/otp':
            /** @var \App\Controller\OtpController $controller */
            $controller = $container['OtpController'];
            if ($method === 'POST') {
                $controller->handleOtpForm();
            } else {
                $controller->showOtpForm();
            }
            break;

        case '/thanks':
            /** @var \App\Controller\ThankYouController $controller */
            $controller = $container['ThankYouController'];
            $controller->showThankYouPage();
            break;

        default:
            // Handle static assets
            if (preg_match('/\.(css|js|png|jpg|jpeg|gif|ico)$/', $uri)) {
                return false; // Let the server handle it
            }
            http_response_code(404);
            echo "<h1>404 Not Found</h1>";
            break;
    }
} catch (Exception $e) {
    // 3. Global Exception Handling
    
    // Check if logger was initialized before throwing
    if (isset($container['Logger'])) {
        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = $container['Logger'];
        $logger->critical('Unhandled Exception', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    } else {
        // Fallback if logger itself fails
        error_log('Unhandled Exception: ' . $e->getMessage());
    }

    http_response_code(500);
    echo "<h1>500 Internal Server Error</h1>";
    
    // Show detailed error only in development
    if (isset($container['config']) && $container['config']['env'] === 'development') {
        echo "<pre>" . $e->getMessage() . "</pre>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
}