<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use App\Controller\OtpController;
use App\Service\FacebookCapiService;
use App\Service\OtpApiInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A test-specific version of OtpController that overrides problematic methods.
 */
class TestableOtpController extends OtpController
{
    public ?string $redirectUrl = null;
    public ?string $renderedView = null;
    public array $renderData = [];

    /**
     * Overrides the redirect method to prevent header() and exit() calls.
     */
    protected function redirect(string $url): void
    {
        $this->redirectUrl = $url;
        // Do not call parent or exit()
    }

    /**
     * Overrides the render method to prevent require_once errors.
     */
    protected function render(string $viewName, array $data = []): void
    {
        $this->renderedView = $viewName;
        $this->renderData = $data;
        // Do not call parent or require_once()
    }
}


/**
 * Unit tests for OtpController.
 */
#[CoversClass(OtpController::class)]
class OtpControllerTest extends TestCase
{
    private TestableOtpController $controller;
    private array $config;
    private MockObject|OtpApiInterface $otpServiceMock;
    private MockObject|FacebookCapiService $capiServiceMock;
    private MockObject|LoggerInterface $loggerMock;

    // This array simulates the data set by FormController
    private array $mockSessionData = [
        'otp_ref_no' => 'test-ref-123',
        'phone_data' => [
            'platform' => 'ideamart',
            'capi_format' => '94771234567'
        ],
        'lead_id' => 'lead-abc-999'
    ];

