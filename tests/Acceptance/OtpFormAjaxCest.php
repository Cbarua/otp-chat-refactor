<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\TestLogHelper;

final class OtpFormAjaxCest
{
    private static bool $logsCleared = false;
    private array $config;

    public function __construct()
    {
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
        $I->setCookie('TEST_CLASS_NAME', 'OtpFormAjaxCest');
        $_COOKIE['TEST_CLASS_NAME'] = 'OtpFormAjaxCest';

        // Set custom IDEAMART_URLS via cookie
        $customUrls = json_encode([
            "http://localhost:8081/testapi",
        ]);
        $I->setCookie('TEST_IDEAMART_URLS', rawurlencode($customUrls));
        $_COOKIE['TEST_IDEAMART_URLS'] = $customUrls;

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
        $files = TestLogHelper::getLogFiles('OtpFormAjaxCest');
        $logFiles = [
            $files['app_full'],
            $files['capi_full'],
            $files['mock_api'],
        ];

        foreach ($logFiles as $file) {
            $timestamp = date('Y-m-d H:i:s');
            $separator = "\n" . str_repeat('=', 80) . "\n";
            $message = "{$separator}=== OTP ACCEPTANCE TEST START ===\nTime: {$timestamp}{$separator}";
            @file_put_contents($file, $message);
        }
    }

    private function logTestStart(AcceptanceTester $I, string $description): void
    {
        $separator = "\n" . str_repeat('=', 80) . "\n";
        $timestamp = date('Y-m-d H:i:s');
        $message = "{$separator}{$description}\nTime: {$timestamp}{$separator}";

        $files = TestLogHelper::getLogFiles('OtpFormAjaxCest');
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

    private function navigateToOtpForm(AcceptanceTester $I, string $phone = '0781234569'): void
    {
        $I->amOnPage('/');
        $I->fillField('mobile', $phone);
        $I->click('Register');
        $I->wait(3);
        $I->seeInCurrentUrl('/otp');
    }

    public function testDirectAccessToOtpWithoutTokenRedirectsToHome(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test direct access to /otp without session token redirects to home (/)');
        $I->amOnPage('/otp');
        $I->seeInCurrentUrl('/');
        $I->seeElement('input[name="mobile"]');
    }

    public function testClickRequestPinAgainLinkNavigatesToPhoneForm(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test clicking "Request PIN Again" link navigates to phone form (/)');
        $this->navigateToOtpForm($I);

        $I->click('මෙතන ඔබන්න.');
        $I->wait(1);

        $I->seeInCurrentUrl('/');
        $I->seeElement('input[name="mobile"]');
        $I->seeElement('input[type="submit"]', ['value' => 'Register']);
    }

    public function testInvalidOtpShowsError(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test OTP form submission with invalid OTP shows error. Error do NOT persist when navigating back to phone form (/)');
        $this->navigateToOtpForm($I);

        // Submit invalid OTP to trigger error display
        $I->fillField('otp', '123456');
        $I->click('Verify');
        $I->wait(2);

        $I->see('Invalid OTP. Please enter the correct OTP.', '#otpError');

        // Click link to return to phone form
        $I->click('මෙතන ඔබන්න.');
        $I->wait(1);

        $I->seeInCurrentUrl('/');
        $I->dontSeeElement('#phoneError', ['style' => 'display: block;']);
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);
    }

    public function testOtpFormCsrfProtection(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test OTP form submission with invalid CSRF token shows error. Error do NOT persist when navigating back to phone form (/)');
        $this->navigateToOtpForm($I);

        $I->executeJS("document.querySelector('input[name=\"csrf_token\"]').value = 'invalid_token_123';");
        $I->fillField('otp', '999999');
        $I->click('Verify');

        $I->wait(1);

        $I->see('Security check failed. Please try again.', '#otpError');
        $I->wait(2);
        $I->seeInCurrentUrl('/otp');

        // Click link to return to phone form
        $I->click('මෙතන ඔබන්න.');
        $I->wait(1);

        $I->seeInCurrentUrl('/');
        $I->dontSeeElement('#phoneError', ['style' => 'display: block;']);
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);
    }

    public function testOtpFormInvalidOtpAttemptsAndSmsFallback(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test 3 invalid OTP attempts triggering SMS fallback link. SMS fallback do NOT persist when navigating back to phone form (/)');
        $this->navigateToOtpForm($I);

        // Attempt 1
        $I->fillField('otp', '123456');
        $I->click('Verify');
        $I->wait(2);
        $I->see('Invalid OTP. Please enter the correct OTP.', '#otpError');

        // Attempt 2
        $I->fillField('otp', '123456');
        $I->click('Verify');
        $I->wait(2);
        $I->see('Invalid OTP. Please enter the correct OTP.', '#otpError');

        // Attempt 3 -> Triggers SMS fallback
        $I->fillField('otp', '123456');
        $I->click('Verify');
        $I->wait(3);

        // Should reload and render SMS Fallback link
        $I->seeElement('#smsLink');

        // Navigating to / should clear SMS fallback state
        $I->click('මෙතන ඔබන්න.');
        $I->wait(1);
        $I->seeInCurrentUrl('/');
        $I->dontSeeElement('#smsLink');
    }

    public function testOtpFormCouldNotFindOtpTriggersSmsFallback(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test "Could not find OTP" (444444) triggers SMS fallback link');
        $this->navigateToOtpForm($I);

        $I->fillField('otp', '444444');
        $I->click('Verify');
        $I->wait(3);

        $I->seeElement('#smsLink');
    }

    public function testOtpFormCouldNotFindOtpTriggersRenewalWithoutSmsConfig(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test "Could not find OTP" (444444) triggers OTP renewal when SMS config is not set');

        // Disable SMS fallback using cookie
        $I->setCookie('DISABLE_SMS_FALLBACK', 'true');
        $_COOKIE['DISABLE_SMS_FALLBACK'] = 'true';

        $this->navigateToOtpForm($I);

        $I->fillField('otp', '444444');
        $I->click('Verify');
        $I->wait(3);

        // Should see expired OTP renewal error message instead of SMS fallback link
        $I->dontSeeElement('#smsLink');
        $I->see('Your OTP expired. A new OTP has been sent to your phone.', '#otpError');

        // Reset cookie for subsequent tests
        $I->resetCookie('DISABLE_SMS_FALLBACK');
        unset($_COOKIE['DISABLE_SMS_FALLBACK']);
    }

    public function testOtpFormExpiredOtpAutoRenewal(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test expired OTP (111111) auto-renewal message. Message do NOT persist when navigating back to phone form (/)');
        $this->navigateToOtpForm($I);

        $I->fillField('otp', '111111');
        $I->click('Verify');
        $I->wait(3);

        $I->see('Your OTP expired. A new OTP has been sent to your phone.', '#otpError');

        // Navigating to / should clear expired OTP state
        $I->click('මෙතන ඔබන්න.');
        $I->wait(1);
        $I->seeInCurrentUrl('/');
        $I->dontSeeElement('#phoneError', ['style' => 'display: block;']);
        $I->dontSeeElement('#alreadyRegistered', ['style' => 'display: block;']);
    }

    public function testOtpFormSuccessfulVerification(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test successful OTP verification (999999) redirects to thank you page');
        $this->navigateToOtpForm($I);

        $I->fillField('otp', '999999');
        $I->click('Verify');
        $I->wait(3);

        $I->seeInCurrentUrl('/thanks');
    }
}
