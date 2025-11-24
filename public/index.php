<?php
// public/index.php

// 1. Load Composer autoloader and bootstrap the application
$container = require_once __DIR__ . '/../bootstrap/app.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use FastRoute\Dispatcher;

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// 2. Create a Request object from the PHP globals
$request = Request::createFromGlobals();

// 3. Set up the router
$dispatcher = FastRoute\simpleDispatcher(function (FastRoute\RouteCollector $r) {
    // The routes/web.php file returns a closure that defines the routes.
    $routes = require __DIR__ . '/../routes/web.php';
    $routes($r);
});

// 4. Dispatch the request
$routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getPathInfo());

// 5. Handle the response from the router
$response = null;

try {
    switch ($routeInfo[0]) {
        case Dispatcher::NOT_FOUND:
            // ... 404 Not Found
            $response = new Response('Not Found', 404);
            break;

        case Dispatcher::METHOD_NOT_ALLOWED:
            // ... 405 Method Not Allowed
            $allowedMethods = $routeInfo[1];
            $response = new Response('Method Not Allowed', 405, ['Allow' => implode(', ', $allowedMethods)]);
            break;

        case Dispatcher::FOUND:
            // ... Call the controller action
            $handler = $routeInfo[1];
            $vars = $routeInfo[2]; // Route parameters (not used in this app, but available)

            // The handler is a 'ControllerName@methodName' string
            [$controllerName, $methodName] = explode('@', $handler, 2);

            // Get the controller instance from the DI container
            $controller = $container[$controllerName];

            // Call the method and pass the request object.
            // The controller action will return a Response object.
            $response = $controller->$methodName($request, $vars);
            break;
    }
} catch (\Throwable $e) {
    // 6. Global Exception Handling
    $logger = $container['Logger'];
    $logger->critical('Unhandled Exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    $body = '<h1>500 Internal Server Error</h1>';
    if ($container['config']['env'] === 'development') {
        $body .= '<pre>' . $e->getMessage() . '</pre>';
        $body .= '<pre>' . $e->getTraceAsString() . '</pre>';
    }
    $response = new Response($body, 500);
}

// 7. Send the response to the browser
if ($response instanceof Response) {
    $response->send();
} else {
    // Fallback for safety, though this should not be reached
    (new Response('An unexpected error occurred', 500))->send();
}