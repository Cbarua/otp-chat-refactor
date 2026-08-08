<?php

declare(strict_types=1);

namespace Tests\NoJs;

use Tests\Support\NoJsTester;
use Tests\Support\TestLogHelper;
use PHPUnit\Framework\Assert;

final class RegistrationFlowCest
{
    private const ERROR_OTP_INVALID = 'Invalid OTP. Please enter the correct OTP.';
    private const ERROR_OTP_NEW = 'Please try again with the new OTP sent to your phone.';
    private const ERROR_CSRF = 'Security check failed. Please try again.';
    private const ERROR_RATE_LIMIT = 'Too many attempts. Please try again later.';

    private const MAGIC_PHONE = '0760123456';
    private const MAGIC_ALREADY_REGISTERED_PHONE = '0761234567';
    private const MAGIC_OTP = '999999';

    /**
     * Mock API Gateways used for testing fallback scenarios.
     * 
     * The mock API controller (MockApiController) checks the path suffix:
     * - Gateways containing 'fail' (e.g. /fail1, /fail2) simulate system-level errors (E1000) on getOtp requests.
     * - Gateways containing 'success' (e.g. /success, /success2) simulate a healthy gateway that successfully generates OTP tokens (S1000).
     */
    private const MOCK_URL_FAIL_GATEWAY_1 = 'http://localhost:8081/fail1';
    private const MOCK_URL_FAIL_GATEWAY_2 = 'http://localhost:8081/fail2';
    private const MOCK_URL_SUCCESS_GATEWAY_1 = 'http://localhost:8081/success';
    private const MOCK_URL_SUCCESS_GATEWAY_2 = 'http://localhost:8081/success2';
    // This gateway forwards requests directly to the test telco API server (telco.api) to simulate real carrier behaviors.
    private const MOCK_URL_TEST_API = 'http://localhost:8081/testapi';

