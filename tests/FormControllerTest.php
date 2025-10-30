<?php
// tests/FormControllerTest.php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Controller\FormController;
use App\Service\FacebookCapiService;
use App\Service\OtpApiInterface;
use App\Service\UserLoggerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * A test-specific version of FormController that overrides problematic methods.
 * This class is defined here for convenience, but could be in its own file.
 */
class TestableFormController extends FormController
{
    public ?string $redirectUrl = null;
    public ?string $renderedView = null;
    public array $renderData = [];

    /**
     * Overrides the redirect method to prevent header() and exit() calls.
     * We just capture the URL it tried to redirect to.
     */
    protected function redirect(string $url): void
    {
        $this->redirectUrl = $url;
        // Do not call parent or exit()
    }

    /**
     * Overrides the render method to prevent require_once errors.
     * We just capture the view name and data.
     */
    protected function render(string $viewName, array $data = []): void
    {
        $this->renderedView = $viewName;
        $this->renderData = $data;
        // Do not call parent or require_once()
    }
}


/**
 * Unit tests for FormController.
 */
#[CoversClass(FormController::class)]
class FormControllerTest extends TestCase
{
    private TestableFormController $controller;
    private array $config;
    private array $carrierConfig;
    private MockObject|OtpApiInterface $otpServiceMock;
    private MockObject|UserLoggerInterface $userLoggerMock;
    private MockObject|FacebookCapiService $capiServiceMock;
    private MockObject|LoggerInterface $loggerMock;

