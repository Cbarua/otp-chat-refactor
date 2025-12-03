<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

final class RegistrationFlowCest
{
    private const ERROR_OTP_INVALID = 'Invalid OTP. Please enter the correct OTP.';
    private const ERROR_OTP_NEW = 'Please try again with the new OTP sent to your phone.';
    private const ERROR_CSRF = 'Security check failed. Please try again.';
    private const ERROR_RATE_LIMIT = 'Too many attempts. Please try again later.';

    private const MAGIC_PHONE = '0760123456';
    private const MAGIC_ALREAY_REGISTERED_PHONE = '0761234567';
    private const MAGIC_OTP = '999999';

    private static bool $logsCleared = false;
    private array $config;

    public function __construct()
    {
        // Load config to check for pixel ID
        $this->config = require __DIR__ . '/../../config/app.php';
    }

    public function _before(AcceptanceTester $I): void
    {
        // 1. On the home page
        $I->amOnPage('/');

        // Set cookie to tell the app to use .env.test environment
        // Only can set cookie in browser
        $I->setCookie('APP_ENV', 'testing');

        // Reload config to ensure we have the test environment settings
        $_COOKIE['APP_ENV'] = 'testing';
        $this->config = require __DIR__ . '/../../config/app.php';

        // Clear logs only once before the first test
        if (!self::$logsCleared) {
            $this->clearLogFiles();
            self::$logsCleared = true;
        }

        // Reset rate limits before each test
        $this->resetRateLimits();
    }

    private function resetRateLimits(): void
    {
        $dbPath = __DIR__ . '/../../logs/userlog.sqlite';
        if (file_exists($dbPath)) {
            try {
                $db = new \SQLite3($dbPath);
                $db->busyTimeout(5000); // Wait up to 5 seconds for lock
                $db->exec("DELETE FROM rate_limits");
                $db->close();
            } catch (\Exception $e) {
                // Ignore DB errors during test cleanup
            }
        }
    }

    private function clearLogFiles(): void
    {
        $today = date('Y-m-d');
        $logFiles = [
            __DIR__ . "/../../logs/app/app-{$today}.log",
            __DIR__ . "/../../logs/capi/capi-{$today}.log",
            __DIR__ . "/../../logs/mock_api.log",
        ];

        foreach ($logFiles as $file) {
            if (file_exists($file)) {
                // Create the plain text separator message
                $timestamp = date('Y-m-d H:i:s');
                $separator = "\n" . str_repeat('=', 80) . "\n";
                $message = "{$separator}=== ACCEPTANCE TEST START ===\nTime: {$timestamp}{$separator}";
                @file_put_contents($file, $message); // Clear today's log file
            }
        }
    }

    private function logTestStart(AcceptanceTester $I, string $description): void
    {
        $separator = "\n" . str_repeat('=', 80) . "\n";
        $timestamp = date('Y-m-d H:i:s');
        $message = "{$separator}{$description}\nTime: {$timestamp}{$separator}";

        $today = date('Y-m-d');
        $logFiles = [
            __DIR__ . "/../../logs/app/app-{$today}.log",
            __DIR__ . "/../../logs/capi/capi-{$today}.log",
            __DIR__ . "/../../logs/mock_api.log",
        ];

        foreach ($logFiles as $file) {
            if (file_exists($file)) {
                @file_put_contents($file, $message, FILE_APPEND);
            }
        }

        // Also call the Codeception wantTo method
        $I->wantTo($description);
    }

    public function testRateLimitFallback(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test Rate Limit Fallback (Exhaust Limit -> New OTP -> Success)');

        // 1. On the home page
        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');
        $I->wait(5); // Wait for API

        // 2. On OTP page
        $I->seeInCurrentUrl('/otp');

        // 3. Exhaust rate limit (5 attempts)
        // The controller checks rate limit BEFORE processing.
        // So 5 failed attempts means the NEXT one (6th) should be blocked/fallback.
        for ($i = 1; $i <= 6; $i++) {
            $I->fillField('otp', '12345' . $i); // Invalid OTPs
            $I->click('Verify');

            if ($i == 6)
                break;
            $I->seeInCurrentUrl('/otp');
        }

        // 4. The 6th attempt should trigger fallback because rate limit is exceeded
        // Wait for fallback API call
        $I->wait(5);

        // 5. Should be back on OTP page with "New OTP" message
        $I->seeInCurrentUrl('/otp');
        $I->see(self::ERROR_OTP_NEW, '.alert-danger');

        // 6. Verify that we can now complete the flow with the correct OTP
        // The rate limit should have been cleared.
        $I->fillField('otp', self::MAGIC_OTP);
        $I->click('Verify');
        $I->wait(3);
        $I->seeInCurrentUrl('/thanks');
    }

