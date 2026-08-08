<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\TestLogHelper;

final class PhoneFormAjaxCest
{
    private static bool $logsCleared = false;
    private array $config;

    public function __construct()
    {
        // Load config to check for GA or other settings
        $this->config = require __DIR__ . '/../../config/app.php';
    }

    public function _before(AcceptanceTester $I): void
    {
        $I->amOnPage('/');
        
        $logDir = TestLogHelper::getLogDir();
        $I->setCookie('APP_ENV', 'testing');
        $_COOKIE['APP_ENV'] = 'testing';
        $I->setCookie('TEST_LOG_DIR', $logDir);
        $_COOKIE['TEST_LOG_DIR'] = $logDir;
        $I->setCookie('TEST_CLASS_NAME', 'PhoneFormAjaxCest');
        $_COOKIE['TEST_CLASS_NAME'] = 'PhoneFormAjaxCest';

        // Set custom IDEAMART_URLS via cookie
        $customUrls = json_encode([
            "http://localhost:8081/testapi",
        ]);
        $I->setCookie('TEST_IDEAMART_URLS', rawurlencode($customUrls));
        $_COOKIE['TEST_IDEAMART_URLS'] = $customUrls;

        // load the .env.test file with config
        $this->config = require __DIR__ . '/../../config/app.php';

        if (!self::$logsCleared) {
            $this->clearLogFiles();
            self::$logsCleared = true;
        }

        $this->resetRateLimits();
    }

    private function resetRateLimits(): void
    {
        $dbPath = __DIR__ . '/../../logs/userlog.sqlite';
        if (file_exists($dbPath)) {
            try {
                $db = new \SQLite3($dbPath);
                $db->busyTimeout(5000);
                $db->exec("DELETE FROM rate_limits");
                $db->close();
            } catch (\Exception $e) {
                // Ignore DB errors during test cleanup
            }
        }
    }

    private function clearLogFiles(): void
    {
        $files = \Tests\Support\TestLogHelper::getLogFiles('PhoneFormAjaxCest');
        $logFiles = [
            $files['app_full'],
            $files['capi_full'],
            $files['mock_api'],
        ];

        foreach ($logFiles as $file) {
            $timestamp = date('Y-m-d H:i:s');
            $separator = "\n" . str_repeat('=', 80) . "\n";
            $message = "{$separator}=== AJAX ACCEPTANCE TEST START ===\nTime: {$timestamp}{$separator}";
            @file_put_contents($file, $message);
        }
    }

    private function logTestStart(AcceptanceTester $I, string $description): void
    {
        $separator = "\n" . str_repeat('=', 80) . "\n";
        $timestamp = date('Y-m-d H:i:s');
        $message = "{$separator}{$description}\nTime: {$timestamp}{$separator}";

        $files = \Tests\Support\TestLogHelper::getLogFiles('PhoneFormAjaxCest');
        $logFiles = [
            $files['app_full'],
            $files['capi_full'],
            $files['mock_api'],
        ];

        foreach ($logFiles as $file) {
            if (file_exists($file)) {
                @file_put_contents($file, $message, FILE_APPEND);
            }
        }

        $I->wantTo($description);
    }

    public function testPhoneFormAjaxAlreadyRegistered(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX submission for an already registered user (Hutch)');
        $I->amOnPage('/');

        // 0781234563 maps to E1351 'user already registered'
        $I->fillField('mobile', '0781234563');

        // Check initial clean state
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);
        $I->dontSeeElement('#phoneError', ['style' => 'display: block;']);

        $I->click('Register');

        // Wait for AJAX call
        $I->wait(3);

        // Page should not reload (remain on '/')
        $I->seeInCurrentUrl('/');

        // Green alert should show correct user-facing message
        $I->see('You are already registered!', '#alreadyRegistered');
        $I->dontSeeElement('#phoneError', ['style' => 'display: block;']);

        // Submit button should be enabled again
        $I->dontSeeElement('input[type="submit"][disabled]');
        $I->seeElement('input[type="submit"]', ['value' => 'Register']);

