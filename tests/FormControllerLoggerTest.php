<?php

declare(strict_types=1);

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

class TestableFormControllerLogger extends FormController
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
 * Dedicated unit tests to verify logging standards and contracts in FormController.
 */
#[CoversClass(FormController::class)]
class FormControllerLoggerTest extends TestCase
{
    private TestableFormControllerLogger $controller;
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

        $this->controller = new TestableFormControllerLogger(
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

    public function testLogsNewPageVisitAndPageViewOnInitialVisit(): void
    {
        $request = Request::createFromGlobals();
        $this->csrfServiceMock->method('getToken')->willReturn('csrf-token-123');

        $expectedMessages = [
            'New page visit. /',
            'New PageView triggered. /'
        ];

        $this->loggerMock->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function (string $message) use (&$expectedMessages): void {
                $this->assertSame(array_shift($expectedMessages), $message);
            });

        $this->controller->showPhoneForm($request);
    }

    public function testLogsNoticeWhenSkippingPageViewDueToSessionError(): void
    {
        $request = Request::createFromGlobals();
        $this->sessionData[FormController::SESSION_ERROR] = 'Invalid phone number';
        $this->csrfServiceMock->method('getToken')->willReturn('csrf-token-123');

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('New page visit. /', $this->anything());

        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('Rendering / to display error, skipping new PageView.', $this->anything());

        $this->controller->showPhoneForm($request);
    }

    public function testLogsWarningOnCsrfValidationFailure(): void
    {
        $request = Request::create('http://localhost/', 'POST', [
            'csrf_token' => 'invalid_token',
            'phone_number' => '0771234567'
        ]);

        $this->csrfServiceMock->method('validate')->willReturn(false);

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Phone form submitted', $this->anything());

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('CSRF token validation failed on phone form submission.', $this->anything());

        $this->controller->handlePhoneForm($request);
    }

    public function testLogsWarningOnRateLimitExceeded(): void
    {
        $request = Request::create('http://localhost/', 'POST', [
            'csrf_token' => 'valid_token',
            'phone_number' => '0771234567'
        ]);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(false); // false means rate limited

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Phone form submitted', $this->anything());

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Rate limit exceeded for phone submission.', $this->anything());

        $this->controller->handlePhoneForm($request);
    }

    public function testLogsWarningOnInvalidPhoneNumber(): void
    {
        $request = Request::create('http://localhost/', 'POST', [
            'csrf_token' => 'valid_token',
            'phone_number' => '1234'
        ]);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true); // true means allowed

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Phone form submitted', $this->anything());

        $expectedWarnings = [
            'Validation failed: Phone number format mismatch.',
            'Invalid phone number submitted'
        ];

        $this->loggerMock->expects($this->exactly(2))
            ->method('warning')
            ->willReturnCallback(function (string $message) use (&$expectedWarnings): void {
                $this->assertSame(array_shift($expectedWarnings), $message);
            });

        $this->controller->handlePhoneForm($request);
    }

    public function testLogsNoticeOnReusingValidToken(): void
    {
        $this->sessionData[FormController::SESSION_OTP_TOKEN] = [
            'referenceNo' => 'ref-123',
            'createdAt' => time() - 30
        ];
        $this->sessionData[FormController::SESSION_PHONE_DATA] = [
            'capi_format' => '94771234567',
            'telco_format' => 'tel:94771234567',
            'platform' => 'ideamart'
        ];

        $request = new Request([], [
            'mobile' => '0771234567',
            'csrf_token' => 'valid_token'
        ]);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('Reusing existing valid OTP token', $this->anything());

        $this->controller->handlePhoneForm($request);
    }

    public function testLogsNoticeOnUserAlreadyRegistered(): void
    {
        $request = new Request([], ['mobile' => '0711234567', 'csrf_token' => 'valid_token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('getOtp')
            ->willReturn(['status' => 'FAIL', 'statusDetail' => 'user already registered']);

        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with($this->stringContains('User already registered'), $this->anything());

        $this->controller->handlePhoneForm($request);
    }

    public function testLogsErrorOnTemporarySystemError(): void
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'valid_token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('getOtp')
            ->willReturn(['status' => 'FAIL', 'statusDetail' => 'temporary system error']);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Temporary system error encountered', $this->anything());

        $this->controller->handlePhoneForm($request);
    }
}