    protected function setUp(): void
    {
        // 1. Reset global state before each test
        $_SESSION = [];
        $_POST = [];
        $_SERVER = [];

        // 2. Set up mock server variables needed by Helpers.php
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTPS'] = 'on'; // For Helpers::getCurrentUrl

        // 3. Set up a visitor ID in the session
        $_SESSION['visitor_id'] = 'v_test123';

        // 4. Define mock configurations
        $this->config = [
            'facebook' => [
                'test_event_code' => 'TEST123',
                'pixel_id' => 'fb-pixel-123',
            ],
            // ... other config keys as needed
        ];
        
        // This config is required for Validator::normalizePhone to work
        $this->carrierConfig = require __DIR__ . '/../config/carriers.php';

        // 5. Create mocks for all injected dependencies
        $this->otpServiceMock = $this->createMock(OtpApiInterface::class);
        $this->userLoggerMock = $this->createMock(UserLoggerInterface::class);
        $this->capiServiceMock = $this->createMock(FacebookCapiService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        // 6. Instantiate our TestableFormController
        $this->controller = new TestableFormController(
            $this->config,
            $this->carrierConfig,
            $this->otpServiceMock,
            $this->userLoggerMock,
            $this->capiServiceMock,
            $this->loggerMock
        );
    }

    // --- Tests for showPhoneForm() ---

    public function testShowPhoneFormNewVisit(): void
    {
        // Expect CAPI PageView event
        $this->capiServiceMock->expects($this->once())
            ->method('sendEvent')
            ->with(
                'PageView',
                $this->stringContains('pgview-'),
                'https://localhost/',
                '127.0.0.1',
                'TestAgent'
            );

        // Expect UserLogger visit (without phone)
        $this->userLoggerMock->expects($this->once())
            ->method('logVisit')
            ->with('v_test123', '127.0.0.1', 'TestAgent', null);

        // Run the controller method
        $this->controller->showPhoneForm();

        // Check that the correct view was rendered
        $this->assertEquals('phone_form', $this->controller->renderedView);
        
        // Check that a page_view_id was set in the session
        $this->assertArrayHasKey('page_view_id', $_SESSION);
        
        // Check that error message is null
        $this->assertNull($this->controller->renderData['errorMessage']);
        $this->assertFalse($this->controller->renderData['isErrorRedirect']);
    }

    public function testShowPhoneFormWithErrorRedirect(): void
    {
        $_SESSION['error_message'] = 'An error occurred';

        // CAPI and UserLogger should NOT be called on an error redirect
        $this->capiServiceMock->expects($this->never())->method('sendEvent');
        $this->userLoggerMock->expects($this->never())->method('logVisit');

        // Run the controller method
        $this->controller->showPhoneForm();

        // Check that the correct view was rendered
        $this->assertEquals('phone_form', $this->controller->renderedView);

        // Check that the error message was passed to the view
        $this->assertEquals('An error occurred', $this->controller->renderData['errorMessage']);
        $this->assertTrue($this->controller->renderData['isErrorRedirect']);

        // Check that the error message was cleared from the session
        $this->assertArrayNotHasKey('error_message', $_SESSION);
        
        // Check that no PageView ID was set
        $this->assertArrayNotHasKey('page_view_id', $_SESSION);
    }

    // --- Tests for handlePhoneForm() ---

    public function testHandlePhoneFormInvalidNumber(): void
    {
        $_POST['mobile'] = '12345'; // Invalid number

        // UserLogger should not be called for an invalid number
        $this->userLoggerMock->expects($this->never())->method('logVisit');

        // Run the controller method
        $this->controller->handlePhoneForm();

        // Check that it redirects back to the home page
        $this->assertEquals('/', $this->controller->redirectUrl);

        // Check that the correct error message was set in the session
        $this->assertEquals(
            'Invalid phone number. Example: 0771234567',
            $_SESSION['error_message']
        );
    }

    public function testHandlePhoneFormValidNumberOtpSuccess(): void
    {
        $_POST['mobile'] = '0771234567'; // Valid LK Dialog number
        $_POST['fbp'] = 'fb.1.test_fbp';
        $_POST['fbc'] = 'fb.1.test_fbc';

        // Expect UserLogger to be called with the normalized phone
        $this->userLoggerMock->expects($this->once())
            ->method('logVisit')
            ->with('v_test123', '127.0.0.1', 'TestAgent', '94771234567');

        // Expect OTP service to be called and return a success response
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with(
                'ideamart', // Platform from Validator
                'tel:94771234567', // Telco format from Validator
                $this->anything() // We don't need to strictly test metaData here
            )
            ->willReturn(['referenceNo' => 'otp_ref_999']);

        // Run the controller method
        $this->controller->handlePhoneForm();

        // Check that it redirects to the OTP page
        $this->assertEquals('/otp', $this->controller->redirectUrl);

        // Check that all required data was stored in the session
        $this->assertArrayHasKey('lead_id', $_SESSION);
        $this->assertEquals('otp_ref_999', $_SESSION['otp_ref_no']);
        $this->assertEquals('fb.1.test_fbp', $_SESSION['fbp']);
        $this->assertEquals('fb.1.test_fbc', $_SESSION['fbc']);
        $this->assertEquals('94771234567', $_SESSION['phone_data']['capi_format']);
    }

    public function testHandlePhoneFormOtpFailureUserRegistered(): void
    {
        $_POST['mobile'] = '0711234567'; // Valid LK Mobitel number

        // UserLogger should still be called
        $this->userLoggerMock->expects($this->once())->method('logVisit');

        // Expect OTP service to return a "user already registered" error
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->willReturn([
                'status' => 'FAIL',
                'statusDetail' => 'user already registered'
            ]);

        // Run the controller method
        $this->controller->handlePhoneForm();

        // Check that it redirects back to the home page
        $this->assertEquals('/', $this->controller->redirectUrl);

        // Check that the specific error message was set
        $this->assertEquals('You are already registered!', $_SESSION['error_message']);
    }

    public function testHandlePhoneFormOtpFailureGenericError(): void
    {
        $_POST['mobile'] = '0711234567';

        // UserLogger should still be called
        $this->userLoggerMock->expects($this->once())->method('logVisit');

        // Expect OTP service to return a generic error
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->willReturn([
                'status' => 'FAIL',
                'statusDetail' => 'some other api error'
            ]);

        // Run the controller method
        $this->controller->handlePhoneForm();

        // Check that it redirects back to the home page
        $this->assertEquals(
            '/',
            $this->controller->redirectUrl
        );

        // Check that the generic error message was set
        $this->assertEquals(
            'An error occurred. Please try again later.',
            $_SESSION['error_message']
        );
    }
}