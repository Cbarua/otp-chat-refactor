<?php
// routes/web.php

use FastRoute\RouteCollector;

/**
 * Defines the web routes for the application.
 *
 * This function is included by the front controller and is passed a
 * FastRoute\RouteCollector instance, which it uses to define all
 * the application's routes.
 *
 * @param FastRoute\RouteCollector $r The route collector.
 */
return function(RouteCollector $r) {
    // Each route maps an HTTP method and a URI to a "ControllerName@methodName" string.
    // The dispatcher will use this to determine which controller action to execute.
    
    // Home page / Phone form
    $r->addRoute('GET', '/', 'FormController@showPhoneForm');
    $r->addRoute('POST', '/', 'FormController@handlePhoneForm');

    // OTP form
    $r->addRoute('GET', '/otp', 'OtpController@showOtpForm');
    $r->addRoute('POST', '/otp', 'OtpController@handleOtpForm');

    // Thank you page
    $r->addRoute('GET', '/thanks', 'ThankYouController@showThankYouPage');
};