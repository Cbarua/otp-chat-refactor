<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;
use Tests\Support\TestLogHelper;

final class AnalyticsCest
{
    private const MAGIC_PHONE = '0760123456';
    private const MAGIC_OTP = '999999';

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
        $I->setCookie('TEST_CLASS_NAME', 'AnalyticsCest');
        $_COOKIE['TEST_CLASS_NAME'] = 'AnalyticsCest';
        
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
        $files = TestLogHelper::getLogFiles('AnalyticsCest');
        $logFiles = [
            $files['app_full'],
            $files['capi_full'],
            $files['mock_api'],
        ];

        foreach ($logFiles as $file) {
            $timestamp = date('Y-m-d H:i:s');
            $separator = "\n" . str_repeat('=', 80) . "\n";
            $message = "{$separator}=== ANALYTICS TEST START ===\nTime: {$timestamp}{$separator}";
            @file_put_contents($file, $message);
        }
    }

    private function logTestStart(AcceptanceTester $I, string $description): void
    {
        $separator = "\n" . str_repeat('=', 80) . "\n";
        $timestamp = date('Y-m-d H:i:s');
        $message = "{$separator}{$description}\nTime: {$timestamp}{$separator}";

        $files = TestLogHelper::getLogFiles('AnalyticsCest');
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

    // --- Facebook Pixel Firing Tests ---
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

    public function testPixelsOnOtpForm(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test Facebook Pixel firing on the OTP form');

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->wait(3);
        $I->seeInCurrentUrl('/otp');

        $pixelId = $this->config['facebook']['pixel_id'] ?? null;

        if (empty($pixelId)) {
            $I->dontSeeInSource("fbq('track', 'PageView'");
            $I->dontSeeInSource("fbq('track', 'Lead'");
        } else {
            $I->seeInSource("external_id: 'v_");
            $I->seeInSource("country: 'lk'");

            // Check that the PageView event for this page is rendered
            $I->seeInSource("fbq('track', 'PageView'");
            $I->seeInSource("eventID: 'pgview-otp-");

            // Check that the Lead event is rendered
            $I->seeInSource("fbq('track', 'Lead'");
            $I->seeInSource("eventID: 'lead-");
        }
    }

    public function testPixelsOnThanksPage(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test Facebook Pixel firing on the Thank You page');

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->wait(3);
        $I->seeInCurrentUrl('/otp');
        $I->fillField('otp', self::MAGIC_OTP);
        $I->click('Verify');

        $I->wait(3);
        $I->seeInCurrentUrl('/thanks');

        $pixelId = $this->config['facebook']['pixel_id'] ?? null;

        if (empty($pixelId)) {
            $I->dontSeeInSource("fbq('track', 'CompleteRegistration'");
            $I->dontSeeInSource("fbq('track', 'PageView'");
        } else {
            $I->seeInSource("external_id: 'v_");
            $I->seeInSource("country: 'lk'");

            // Check that the CompleteRegistration event is rendered
            $I->seeInSource("fbq('track', 'CompleteRegistration'");
            $I->seeInSource("eventID: 'reg-");

            // Check that a PageView event is fired
            $I->seeInSource("fbq('track', 'PageView'");
            $I->seeInSource("eventID: 'pgview-thanks-");
        }
    }

    // --- Google Analytics Firing Tests ---
    public function testGoogleAnalyticsOnPhoneForm(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test Google Analytics firing on the phone form');
        $I->amOnPage('/');

        $gaMeasurementId = $this->config['google']['ga_measurement_id'] ?? null;

        if (empty($gaMeasurementId)) {
            $I->dontSeeInSource("gtag('config', '$gaMeasurementId');");
        } else {
            $I->seeInSource("gtag('config', '$gaMeasurementId');");

            // Test begin_registration event code presence
            $I->seeInSource("gtag('event', 'begin_registration'");
            $I->seeInSource("'event_label': 'phone_submitted_ajax'");

            // Test form_error event code presence
            $I->seeInSource("gtag('event', 'form_error'");
            $I->seeInSource("'event_label': 'client_side_phone_error'");
        }
    }

    public function testGoogleAnalyticsOnOtpForm(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test Google Analytics firing on the OTP form');

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->wait(3);
        $I->seeInCurrentUrl('/otp');

        $gaMeasurementId = $this->config['google']['ga_measurement_id'] ?? null;

        if (empty($gaMeasurementId)) {
            $I->dontSeeInSource("gtag('config', '$gaMeasurementId');");
        } else {
            $I->seeInSource("gtag('config', '$gaMeasurementId');");

            // Test submit_otp event on form submission
            $I->seeInSource("onsubmit=\"gtag('event', 'submit_otp'");
            $I->seeInSource("'event_label': 'otp_submitted'");
        }
    }

    public function testGoogleAnalyticsOnSmsLinkClick(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test Google Analytics event on SMS link click');

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');
        $I->wait(3);

        $I->seeInCurrentUrl('/otp');

        // Submit invalid OTP 3 times to show SMS link
        for ($i = 1; $i <= 3; $i++) {
            $I->fillField('otp', '11112' . $i);
            $I->click('Verify');
            $I->wait(3);
        }

        $I->seeElement('#smsLink');

        // Verify GA event tracking code is present in the onclick attribute
        $I->seeInSource("gtag('event', 'sms_link_click'");
        $I->seeInSource("'event_category': 'engagement'");
        $I->seeInSource("'event_label': 'sms_fallback_link'");
    }

    public function testGoogleAnalyticsOnThanksPage(AcceptanceTester $I): void
    {
        $this->logTestStart($I, 'Test Google Analytics firing on the Thank You page');

        $I->amOnPage('/');
        $I->fillField('mobile', self::MAGIC_PHONE);
        $I->click('Register');

        $I->wait(3);
        $I->seeInCurrentUrl('/otp');
        $I->fillField('otp', self::MAGIC_OTP);
        $I->click('Verify');

        $I->wait(3);
        $I->seeInCurrentUrl('/thanks');

        $gaMeasurementId = $this->config['google']['ga_measurement_id'] ?? null;

        if (empty($gaMeasurementId)) {
            $I->dontSeeInSource("gtag('config', '$gaMeasurementId');");
        } else {
            $I->seeInSource("gtag('config', '$gaMeasurementId');");

            // Test generate_lead event
            $I->seeInSource("gtag('event', 'generate_lead'");
            $I->seeInSource("'event_label': 'registration_complete'");
        }
    }
}
