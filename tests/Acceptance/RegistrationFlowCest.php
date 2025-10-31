<?php

declare(strict_types=1);

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

final class RegistrationFlowCest
{
    public function _before(AcceptanceTester $I): void
    {
        // Code here will be executed before each test.
    }

    // --- Core User Flow Tests ---

    public function testSuccessfulPath(AcceptanceTester $I)
    {
        $I->wantTo('Test the complete successful registration flow');

        // 1. On the home page
        $I->amOnPage('/');
        $I->see('ඔබගේ දුරකතන අංකය පහතින් ඇතුළත් කරන්න');

        // 2. Submit valid phone (magic test number)
        $I->fillField('mobile', '0760123456');
        $I->click('Register');

        // 3. On OTP page
        $I->seeInCurrentUrl('/otp');
        $I->see('PIN අංකය ඇතුළත් කරන්න');

        // 4. Submit the magic test OTP
        // NOTE: This assumes your backend has a "magic" OTP for testing.
        // If not, this test will fail.
        $I->fillField('otp', '999999');
        $I->click('Verify');

        // 5. On Thanks page
        $I->seeInCurrentUrl('/thanks');
        $I->see('ඔබගේ ලියාපදිංචිය තහවුරු කිරීමට');
    }

    // --- JavaScript Validation Tests ---

    public function testClientSidePhoneValidation(AcceptanceTester $I)
    {
        $I->wantTo('Test the client-side JavaScript phone validation');
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
        $I->wantTo('Test the server-side phone validation');
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
        $I->wantTo('Test a failed (non-magic) OTP submission');
        
        // Step 1: Get to the OTP page
        $I->amOnPage('/');
        $I->fillField('mobile', '0760123456');
        $I->click('Register');
        $I->seeInCurrentUrl('/otp');

        // Step 2: Submit a bad OTP
        // This assumes '111111' is not a magic test OTP.
        $I->fillField('otp', '111111');
        $I->click('Verify');

        // We stay on the OTP page
        $I->seeInCurrentUrl('/otp');
        
        // We see the error message from the API
        $I->see(
            'Invalid OTP. Please try again.',
            '.alert-danger'
        );
    }
    
    public function testDirectPageAccess(AcceptanceTester $I)
    {
        $I->wantTo('Test that /otp and /thanks are protected');

        $I->amOnPage('/otp');
        $I->dontSee('PIN අංකය ඇතුළත් කරන්න');
        $I->seeInCurrentUrl('/'); // Should be redirected

        $I->amOnPage('/thanks');
        $I->dontSee('ඔබගේ ලියාපදිංචිය තහවුරු කිරීමට');
        $I->seeInCurrentUrl('/'); // Should be redirected
    }

    // --- Facebook Pixel Firing Tests ---
    public function testPixelsOnPhoneForm(AcceptanceTester $I)
    {
        $I->wantTo('Test Facebook Pixel firing on the phone form');
        $I->amOnPage('/');
        
        // 1. Check that the pixel is initialized
        $I->seeInSource('fbq(\'init\', \'YOUR_PIXEL_ID\')');
        
        // 2. Check that the PageView event is rendered with its unique ID
        $I->seeInSource('fbq(\'track\', \'PageView\'');
        $I->seeInSource('eventID: \'pgview-'); //
    }

    public function testPixelsOnOtpForm(AcceptanceTester $I)
    {
        $I->wantTo('Test Facebook Pixel firing on the OTP form');
        
        // 1. Get to the OTP page
        $I->amOnPage('/');
        $I->fillField('mobile', '0760123456');
        $I->click('Register');
        $I->seeInCurrentUrl('/otp');

        // 2. Check that the PageView event for this page is rendered
        $I->seeInSource('fbq(\'track\', \'PageView\'');
        $I->seeInSource('eventID: \'pgview-otp-'); //

        // 3. Check that the Lead event is rendered
        $I->seeInSource('fbq(\'track\', \'Lead\'');
        $I->seeInSource('eventID: \'lead-'); //
    }
    
    public function testPixelsOnThanksPage(AcceptanceTester $I)
    {
        $I->wantTo('Test Facebook Pixel firing on the Thank You page');
        
        // 1. Get to the Thanks page
        $I->amOnPage('/');
        $I->fillField('mobile', '0760123456');
        $I->click('Register');
        $I->seeInCurrentUrl('/otp');
        $I->fillField('otp', '999999');
        $I->click('Verify');
        $I->seeInCurrentUrl('/thanks');

        // 2. Check that the CompleteRegistration event is rendered
        $I->seeInSource('fbq(\'track\', \'CompleteRegistration\'');
        $I->seeInSource('eventID: \'reg-'); //
        
        // 3. Check that a PageView event is fired
        $I->seeInSource('fbq(\'track\', \'PageView\'');
        $I->seeInSource('eventID: \'pgview-thanks-'); //
    }
}