        // Verify GA events in dataLayer
        $this->assertDataLayerEvent($I, 'begin_registration', 'phone_submitted_ajax');
        $this->assertDataLayerEvent($I, 'form_error', 'already_registered');
    }

    public function testPhoneFormAjaxTemporarySystemError(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX submission for a temporary system error (Hutch)');
        $I->amOnPage('/');

        // 0781234564 maps to E1603 'temporary System Error occurred while delivering your request'
        $I->fillField('mobile', '0781234564');

        // Check initial clean state
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);
        $I->dontSeeElement('#phoneError', ['style' => 'display: block;']);

        $I->click('Register');

        // Wait for AJAX call
        $I->wait(2);

        // Remain on main page and show generic error alert
        $I->seeInCurrentUrl('/');
        $I->see('Temporary system error', '#phoneError');
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);

        // Verify GA events in dataLayer
        $this->assertDataLayerEvent($I, 'begin_registration', 'phone_submitted_ajax');
        $this->assertDataLayerEvent($I, 'form_error', 'phone_form_error');

        // Wait for redirect
        $I->wait(3);

        // Should be redirected to OTP page with SMS fallback
        $I->seeInCurrentUrl('/otp');
        $I->seeElement('#smsLink');
    }

    public function testPhoneFormAjaxMaxOtpRequestsReached(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX submission when maximum OTP requests reached (0781234560)');
        $I->amOnPage('/');

        // 0781234560 maps to E1853 'Maximum number of OTP requests reached'
        $I->fillField('mobile', '0781234560');
        $I->click('Register');

        $I->wait(3);

        $I->seeInCurrentUrl('/');
        $I->see('An error occurred. Please try again later.', '#phoneError');
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);

        // Submit button should be re-enabled
        $I->dontSeeElement('input[type="submit"][disabled]');
        $I->seeElement('input[type="submit"]', ['value' => 'Register']);
    }

    public function testPhoneFormAjaxAppNotAllowedError(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX submission when App ID is not allowed (0781234562)');
        $I->amOnPage('/');

        // 0781234562 maps to E1301 'Requested ApplicationID is not allowed'
        $I->fillField('mobile', '0781234562');
        $I->click('Register');

        $I->wait(3);

        $I->seeInCurrentUrl('/');
        $I->see('An error occurred. Please try again later.', '#phoneError');
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);

        // Submit button should be re-enabled
        $I->dontSeeElement('input[type="submit"][disabled]');
        $I->seeElement('input[type="submit"]', ['value' => 'Register']);
    }

    public function testPhoneFormAjaxCsrfProtection(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX submission with invalid CSRF token');
        $I->amOnPage('/');

        // Corrupt CSRF token in DOM
        $I->executeJS("document.querySelector('input[name=\"csrf_token\"]').value = 'invalid_csrf_token_123';");

        $I->fillField('mobile', '0781234569');
        $I->click('Register');

        $I->wait(2);

        $I->seeInCurrentUrl('/');
        $I->see('Security check failed. Please try again.', '#phoneError');
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);
    }

    public function testPhoneFormAjaxRateLimitExceeded(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX rate limit exceeded error message');
        $I->amOnPage('/');

        // Exceed rate limit (5 attempts per minute per IP) using 0781234561 (generic error, stays on /)
        for ($i = 1; $i <= 6; $i++) {
            $I->fillField('mobile', '0781234561');
            $I->click('Register');
            $I->wait(1);
        }

        $I->seeInCurrentUrl('/');
        $I->see('Too many attempts. Please try again later.', '#phoneError');
    }

    public function testPhoneFormAjaxGenericError(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX submission for a generic system error (Hutch)');
        $I->amOnPage('/');

        // 0781234561 maps to E1857 'Internal Server Error Occur'
        $I->fillField('mobile', '0781234561');
        $I->click('Register');

        // Wait for AJAX call
        $I->wait(3);

        // Remain on main page and show generic error alert
        $I->seeInCurrentUrl('/');
        $I->see('An error occurred. Please try again later.', '#phoneError');
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);

        // Submit button should be re-enabled
        $I->dontSeeElement('input[type="submit"][disabled]');
        $I->seeElement('input[type="submit"]', ['value' => 'Register']);

        // Verify GA events in dataLayer
        $this->assertDataLayerEvent($I, 'begin_registration', 'phone_submitted_ajax');
        $this->assertDataLayerEvent($I, 'form_error', 'phone_form_error');
    }

    public function testPhoneFormAjaxUnsupportedCarrier(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX submission for an unsupported carrier (LK prefix 073)');
        $I->amOnPage('/');

        // LK format passes client regex validation but fails unsupported carrier check on server
        $I->fillField('mobile', '0731234567');
        $I->click('Register');

        // Wait for AJAX call
        $I->wait(3);

        // Remain on main page and show invalid phone error alert
        $I->seeInCurrentUrl('/');
        $I->see('Invalid phone number. Example: 0771234567', '#phoneError');
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);

        // Submit button should be re-enabled
        $I->dontSeeElement('input[type="submit"][disabled]');
        $I->seeElement('input[type="submit"]', ['value' => 'Register']);

        // Verify GA events in dataLayer
        $this->assertDataLayerEvent($I, 'begin_registration', 'phone_submitted_ajax');
        $this->assertDataLayerEvent($I, 'form_error', 'phone_form_error');
    }

    public function testPhoneFormAjaxSubmitButtonLoaderState(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX submit button disabled loader state during submission');
        $I->amOnPage('/');

        // Use 0781234561 (Generic Error) so the page remains on / without instant redirection
        $I->fillField('mobile', '0781234561');

        // Click Register which starts AJAX
        $I->click('Register');

        // Immediately assert submit button is disabled and text is changed to "Please wait..."
        $I->seeElement('input[type="submit"][disabled]');
        $I->seeElement('input[type="submit"]', ['value' => 'Please wait...']);

        // Wait for AJAX call to finish
        $I->wait(3);

        // After error, button should be restored back to enabled and value "Register"
        $I->dontSeeElement('input[type="submit"][disabled]');
        $I->seeElement('input[type="submit"]', ['value' => 'Register']);
    }

    public function testPhoneFormAjaxClientSideValidationErrorEvents(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test AJAX client-side validation GA events and error message display');
        $I->amOnPage('/');
        $I->fillField('mobile', '1234567890'); // Invalid format
        $I->click('Register');

        // Should display Sinhala client-side validation error message
        $I->see('වලංගු ජංගම දුරකථන අංකයක් ඇතුළත් කරන්න. උදා : 0772221234', '#phoneError');
        $I->seeInCurrentUrl('/');

        $this->assertDataLayerEvent($I, 'form_error', 'client_side_phone_error');
    }

    public function testPixelsOnPhoneForm(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test Facebook Pixel firing on the phone form');
        $I->amOnPage('/');

        $pixelId = $this->config['facebook']['pixel_id'] ?? null;

        if (empty($pixelId)) {
            $I->dontSeeInSource("fbq('init'");
            $I->dontSeeInSource("fbq('track', 'PageView'");
        } else {
            $I->seeInSource("fbq('init', '$pixelId'");
            $I->seeInSource("external_id: 'v_");
            $I->dontSeeInSource("country: 'lk'");

            // Check that the PageView event is rendered with its unique ID
            $I->seeInSource("fbq('track', 'PageView'");
            $I->seeInSource("eventID: 'pgview-");
        }
    }

    public function testGoogleAnalyticsOnPhoneForm(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test Google Analytics firing on the phone form');
        $I->amOnPage('/');

        $gaMeasurementId = $this->config['google']['ga_measurement_id'] ?? null;

        if (empty($gaMeasurementId)) {
            $I->dontSeeInSource("gtag('config', '$gaMeasurementId');");
        } else {
            $I->seeInSource("gtag('config', '$gaMeasurementId');");
            
            // Check that gtag script is present
            $I->seeInSource("https://www.googletagmanager.com/gtag/js?id=$gaMeasurementId");
        }
    }

    private function assertDataLayerEvent(AcceptanceTester $I, string $eventName, string $eventLabel): void
    {
        $gaMeasurementId = $this->config['google']['ga_measurement_id'] ?? null;
        if (empty($gaMeasurementId)) {
            return;
        }

        $eventFired = $I->executeJS("
            return window.dataLayer.some(item => 
                item[0] === 'event' && 
                item[1] === " . json_encode($eventName) . " && 
                item[2] && 
                item[2].event_label === " . json_encode($eventLabel) . "
            );
        ");
        \PHPUnit\Framework\Assert::assertTrue($eventFired, "Should fire {$eventName} event with label {$eventLabel}");
    }
}
