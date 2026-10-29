<?php
// tests/OtpFlowStateTest.php
declare(strict_types=1);

use App\DTO\OtpVerificationToken;
use App\DTO\PhoneNumber;
use App\Service\OtpFlowState;
use App\Service\SessionService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the session keys and stored values: sessions in flight during a deploy must stay readable.
 */
class OtpFlowStateTest extends TestCase
{
    private OtpFlowState $state;
    private PhoneNumber $phone;
    private OtpVerificationToken $token;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->state = new OtpFlowState(new SessionService());
        $this->phone = new PhoneNumber('0771234567', 'tel:94771234567', '94771234567', 'ideamart', 0.01);
        $this->token = new OtpVerificationToken('ref', 'https://api/app1', 'ideamart', time());
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testStartVerificationStepStoresPhoneTokenAndLeadId(): void
    {
        $this->state->startVerificationStep($this->phone, $this->token);

        $this->assertSame($this->phone, $_SESSION['phone_data']);
        $this->assertSame($this->token, $_SESSION['otp_token']);
        $this->assertMatchesRegularExpression('/^lead-[0-9a-f]{32}$/', $_SESSION['lead_id']);
        $this->assertArrayNotHasKey('show_sms_link', $_SESSION);
        $this->assertSame($this->token, $this->state->token());
        $this->assertTrue($this->state->inVerificationStep());
        $this->assertFalse($this->state->inSmsFallback());
    }

    public function testSmsFallbackAtRequestStepStoresTheTrueSentinel(): void
    {
        $this->state->startVerificationStep($this->phone, null);

        $this->assertTrue($_SESSION['otp_token']);
        $this->assertTrue($_SESSION['show_sms_link']);
        $this->assertNull($this->state->token());
        $this->assertTrue($this->state->inVerificationStep());
        $this->assertTrue($this->state->inSmsFallback());
        $this->assertSame($this->phone, $this->state->phone());
    }

    public function testTakeLeadIdReturnsItOnce(): void
    {
        $this->state->startVerificationStep($this->phone, $this->token);
        $leadId = $_SESSION['lead_id'];

        $this->assertSame($leadId, $this->state->takeLeadId());
        $this->assertNull($this->state->takeLeadId());
    }

    public function testRememberPhoneDoesNotStartTheVerificationStep(): void
    {
        $this->state->rememberPhone($this->phone);

        $this->assertSame($this->phone, $_SESSION['phone_data']);
        $this->assertFalse($this->state->inVerificationStep());
        $this->assertArrayNotHasKey('lead_id', $_SESSION);
    }

    public function testInvalidOtpsAndSmsFallbackUntilReset(): void
    {
        $this->state->startVerificationStep($this->phone, $this->token);

        $this->assertSame(1, $this->state->countInvalidOtp());
        $this->assertSame(2, $this->state->countInvalidOtp());
        $this->assertSame(2, $_SESSION['invalid_otp_count']);
        $this->state->setSmsFallback(false);
        $this->assertFalse($_SESSION['show_sms_link']);
        $this->state->setSmsFallback(true);
        $this->assertTrue($this->state->inSmsFallback());

        $this->state->resetAttempts();

        $this->assertArrayNotHasKey('invalid_otp_count', $_SESSION);
        $this->assertArrayNotHasKey('show_sms_link', $_SESSION);
        $this->assertSame($this->token, $this->state->token());
    }

    public function testReplaceTokenStartsAttemptsAfresh(): void
    {
        $this->state->startVerificationStep($this->phone, null);
        $this->state->countInvalidOtp();
        $newToken = $this->token->withFailedUrls(['https://api/app0']);

        $this->state->replaceToken($newToken);

        $this->assertSame($newToken, $_SESSION['otp_token']);
        $this->assertArrayNotHasKey('invalid_otp_count', $_SESSION);
        $this->assertArrayNotHasKey('show_sms_link', $_SESSION);
    }

    public function testRegisterThenFinish(): void
    {
        $this->state->startVerificationStep($this->phone, $this->token);
        $this->state->countInvalidOtp();
        $this->state->setSmsFallback(true);

        $this->state->register();

        $this->assertMatchesRegularExpression('/^reg-[0-9a-f]{32}$/', $_SESSION['reg_id']);
        $this->assertSame($_SESSION['reg_id'], $this->state->regId());
        $this->assertArrayNotHasKey('invalid_otp_count', $_SESSION);
        $this->assertArrayNotHasKey('show_sms_link', $_SESSION);

        $this->state->finish();

        $this->assertNull($this->state->regId());
        $this->assertFalse($this->state->inVerificationStep());
        $this->assertSame($this->phone, $this->state->phone());
    }

    public function testValuesOfOtherTypesReadAsMissing(): void
    {
        $_SESSION['phone_data'] = ['capi_format' => '94771234567'];
        $_SESSION['otp_token'] = ['referenceNo' => 'ref'];

        $this->assertNull($this->state->phone());
        $this->assertNull($this->state->token());
    }
}