    private static bool $logsCleared = false;
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/app.php';
    }

    public function _before(NoJsTester $I): void
    {
        $I->amOnPage('/');
        
        $logDir = TestLogHelper::getLogDir();
        $I->setCookie('APP_ENV', 'testing');
        $_COOKIE['APP_ENV'] = 'testing';
        $I->setCookie('TEST_LOG_DIR', $logDir);
        $_COOKIE['TEST_LOG_DIR'] = $logDir;
        $I->setCookie('TEST_CLASS_NAME', 'RegistrationFlowCest');
        $_COOKIE['TEST_CLASS_NAME'] = 'RegistrationFlowCest';

        // Override standard IDEAMART_URLS configuration by setting a cookie.
        // This makes the fallback gateways explicit and consistent across test runs,
        // rather than relying on external .env.test configuration.
        //
        // Try sequence: Fail Gateway 1 (fails) -> Fail Gateway 2 (fails) -> Success Gateway 1 (succeeds) -> Success Gateway 2 (succeeds/backup).
        $customUrls = json_encode([
            self::MOCK_URL_FAIL_GATEWAY_1,
            self::MOCK_URL_FAIL_GATEWAY_2,
            self::MOCK_URL_SUCCESS_GATEWAY_1,
            self::MOCK_URL_SUCCESS_GATEWAY_2,
        ]);
        $I->setCookie('TEST_IDEAMART_URLS', rawurlencode($customUrls));
        $_COOKIE['TEST_IDEAMART_URLS'] = $customUrls;

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
        $files = TestLogHelper::getLogFiles('RegistrationFlowCest');
        $logFiles = [
            $files['app_full'],
            $files['capi_full'],
            $files['mock_api'],
        ];

        foreach ($logFiles as $file) {
            $timestamp = date('Y-m-d H:i:s');
            $separator = "\n" . str_repeat('=', 80) . "\n";
            $message = "{$separator}=== NO-JS ACCEPTANCE TEST START ===\nTime: {$timestamp}{$separator}";
            @file_put_contents($file, $message);
        }
    }

    private function logTestStart(NoJsTester $I, string $description): void
    {
        $separator = "\n" . str_repeat('=', 80) . "\n";
        $timestamp = date('Y-m-d H:i:s');
        $message = "{$separator}{$description}\nTime: {$timestamp}{$separator}";

        $files = TestLogHelper::getLogFiles('RegistrationFlowCest');
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

    public function testRateLimitFallback(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test Rate Limit Fallback (Exhaust Limit -> New OTP -> Success)');

        $I->amOnPage('/');
        $I->setCookie('DISABLE_SMS_FALLBACK', 'true');

        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->seeInCurrentUrl('/otp');

        // Exhaust rate limit (5 attempts)
        for ($i = 1; $i <= 6; $i++) {
            $I->fillField('otp', '12345' . $i);
            $I->click('Verify');

            if ($i == 6) {
                break;
            }

            $I->seeInCurrentUrl('/otp');
        }

        $I->seeInCurrentUrl('/otp');
        $I->see(self::ERROR_OTP_NEW, '.alert-danger');

        $I->fillField('otp', self::MAGIC_OTP);
        $I->click('Verify');
        $I->seeInCurrentUrl('/thanks');
    }

    public function testSuccessfulPathWithFallback(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test the complete successful registration flow');

        $I->amOnPage('/');
        $I->see('ඔබගේ දුරකතන අංකය පහතින් ඇතුළත් කරන්න');

        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->seeInCurrentUrl('/otp');
        $I->see('PIN අංකය ඇතුළත් කරන්න');

        $I->fillField('otp', '999999');
        $I->click('Verify');

        $I->seeInCurrentUrl('/thanks');
        $I->see('ඔබගේ ලියාපදිංචිය තහවුරු කිරීමට');
    }

    public function testOtpFallback(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test OTP verification fallback (Fail -> New OTP -> Success)');

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->seeInCurrentUrl('/otp');
        $I->see('PIN අංකය ඇතුළත් කරන්න');

        $I->fillField('otp', '000000');
        $I->click('Verify');

        $I->seeInCurrentUrl('/otp');
        $I->see(self::ERROR_OTP_NEW, '.alert-danger');

        $I->fillField('otp', self::MAGIC_OTP);
        $I->click('Verify');
        $I->seeInCurrentUrl('/thanks');
    }

    public function testSmsFallbackAfterThreeInvalidAttempts(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test SMS Fallback after 3 invalid attempts');

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->seeInCurrentUrl('/otp');

        for ($i = 1; $i <= 3; $i++) {
            $I->fillField('otp', '11111' . $i);
            $I->click('Verify');
            $I->seeInCurrentUrl('/otp');
        }

        $I->seeElement('#smsLink');
        $I->see('PIN අංකය නැද්ද? පහල බොත්තම ඔබලා සෙන්ඩ් කරන්න');
        $I->dontSeeElement('input[name="otp"]');
    }

    public function testServerSidePhoneValidation(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test the server-side phone validation');
        $I->amOnPage('/');

        // Without JS, submitting raw invalid value triggers server validation
        $I->fillField('mobile', 'bad-data');
        $I->click('Register');

        $I->seeInCurrentUrl('/');
        $I->see(
            'Invalid phone number. Example: 0771234567',
            '.alert-danger'
        );
    }

    public function testCarrierSpecificPhoneValidation(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test the carrier-specific phone validation');
        $I->amOnPage('/');

        $I->fillField('mobile', '0731234567');
        $I->click('Register');

        $I->seeInCurrentUrl('/');
        $I->see(
            'Invalid phone number. Example: 0771234567',
            '.alert-danger'
        );

        $I->fillField('mobile', '0771234567');
        $I->click('Register');

        $I->seeInCurrentUrl('/otp');
    }

    public function testInvalidOtp(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test a failed OTP submission');

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->seeInCurrentUrl('/otp');

        $I->fillField('otp', '111111');
        $I->click('Verify');

        $I->seeInCurrentUrl('/otp');
        $I->see(
            self::ERROR_OTP_INVALID,
            '.alert-danger'
        );
    }

    public function testDirectPageAccess(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test that /otp and /thanks are protected');

        $I->amOnPage('/otp');
        $I->dontSee('PIN අංකය ඇතුළත් කරන්න');
        $I->seeInCurrentUrl('/');

        $I->amOnPage('/thanks');
        $I->dontSee('ඔබගේ ලියාපදිංචිය තහවුරු කිරීමට');
        $I->seeInCurrentUrl('/');
    }

    public function testCsrfProtection(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test CSRF protection on phone form');
        $I->amOnPage('/');

        // Submit form with tampered/invalid csrf_token
        $I->submitForm('#leadForm', [
            'csrf_token' => 'invalid_token',
            'mobile' => self::MAGIC_PHONE
        ]);

        $I->seeInCurrentUrl('/');
        $I->see(self::ERROR_CSRF, '.alert-danger');
    }

    public function testRateLimiting(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test rate limiting on phone form');

        for ($i = 0; $i < 10; $i++) {
            $I->amOnPage('/');
            $I->fillField('mobile', self::MAGIC_PHONE);
            $I->click('Register');

            $html = $I->grabPageSource();
            if (strpos($html, self::ERROR_RATE_LIMIT) !== false) {
                $I->see(self::ERROR_RATE_LIMIT, '.alert-danger');
                return;
            }
        }

        $I->fail('Failed to trigger rate limiter after 10 attempts.');
    }

    public function testPreventsRedundantOtpRequest(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test that redundant OTP requests are prevented');

        $files = TestLogHelper::getLogFiles('RegistrationFlowCest');
        if (!file_exists($files['mock_api'])) {
            file_put_contents($files['mock_api'], '');
        }

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');
        $I->seeInCurrentUrl('/otp');

        $count1 = $this->countMockApiRequests();

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');
        $I->seeInCurrentUrl('/otp');

        $count2 = $this->countMockApiRequests();
        Assert::assertEquals($count1, $count2, 'Should NOT have made a new API request');
    }

    public function testExpiredOtpRenewal(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test expired OTP auto-renewal');

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->fillField('otp', '777777');
        $I->click('Verify');

        $I->seeInCurrentUrl('/otp');
        $I->see('Your OTP expired. A new OTP has been sent to your phone.', '.alert-danger');

        $I->fillField('otp', self::MAGIC_OTP);
        $I->click('Verify');
        $I->seeInCurrentUrl('/thanks');
    }

    public function testAlreadyRegisteredUser(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test registration flow for an already registered user (Hutch)');

        // Override standard fallback gateways list with the testapi gateway.
        // This will forward request to mock telco.api which maps 0781234563 to already registered.
        $customUrls = json_encode([self::MOCK_URL_TEST_API]);
        $I->setCookie('TEST_IDEAMART_URLS', rawurlencode($customUrls));
        $_COOKIE['TEST_IDEAMART_URLS'] = $customUrls;

        $I->amOnPage('/');
        $I->fillField('mobile', '0781234563');
        $I->click('Register');

        // Should stay on the home page and show the warning message
        $I->seeInCurrentUrl('/');
        $I->see('You are already registered!', '#alreadyRegistered');
    }

    public function testTemporarySystemError(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test registration flow for a temporary system error (Hutch)');

        // Override standard fallback gateways list with the testapi gateway.
        // This will forward request to mock telco.api which maps 0781234564 to temporary system error.
        $customUrls = json_encode([self::MOCK_URL_TEST_API]);
        $I->setCookie('TEST_IDEAMART_URLS', rawurlencode($customUrls));
        $_COOKIE['TEST_IDEAMART_URLS'] = $customUrls;

        $I->amOnPage('/');
        $I->fillField('mobile', '0781234564');
        $I->click('Register');

        // Should redirect directly to the OTP page showing the SMS fallback link
        $I->seeInCurrentUrl('/otp');
        $I->seeElement('#smsLink');
        $I->see('PIN අංකය නැද්ද? පහල බොත්තම ඔබලා සෙන්ඩ් කරන්න');
    }

    public function testCsrfProtectionOnOtpForm(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test CSRF protection on OTP verification form');

        // Go through the normal registration flow first to get a valid session
        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');
        $I->seeInCurrentUrl('/otp');

        // Submit the form with an invalid csrf_token
        $I->submitForm('.form-section form', [
            'csrf_token' => 'invalid_token',
            'otp' => '111111'
        ]);

        // Should redirect back to /otp page showing CSRF error alert
        $I->seeInCurrentUrl('/otp');
        $I->see(self::ERROR_CSRF, '.alert-danger');
    }

    public function testOtpNotFoundTriggersSmsFallback(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test OTP not found (444444) triggers SMS fallback when SMS config is set');

        $customUrls = json_encode([self::MOCK_URL_TEST_API]);
        $I->setCookie('TEST_IDEAMART_URLS', rawurlencode($customUrls));
        $_COOKIE['TEST_IDEAMART_URLS'] = $customUrls;

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->seeInCurrentUrl('/otp');

        $I->fillField('otp', '444444');
        $I->click('Verify');

        $I->seeInCurrentUrl('/otp');
        $I->seeElement('#smsLink');
    }

    public function testOtpNotFoundTriggersRenewalWithoutSmsConfig(NoJsTester $I): void
    {
        $this->logTestStart($I, 'Test OTP not found (444444) triggers renewal when SMS config is not set (DISABLE_SMS_FALLBACK=true)');

        $customUrls = json_encode([self::MOCK_URL_TEST_API]);
        $I->setCookie('TEST_IDEAMART_URLS', rawurlencode($customUrls));
        $_COOKIE['TEST_IDEAMART_URLS'] = $customUrls;

        // Disable SMS fallback using cookie
        $I->setCookie('DISABLE_SMS_FALLBACK', 'true');
        $_COOKIE['DISABLE_SMS_FALLBACK'] = 'true';

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->seeInCurrentUrl('/otp');

        $I->fillField('otp', '444444');
        $I->click('Verify');

        $I->seeInCurrentUrl('/otp');
        $I->see('Your OTP expired. A new OTP has been sent to your phone.', '.alert-danger');
        $I->dontSeeElement('#smsLink');

        // Reset cookie for subsequent tests
        $I->resetCookie('DISABLE_SMS_FALLBACK');
        unset($_COOKIE['DISABLE_SMS_FALLBACK']);
    }

    private function countMockApiRequests(): int
    {
        $files = \Tests\Support\TestLogHelper::getLogFiles('RegistrationFlowCest');
        $logPath = $files['mock_api'];
        if (!file_exists($logPath)) {
            return 0;
        }
        $content = file_get_contents($logPath);
        return substr_count($content, 'Request Processed');
    }
}