    protected function setUp(): void
    {
        // 1. Reset global state
        $_SESSION = [];
        $_POST = [];
        $_SERVER = [];

        // 2. Set up mock server variables for Helpers
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/otp';
        $_SERVER['HTTPS'] = 'on';

        // 3. Define mock config
        $this->config = [
            'facebook' => [
                'test_event_code' => 'TEST123',
                'pixel_id' => 'fb-pixel-123',
            ],
            // Add other keys as needed by the controller
        ];

        // 4. Create mocks
        $this->otpServiceMock = $this->createMock(OtpApiInterface::class);
        $this->capiServiceMock = $this->createMock(FacebookCapiService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        // 5. Instantiate our TestableController
        $this->controller = new TestableOtpController(
            $this->config,
            $this->otpServiceMock,
            $this->capiServiceMock,
            $this->loggerMock
        );
    }

    // --- Tests for showOtpForm() ---

    public function testShowOtpFormSecurityCheckFails(): void
    {
        // Session is empty, no 'otp_ref_no'
        $this->controller->showOtpForm();

        // Should redirect to home
        $this->assertEquals('/', $this->controller->redirectUrl);
        $this->assertNull($this->controller->renderedView);
        $this->capiServiceMock->expects($this->never())->method('sendEvent');
    }

    public function testShowOtpFormFiresPageViewAndLeadEvents(): void
    {
        $_SESSION = $this->mockSessionData;

        // Expect CAPI to be called twice: PageView and Lead
        $this->capiServiceMock->expects($this->exactly(2))
            ->method('sendEvent')
            ->willReturnCallback(function (string $eventName, string $eventId) {
                if ($eventName === 'PageView') {
                    $this->assertStringContainsString('pgview-otp-', $eventId);
                } else if ($eventName === 'Lead') {
                    $this->assertEquals('lead-abc-999', $eventId);
                }
                return null;
            });

        $this->controller->showOtpForm();

        // Check that view was rendered
        $this->assertEquals('otp_form', $this->controller->renderedView);
        $this->assertNull($this->controller->redirectUrl);
        
        // Check session state after
        $this->assertArrayNotHasKey('lead_id', $_SESSION, 'Lead ID should be unset after firing');
        $this->assertArrayHasKey('page_view_id_otp', $_SESSION);
        $this->assertNull($this->controller->renderData['errorMessage']);
    }

    public function testShowOtpFormWithErrorRedirectSkipsEvents(): void
    {
        $_SESSION = $this->mockSessionData;
        $_SESSION['error_message'] = 'Invalid PIN';
        // Unset lead_id, as it would have fired on the first (non-error) load
        unset($_SESSION['lead_id']);

        // CAPI events should NOT fire on an error redirect
        $this->capiServiceMock->expects($this->never())->method('sendEvent');

        $this->controller->showOtpForm();

        // Check that view was rendered with error
        $this->assertEquals('otp_form', $this->controller->renderedView);
        $this->assertEquals('Invalid PIN', $this->controller->renderData['errorMessage']);
        
        // Check session state after
        $this->assertArrayNotHasKey('error_message', $_SESSION, 'Error message should be unset');
        $this->assertArrayNotHasKey('page_view_id_otp', $_SESSION, 'Page view ID should not be set on error');
    }

    // --- Tests for handleOtpForm() ---

    public function testHandleOtpFormSecurityCheckFails(): void
    {
        // Session is empty
        $this->controller->handleOtpForm();

        // Should redirect to home
        $this->assertEquals('/', $this->controller->redirectUrl);
        $this->otpServiceMock->expects($this->never())->method('verifyOtp');
    }

    public function testHandleOtpFormInvalidFormat(): void
    {
        $_SESSION = $this->mockSessionData;
        $_POST['otp'] = '123'; // Invalid format

        $this->controller->handleOtpForm();

        // Should redirect back to OTP page with error
        $this->assertEquals('/otp', $this->controller->redirectUrl);
        $this->assertEquals('Invalid PIN. Must be 6 digits.', $_SESSION['error_message']);
        $this->otpServiceMock->expects($this->never())->method('verifyOtp');
    }

    public function testHandleOtpFormVerificationSuccessAndSubscribed(): void
    {
        $_SESSION = $this->mockSessionData;
        $_POST['otp'] = '123456';

        // Mock API success response
        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->with('ideamart', 'test-ref-123', '123456')
            ->willReturn([
                'status' => 'success',
                'subscriptionStatus' => 'INITIAL CHARGING PENDING'
            ]);

        $this->controller->handleOtpForm();

        // Should redirect to thanks page
        $this->assertEquals('/thanks', $this->controller->redirectUrl);
        $this->assertArrayHasKey('reg_id', $_SESSION);
        $this->assertStringContainsString('reg-', $_SESSION['reg_id']);
    }

    public function testHandleOtpFormVerificationSuccessMspaceLegacy(): void
    {
        $_SESSION = $this->mockSessionData;
        $_SESSION['phone_data']['platform'] = 'mspace'; // Set platform to mspace
        $_POST['otp'] = '123456';

        // Mock API success response but NOT subscribed (to test mspace logic)
        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->with('mspace', 'test-ref-123', '123456')
            ->willReturn([
                'status' => 'success',
                'subscriptionStatus' => 'NOT REGISTERED' // mspace ignores this
            ]);

        $this->controller->handleOtpForm();

        // Should still redirect to thanks page due to mspace legacy rule
        $this->assertEquals('/thanks', $this->controller->redirectUrl);
        $this->assertArrayHasKey('reg_id', $_SESSION);
    }

    public function testHandleOtpFormVerificationSuccessNotSubscribed(): void
    {
        $_SESSION = $this->mockSessionData;
        $_POST['otp'] = '123456';

        // Mock API success response but NOT subscribed
        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn([
                'status' => 'success',
                'subscriptionStatus' => 'NOT REGISTERED'
            ]);

        $this->controller->handleOtpForm();

        // Should redirect back to OTP page with error
        $this->assertEquals('/otp', $this->controller->redirectUrl);
        $this->assertEquals('Registration failed. Please try again.', $_SESSION['error_message']);
    }

    public function testHandleOtpFormVerificationFailsInvalidOtp(): void
    {
        $_SESSION = $this->mockSessionData;
        $_POST['otp'] = '654321';

        // Mock API "Invalid OTP" response
        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['status' => 'Invalid OTP']);

        $this->controller->handleOtpForm();

        // Should redirect back to OTP page with error
        $this->assertEquals('/otp', $this->controller->redirectUrl);
        $this->assertEquals('Invalid OTP. Please try again.', $_SESSION['error_message']);
    }

    public function testHandleOtpFormVerificationFailsGenericError(): void
    {
        $_SESSION = $this->mockSessionData;
        $_POST['otp'] = '123456';

        // Mock a generic API failure
        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['status' => 'FAIL', 'statusDetail' => 'API down']);

        $this->controller->handleOtpForm();

        // Should redirect back to OTP page with generic error
        $this->assertEquals('/otp', $this->controller->redirectUrl);
        $this->assertEquals('An error occurred. Please try again later.', $_SESSION['error_message']);
    }
}
