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

    public function tryToTest(AcceptanceTester $I): void
    {
        // Write your tests here. All `public` methods will be executed as tests.
    }

    public function testSuccessfulPath(AcceptanceTester $I)
    {
        $I->wantTo('Test the complete successful registration flow');

        // 1. On the home page
        $I->amOnPage('/');
        $I->see('ඔබගේ දුරකතන අංකය පහතින් ඇතුළත් කරන්න');

        // 2. Submit valid phone
        $I->fillField('mobile', '0760123456');
        $I->click('Register');

        // 3. On OTP page
        $I->seeInCurrentUrl('/otp');
        $I->see('PIN අංකය ඇතුළත් කරන්න');

        // 4. Submit the magic test OTP
        $I->fillField('otp', '999999');
        $I->click('Verify');

        // 5. On Thanks page
        $I->seeInCurrentUrl('/thanks');
        $I->see('ඔබගේ ලියාපදිංචිය තහවුරු කිරීමට');
    }

    public function testInvalidPhone(AcceptanceTester $I)
    {
        $I->wantTo('Test invalid phone number submission');
        $I->amOnPage('/');
        $I->fillField('mobile', '12345');
        $I->click('Register');

        // We stay on the home page and see the server-side error
        $I->seeInCurrentUrl('/');
        $I->see('Invalid phone number. Example: 0771234567');
    }

    public function testInvalidOtp(AcceptanceTester $I)
    {
        $I->wantTo('Test a failed (non-magic) OTP submission');
        
        // Step 1: Get to the OTP page
        $I->amOnPage('/');
        $I->fillField('mobile', '0760123456');
        $I->click('Register');

        // Step 2: Submit a bad OTP
        $I->amOnPage('/otp');
        $I->fillField('otp', '111111');
        $I->click('Verify');

        // We stay on the OTP page and see the error from the API
        $I->seeInCurrentUrl('/otp');
        // This message depends on your OtpApiService error handling
        $I->see('An error occurred. Please try again later.'); 
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
}
