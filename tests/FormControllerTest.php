<?php

declare(strict_types=1);

namespace App\Controller;

if (!function_exists('App\Controller\random_bytes')) {
    function random_bytes(int $length): string
    {
        if (isset($GLOBALS['mock_random_bytes_fail']) && $GLOBALS['mock_random_bytes_fail']) {
            throw new \Exception("Random bytes failed");
        }
        return \random_bytes($length);
    }
}

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Controller\FormController;
use App\Service\FacebookCapiService;
use App\Service\OtpApiInterface;
use App\Service\UserLoggerInterface;
use App\Service\UserInfoService;
use App\Service\SessionService;
use App\Service\CsrfService;
use App\Service\RateLimiterService;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A test-specific version of FormController that overrides problematic methods.
 */
class TestableFormController extends FormController
{
    public ?string $redirectUrl = null;
    public ?string $renderedView = null;
    public array $renderData = [];

    protected function redirect(string $url): RedirectResponse
    {
        $this->redirectUrl = $url;
        return new RedirectResponse($url);
    }

    protected function render(string $viewName, array $data = []): Response
    {
        $this->renderedView = $viewName;
        $this->renderData = $data;
        return new Response();
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
    private MockObject|UserInfoService $userInfoServiceMock;
    private MockObject|SessionService $sessionServiceMock;
    private MockObject|CsrfService $csrfServiceMock;
    private MockObject|RateLimiterService $rateLimiterMock;
    private array $sessionData;

    protected function setUp(): void
    {
        $this->sessionData = [];

        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTPS'] = 'on';

        $this->config = [
            'facebook' => [
                'test_event_code' => 'TEST123',
                'pixel_id' => 'fb-pixel-123',
            ],
            'api' => [
                'ideamart' => ['https://ideamart.api/first', 'https://ideamart.api/second'],
                'mspace' => ['https://mspace.api/first']
            ]
        ];

        $this->carrierConfig = require __DIR__ . '/../config/carriers.php';

        $this->otpServiceMock = $this->createMock(OtpApiInterface::class);
        $this->userLoggerMock = $this->createMock(UserLoggerInterface::class);
        $this->capiServiceMock = $this->createMock(FacebookCapiService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->userInfoServiceMock = $this->createMock(UserInfoService::class);
        $this->sessionServiceMock = $this->createMock(SessionService::class);
        $this->csrfServiceMock = $this->createMock(CsrfService::class);
        $this->rateLimiterMock = $this->createMock(RateLimiterService::class);

        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);

        $this->sessionServiceMock->method('get')->willReturnCallback(function (string $key, $default = null) {
            return $this->sessionData[$key] ?? $default;
        });
        $this->sessionServiceMock->method('set')->willReturnCallback(function (string $key, $value): void {
            $this->sessionData[$key] = $value;
        });
        $this->sessionServiceMock->method('has')->willReturnCallback(function (string $key): bool {
            return isset($this->sessionData[$key]);
        });
        $this->sessionServiceMock->method('unset')->willReturnCallback(function (string $key): void {
            unset($this->sessionData[$key]);
        });

        $this->sessionData[FormController::SESSION_VISITOR_ID] = 'v_test123';

        $this->controller = new TestableFormController(
            $this->config,
            $this->carrierConfig,
            $this->otpServiceMock,
            $this->userLoggerMock,
            $this->capiServiceMock,
            $this->loggerMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock,
            $this->rateLimiterMock
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        unset($GLOBALS['mock_random_bytes_fail']);
    }

    public function testShowPhoneFormNewVisit(): void
    {
        $request = Request::createFromGlobals();

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('csrf-token-123');

        // Expect sendEvent with array
        $this->capiServiceMock->expects($this->once())
            ->method('sendEvent')
            ->with(
                'PageView',
                $this->stringStartsWith('pgview-'),
                'https://localhost/', // HTTPS because $_SERVER['HTTPS'] = 'on'
                $this->callback(function ($userData) {
                    return $userData['ip'] === '127.0.0.1' &&
                        $userData['agent'] === 'TestAgent' &&
                        $userData['external_id'] === 'v_test123' &&
                        $userData['country'] === 'lk';
                })
            );

        $this->userLoggerMock->expects($this->once())->method('logVisit')->with('v_test123', '127.0.0.1', 'TestAgent', null);

        $this->controller->showPhoneForm($request);

        $this->assertEquals('phone_form', $this->controller->renderedView);
        $this->assertArrayHasKey(FormController::SESSION_PAGE_VIEW_ID, $this->sessionData);
        $this->assertNull($this->controller->renderData['errorMessage']);
        $this->assertNull($this->controller->renderData['alreadyRegistered']);
        $this->assertEquals('csrf-token-123', $this->controller->renderData['csrfToken']);
    }

    public function testShowPhoneFormGeneratesFbcFromQueryParam(): void
    {
        $request = Request::createFromGlobals();
        $request->query->set('fbclid', 'test_fbclid_val');

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('csrf-token-123');

        $this->capiServiceMock->expects($this->once())
            ->method('sendEvent')
            ->with(
                'PageView',
                $this->anything(),
                $this->anything(),
                $this->callback(function ($userData) {
                    // Check if fbc is generated and passed
                    return strpos($userData['fbc'], 'fb.1.') === 0 &&
                        strpos($userData['fbc'], 'test_fbclid_val') !== false;
                })
            );

        $this->controller->showPhoneForm($request);

        // Verify it's stored in session
        $this->assertArrayHasKey(FormController::SESSION_FBC, $this->sessionData);
        $this->assertStringContainsString('test_fbclid_val', $this->sessionData[FormController::SESSION_FBC]);
    }

    public function testShowPhoneFormWithErrorRedirect(): void
    {
        $request = Request::createFromGlobals();
        $this->sessionData[FormController::SESSION_ERROR] = 'Invalid phone number. Example: 0771234567';

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('csrf-token-123');
        $this->capiServiceMock->expects($this->never())->method('sendEvent');
        $this->userLoggerMock->expects($this->never())->method('logVisit');

        $this->controller->showPhoneForm($request);

        $this->assertEquals('phone_form', $this->controller->renderedView);
        $this->assertEquals('Invalid phone number. Example: 0771234567', $this->controller->renderData['errorMessage']);
        $this->assertNull($this->controller->renderData['alreadyRegistered']);
        $this->assertArrayNotHasKey(FormController::SESSION_ERROR, $this->sessionData);
    }

    public function testShowPhoneFormWithAlreadyRegisteredRedirect(): void
    {
        $request = Request::createFromGlobals();
        $this->sessionData[FormController::SESSION_ALREADY_REGISTERED] = 'You are registered';

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('csrf-token-123');
        $this->capiServiceMock->expects($this->once())->method('sendEvent');
        $this->userLoggerMock->expects($this->never())->method('logVisit');

        $this->controller->showPhoneForm($request);

        $this->assertEquals('phone_form', $this->controller->renderedView);
        $this->assertNull($this->controller->renderData['errorMessage']);
        $this->assertEquals('You are registered', $this->controller->renderData['alreadyRegistered']);
        $this->assertArrayNotHasKey(FormController::SESSION_ALREADY_REGISTERED, $this->sessionData);
    }

    public function testHandlePhoneFormInvalidNumber(): void
    {
        $request = new Request([], ['mobile' => '12345', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->userLoggerMock->expects($this->never())->method('logVisit');

        $this->controller->handlePhoneForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertEquals('Invalid phone number. Example: 0771234567', $this->sessionData[FormController::SESSION_ERROR]);
    }

    public function testHandlePhoneFormValidNumberOtpSuccess(): void
    {
        $request = new Request([], [
            'mobile' => '0771234567',
            'fbp' => 'fb.1.test_fbp',
            'fbc' => 'fb.1.test_fbc',
            'csrf_token' => 'valid-token'
        ]);

        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->userLoggerMock->expects($this->once())->method('logVisit')->with('v_test123', '127.0.0.1', 'TestAgent', '94771234567');
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', 'tel:94771234567', $this->arrayHasKey('ip'))
            ->willReturn(['status' => 'success', 'verificationToken' => ['referenceNo' => 'otp_ref_999', 'usedApiUrl' => 'https://ideamart.api/first']]);

        $this->controller->handlePhoneForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertArrayHasKey(FormController::SESSION_LEAD_ID, $this->sessionData);
        $this->assertEquals('otp_ref_999', $this->sessionData[FormController::SESSION_OTP_TOKEN]['referenceNo']);
        $this->assertEquals('fb.1.test_fbp', $this->sessionData[FormController::SESSION_FBP]);
        $this->assertEquals('fb.1.test_fbc', $this->sessionData[FormController::SESSION_FBC]);
        $this->assertEquals('94771234567', $this->sessionData[FormController::SESSION_PHONE_DATA]['capi_format']);
    }

    public function testHandlePhoneFormOtpFailureUserRegistered(): void
    {
        $request = new Request([], ['mobile' => '0711234567', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->userLoggerMock->expects($this->once())->method('logVisit');
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('mspace', 'tel:94711234567', $this->arrayHasKey('ip'))
            ->willReturn(['status' => 'FAIL', 'statusDetail' => 'user already registered']);

        $this->controller->handlePhoneForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertArrayNotHasKey(FormController::SESSION_ERROR, $this->sessionData);
        $this->assertEquals('You are already registered!', $this->sessionData[FormController::SESSION_ALREADY_REGISTERED]);
    }

    public function testHandlePhoneFormOtpFailureGenericError(): void
    {
        $request = new Request([], ['mobile' => '0711234567', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->userLoggerMock->expects($this->once())->method('logVisit');
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('mspace', 'tel:94711234567', $this->arrayHasKey('ip'))
            ->willReturn(['status' => 'FAIL', 'statusDetail' => 'some other api error']);

        $this->controller->handlePhoneForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertArrayNotHasKey(FormController::SESSION_ALREADY_REGISTERED, $this->sessionData);
        $this->assertEquals('An error occurred. Please try again later.', $this->sessionData[FormController::SESSION_ERROR]);
    }

    public function testTrackVisitRandomBytesFailure(): void
    {
        $GLOBALS['mock_random_bytes_fail'] = true;
        $request = Request::createFromGlobals();

        // We expect it to fall back to uniqid(), so it should still succeed
        $this->controller->showPhoneForm($request);

        $this->assertStringStartsWith('pgview-', $this->sessionData[FormController::SESSION_PAGE_VIEW_ID]);
    }

    public function testHandleOtpApiResponseRandomBytesFailure(): void
    {
        $GLOBALS['mock_random_bytes_fail'] = true;
        $request = new Request([], [
            'mobile' => '0771234567',
            'csrf_token' => 'valid-token'
        ]);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('getOtp')->willReturn(['status' => 'success', 'verificationToken' => ['referenceNo' => 'ref']]);
        $this->userLoggerMock->method('logVisit');

        $this->controller->handlePhoneForm($request);

        $this->assertStringStartsWith('lead-', $this->sessionData[FormController::SESSION_LEAD_ID]);
    }

    public function testShowPhoneFormCapiServiceNull(): void
    {
        $controller = new TestableFormController(
            $this->config,
            $this->carrierConfig,
            $this->otpServiceMock,
            $this->userLoggerMock,
            null, // capiService is null
            $this->loggerMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock,
            $this->rateLimiterMock
        );

        $request = Request::createFromGlobals();
        $this->userLoggerMock->expects($this->once())->method('logVisit');

        $controller->showPhoneForm($request);

        $this->assertEquals('phone_form', $controller->renderedView);
    }

    public function testHandlePhoneFormCsrfValidationFailure(): void
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'invalid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->with('invalid-token')->willReturn(false);
        $this->rateLimiterMock->expects($this->never())->method('check');
        $this->otpServiceMock->expects($this->never())->method('getOtp');

        $this->controller->handlePhoneForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertEquals('Security check failed. Please try again.', $this->sessionData[FormController::SESSION_ERROR]);
    }

    public function testHandlePhoneFormRateLimitExceeded(): void
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(false);
        $this->rateLimiterMock->expects($this->never())->method('increment');
        $this->otpServiceMock->expects($this->never())->method('getOtp');

        $this->controller->handlePhoneForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertEquals('Too many attempts. Please try again later.', $this->sessionData[FormController::SESSION_ERROR]);
    }
}