    public function testSuccessfulPathWithFallback(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test the complete successful registration flow');

        // 1. On the home page
        $I->amOnPage('/');
        $I->see('ඔබගේ දුරකතන අංකය පහතින් ඇතුළත් කරන්න');

        // 2. Submit valid phone (magic test number)
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        // Wait for the redirection to happen (API call takes time)
        $I->wait(3);

        // 3. On OTP page
        $I->seeInCurrentUrl('/otp');
        $I->see('PIN අංකය ඇතුළත් කරන්න');

        // 4. Submit the magic test OTP
        // NOTE: This assumes your backend has a "magic" OTP for testing.
        // If not, this test will fail.
        $I->fillField('otp', '999999');
        $I->click('Verify');

        // Wait for the redirection to happen (API call takes time)
        $I->wait(3);

        // 5. On Thanks page
        $I->seeInCurrentUrl('/thanks');
        $I->see('ඔබගේ ලියාපදිංචිය තහවුරු කිරීමට');
    }

    public function testOtpFallback(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test OTP verification fallback (Fail -> New OTP -> Success)');

        // 1. On the home page
        $I->amOnPage('/');

        // 2. Submit valid phone
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        // Wait for the redirection (API calls: fail1 -> fail2 -> success)
        $I->wait(5);

        // 3. Should eventually land on OTP page
        $I->seeInCurrentUrl('/otp');
        $I->see('PIN අංකය ඇතුළත් කරන්න');

        // 4. Enter '000000' to trigger simulated system error
        $I->fillField('otp', '000000');
        $I->click('Verify');

        // Wait for fallback logic (it should try other URLs and get a new OTP)
        $I->wait(5);

        // 5. Should be back on OTP page with "New OTP" message
        $I->seeInCurrentUrl('/otp');
        $I->see(self::ERROR_OTP_NEW, '.alert-danger');

        // 6. Verify that we can now complete the flow with the correct OTP
        $I->fillField('otp', self::MAGIC_OTP);
        $I->click('Verify');
        $I->wait(3);
        $I->seeInCurrentUrl('/thanks');
    }

    // --- JavaScript Validation Tests ---

    public function testClientSidePhoneValidation(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test the client-side JavaScript phone validation');
        $I->amOnPage('/');

        // 1. Submit an invalid phone number
        $I->fillField('mobile', '1234567890');
        $I->click('Register');

        // 2. Assert the JS error message is visible
        $I->see(
            'වලංගු ජංගම දුරකථන අංකය ඇතුළත් කරන්න. උදා : 0772221234',
            '#phoneError'
        );
        $I->seeElement('#phoneError', ['style' => 'display: block;']);

        // 3. Assert we are still on the home page (JS prevented submission)
        $I->seeInCurrentUrl('/');
        $I->dontSee('PIN අංකය ඇතුළත් කරන්න');
    }

    public function testServerSidePhoneValidation(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test the server-side phone validation');
        $I->amOnPage('/');

        // 1. Bypass client-side JS check by setting value directly
        // and submitting the form programmatically.
        $I->executeJS(
            'document.getElementById("mobile").value = "bad-data";
             document.getElementById("leadForm").submit();'
        );

        // 2. Assert we are redirected back to /
        $I->seeInCurrentUrl('/');

        // 3. Assert we see the SERVER-SIDE error message
        $I->see(
            'Invalid phone number. Example: 0771234567',
            '.alert-danger'
        );
    }

    public function testInvalidOtp(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test a failed (non-magic) OTP submission');

        // Step 1: Get to the OTP page
        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        // Wait for the redirection to happen (API call takes time)
        $I->wait(3);
        $I->seeInCurrentUrl('/otp');

        // Step 2: Submit a bad OTP
        // This assumes '111111' is not a magic test OTP.
        $I->fillField('otp', '111111');
        $I->click('Verify');

        // We stay on the OTP page
        $I->seeInCurrentUrl('/otp');

        // We see the error message
        $I->see(
            self::ERROR_OTP_INVALID,
            '.alert-danger'
        );
    }

