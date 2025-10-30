<?php
// public/index.php

/**
 * ----------------------------------------------------------------
 * APPLICATION ENTRY POINT
 * ----------------------------------------------------------------
 *
 * This is the single public entry point for the application.
 *
 * 1. It bootstraps the application by loading the DI container
 * and all services.
 *
 * 2. It passes the container to the web router to handle
 * the incoming request.
 */

// 1. Bootstrap the application
// This file returns the fully configured $container
$container = require_once __DIR__ . '/../bootstrap/app.php';

// 2. Run the web router
// This file uses the $container to route the request
require_once __DIR__ . '/../routes/web.php';