    public function testDirectPageAccess(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test that /otp and /thanks are protected');

        $I->amOnPage('/otp');
        $I->dontSee('PIN අංකය ඇතුළත් කරන්න');
        $I->seeInCurrentUrl('/'); // Should be redirected

        $I->amOnPage('/thanks');
        $I->dontSee('ඔබගේ ලියාපදිංචිය තහවුරු කිරීමට');
        $I->seeInCurrentUrl('/'); // Should be redirected
    }

    // --- Security Tests ---

    public function testCsrfProtection(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test CSRF protection on phone form');
        $I->amOnPage('/');

        // Tamper with the CSRF token
        $I->executeJS('document.querySelector("input[name=csrf_token]").value = "invalid_token";');

        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        // Should be redirected back to / with error
        $I->seeInCurrentUrl('/');
        $I->see(self::ERROR_CSRF, '.alert-danger');
    }

    // --- Facebook Pixel Firing Tests ---
    public function testPixelsOnPhoneForm(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test Facebook Pixel firing on the phone form');
        $I->amOnPage('/');

        // 1. Check that the pixel is initialized
        $pixelId = $this->config['facebook']['pixel_id'] ?? null;

        if (empty($pixelId)) {
            $I->dontSeeInSource("fbq('init'");
            $I->dontSeeInSource("fbq('track', 'PageView'");
        } else {
            $I->seeInSource("fbq('init', '$pixelId')");
            $I->seeInSource("external_id: 'v_");
            $I->seeInSource("country: 'lk'");

            // 2. Check that the PageView event is rendered with its unique ID
            $I->seeInSource("fbq('track', 'PageView'");
            $I->seeInSource("eventID: 'pgview-");
        }
    }

    public function testPixelsOnOtpForm(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test Facebook Pixel firing on the OTP form');

        // 1. Get to the OTP page
        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        // Wait for the redirection to happen (API call takes time)
        $I->wait(3);
        $I->seeInCurrentUrl('/otp');

        $pixelId = $this->config['facebook']['pixel_id'] ?? null;

        if (empty($pixelId)) {
            $I->dontSeeInSource("fbq('track', 'PageView'");
            $I->dontSeeInSource("fbq('track', 'Lead'");
        } else {
            $I->seeInSource("external_id: 'v_");
            $I->seeInSource("country: 'lk'");

            // 2. Check that the PageView event for this page is rendered
            $I->seeInSource("fbq('track', 'PageView'");
            $I->seeInSource("eventID: 'pgview-otp-");

            // 3. Check that the Lead event is rendered
            $I->seeInSource("fbq('track', 'Lead'");
            $I->seeInSource("eventID: 'lead-");
        }
    }

    public function testPixelsOnThanksPage(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test Facebook Pixel firing on the Thank You page');

        // 1. Get to the Thanks page
        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        // Wait for the redirection to happen (API call takes time)
        $I->wait(3);
        $I->seeInCurrentUrl('/otp');
        $I->fillField('otp', self::MAGIC_OTP);
        $I->click('Verify');

        // Wait for the redirection to happen (API call takes time)
        $I->wait(3);
        $I->seeInCurrentUrl('/thanks');

        $pixelId = $this->config['facebook']['pixel_id'] ?? null;

        if (empty($pixelId)) {
            $I->dontSeeInSource("fbq('track', 'CompleteRegistration'");
            $I->dontSeeInSource("fbq('track', 'PageView'");
        } else {
            $I->seeInSource("external_id: 'v_");
            $I->seeInSource("country: 'lk'");

            // 2. Check that the CompleteRegistration event is rendered
            $I->seeInSource("fbq('track', 'CompleteRegistration'");
            $I->seeInSource("eventID: 'reg-");

            // 3. Check that a PageView event is fired
            $I->seeInSource("fbq('track', 'PageView'");
            $I->seeInSource("eventID: 'pgview-thanks-");
        }
    }

    public function testRateLimiting(AcceptanceTester $I)
    {
        $this->logTestStart($I, 'Test rate limiting on phone form');

        // Attempt to trigger the rate limiter
        // We loop enough times to ensure we hit the limit (5 per 60s)
        for ($i = 0; $i < 10; $i++) {
            $I->amOnPage('/');
            $I->fillField('mobile', self::MAGIC_PHONE);
            $I->click('Register');

            // Check if we hit the limit
            $html = $I->grabPageSource();
            if (strpos($html, self::ERROR_RATE_LIMIT) !== false) {
                $I->see(self::ERROR_RATE_LIMIT, '.alert-danger');
                return; // Success
            }
        }

        $I->fail('Failed to trigger rate limiter after 10 attempts.');
    }
}