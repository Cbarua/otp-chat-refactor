<?php

declare(strict_types=1);

namespace App\Controller;

if (!function_exists('App\Controller\random_bytes')) {
    function random_bytes(int $length): string
    {
        if (isset($GLOBALS['mock_random_bytes_fail_otp']) && $GLOBALS['mock_random_bytes_fail_otp']) {
            throw new \Exception("Random bytes failed");
        }
        return \random_bytes($length);
    }
}

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use App\Controller\OtpController;
use App\Service\FacebookCapiService;
use App\Service\OtpApiInterface;
use App\Service\SessionService;
use App\Service\UserInfoService;
use App\Service\CsrfService;
use App\Service\RateLimiterService;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TestableOtpController extends OtpController
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

#[CoversClass(OtpController::class)]
class OtpControllerTest extends TestCase
{
    private TestableOtpController $controller;
    private array $config;
    private MockObject|OtpApiInterface $otpServiceMock;
    private MockObject|FacebookCapiService $capiServiceMock;
    private MockObject|LoggerInterface $loggerMock;
    private MockObject|UserInfoService $userInfoServiceMock;
    private MockObject|SessionService $sessionServiceMock;
    private MockObject|CsrfService $csrfServiceMock;
    private MockObject|RateLimiterService $rateLimiterMock;

    private array $sessionData;
    private const PRIMARY_API_URL = 'https://mock.api/primary.php';
    private const FALLBACK_API_URL = 'https://mock.api/fallback.php';

    protected function setUp(): void
    {
        $this->sessionData = [];

        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = 'otp';
        $_SERVER['HTTPS'] = 'on';

        $this->config = [
            'facebook' => ['test_event_code' => 'TEST123', 'pixel_id' => 'fb-pixel-123'],
            'api' => ['ideamart' => [self::PRIMARY_API_URL, self::FALLBACK_API_URL]],
            'sms' => ['number' => '77000', 'keyword' => 'chat']
        ];

        $this->otpServiceMock = $this->createMock(OtpApiInterface::class);
        $this->capiServiceMock = $this->createMock(FacebookCapiService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->userInfoServiceMock = $this->createMock(UserInfoService::class);
        $this->sessionServiceMock = $this->createMock(SessionService::class);
        $this->csrfServiceMock = $this->createMock(CsrfService::class);
        $this->rateLimiterMock = $this->createMock(RateLimiterService::class);

        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);
        $this->sessionServiceMock->method('get')->willReturnCallback(fn(string $key, $default = null) => $this->sessionData[$key] ?? $default);
        $this->sessionServiceMock->method('set')->willReturnCallback(function (string $key, $value): void {
            $this->sessionData[$key] = $value;
        });
        $this->sessionServiceMock->method('has')->willReturnCallback(fn(string $key): bool => isset($this->sessionData[$key]));
        $this->sessionServiceMock->method('unset')->willReturnCallback(function (string $key): void {
            unset($this->sessionData[$key]);
        });

        $this->controller = new TestableOtpController(
            $this->config,
            $this->otpServiceMock,
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
        unset($GLOBALS['mock_random_bytes_fail_otp']);
    }

    public function testShowOtpFormSecurityCheckFailsNoToken(): void
    {
        $this->controller->showOtpForm(Request::createFromGlobals());
        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertNull($this->controller->renderedView);
        $this->capiServiceMock->expects($this->never())->method('sendEvent');
    }

    public function testShowOtpFormFiresPageViewAndLeadEvents(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'test-ref-123'],
            OtpController::SESSION_LEAD_ID => 'lead-abc-999',
            OtpController::SESSION_PHONE_DATA => ['capi_format' => '94771234567'],
            'visitor_id' => 'v_test123',
            'fbp' => 'fb.1.test_fbp',
            'fbc' => 'fb.1.test_fbc'
        ];
        $request = Request::createFromGlobals();

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('csrf-token-123');

        // Expect 2 calls to sendEvent
        $this->capiServiceMock->expects($this->exactly(2))
            ->method('sendEvent')
            ->with(
                $this->stringContains(''), // Event Name (PageView or Lead)
                $this->anything(), // Event ID
                $this->anything(), // URL
                $this->callback(function ($userData) {
                    // Verify user data is passed correctly for both events
                    return $userData['ip'] === '127.0.0.1' &&
                        $userData['agent'] === 'TestAgent' &&
                        $userData['external_id'] === 'v_test123' &&
                        $userData['phone'] === '94771234567' &&
                        $userData['fbp'] === 'fb.1.test_fbp' &&
                        $userData['fbc'] === 'fb.1.test_fbc' &&
                        $userData['country'] === 'lk';
                })
            );

        $this->controller->showOtpForm($request);

        $this->assertEquals('otp_form', $this->controller->renderedView);
        $this->assertNull($this->controller->redirectUrl);
        $this->assertArrayNotHasKey(OtpController::SESSION_LEAD_ID, $this->sessionData);
        $this->assertArrayHasKey('page_view_id_otp', $this->sessionData);
        $this->assertEquals('csrf-token-123', $this->controller->renderData['csrfToken']);
    }

    public function testShowOtpFormCapiServiceNull(): void
    {
        $controller = new TestableOtpController(
            $this->config,
            $this->otpServiceMock,
            null, // capiService is null
            $this->loggerMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock,
            $this->rateLimiterMock
        );

        $request = Request::createFromGlobals();
        $this->sessionData[OtpController::SESSION_OTP_TOKEN] = ['referenceNo' => 'ref-123'];
        $this->sessionData[OtpController::SESSION_LEAD_ID] = 'lead-123';
        $this->sessionData[OtpController::SESSION_PHONE_DATA] = ['capi_format' => '94771234567'];

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('token');

        $controller->showOtpForm($request);

        $this->assertEquals('otp_form', $controller->renderedView);
    }

    public function testHandleOtpFormCsrfCheckFails(): void
    {
        $request = new Request([], ['csrf_token' => 'invalid-token']);
        $this->csrfServiceMock->expects($this->once())->method('validate')->with('invalid-token')->willReturn(false);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Security check failed. Please try again.', $this->sessionData[OtpController::SESSION_ERROR]);
    }
    public function testHandleOtpFormSecurityCheckFailsNoToken(): void
    {
        $request = new Request([], ['csrf_token' => 'valid-token']);
        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        // Rate limiter should NOT be called if token is missing (sanity check first)
        $this->rateLimiterMock->expects($this->never())->method('check');

        $this->controller->handleOtpForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->otpServiceMock->expects($this->never())->method('verifyOtp');
    }

    public function testHandleOtpFormRateLimitExceededTriggersFallback(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'ref-123', 'platform' => 'ideamart', 'usedApiUrl' => 'url1'],
            OtpController::SESSION_PHONE_DATA => ['platform' => 'ideamart', 'telco_format' => 'tel:123']
        ];
        $request = new Request([], ['csrf_token' => 'valid-token']);
        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);

        // Rate limit check fails
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(false);

        // Expect fallback call
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', 'tel:123', $this->anything(), ['url1'])
            ->willReturn(['status' => 'success', 'verificationToken' => ['referenceNo' => 'new-ref']]);

        // Expect rate limit clear
        $this->rateLimiterMock->expects($this->once())->method('clear');

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Please try again with the new OTP sent to your phone.', $this->sessionData[OtpController::SESSION_ERROR]);
        $this->assertEquals('new-ref', $this->sessionData[OtpController::SESSION_OTP_TOKEN]['referenceNo']);
    }

    public function testHandleOtpFormRateLimitExceededFallbackFails(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'ref-123', 'platform' => 'ideamart', 'usedApiUrl' => 'url1'],
            OtpController::SESSION_PHONE_DATA => ['platform' => 'ideamart', 'telco_format' => 'tel:123']
        ];
        $request = new Request([], ['csrf_token' => 'valid-token']);
        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);

        // Rate limit check fails
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(false);

        // Fallback also fails
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->willReturn(['status' => 'error']);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        // Should show rate limit error since it was triggered by rate limit
        $this->assertEquals('Too many attempts. Please try again later.', $this->sessionData[OtpController::SESSION_ERROR]);
    }

    public function testHandleOtpFormInvalidFormat(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'test-ref-123'],
        ];
        $request = new Request([], ['otp' => '123', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->controller->handleOtpForm($request);
        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Invalid OTP. Must be 6 digits.', $this->sessionData[OtpController::SESSION_ERROR]);
    }

    public function testHandleOtpFormVerificationSuccessAndSubscribed(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'test-ref-123', 'platform' => 'ideamart'],
            OtpController::SESSION_PHONE_DATA => ['platform' => 'ideamart']
        ];
        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->with(['referenceNo' => 'test-ref-123', 'platform' => 'ideamart'], '123456')
            ->willReturn(['status' => OtpController::OTP_SUCCESS, 'subscriptionStatus' => OtpController::SUB_STATUS_PENDING]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('thanks', $this->controller->redirectUrl);
        $this->assertArrayHasKey(OtpController::SESSION_REG_ID, $this->sessionData);
    }

    public function testHandleOtpFormVerificationFailsInvalidOtp(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'test-ref-123'],
        ];
        $request = new Request([], ['otp' => '654321', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['status' => OtpController::OTP_INVALID]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Invalid OTP. Please enter the correct OTP.', $this->sessionData[OtpController::SESSION_ERROR]);
    }

    public function testHandleOtpFormVerificationFailsInvalidOtpIncrementsCount(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'test-ref-123'],
            OtpController::SESSION_INVALID_OTP_COUNT => 1
        ];
        $request = new Request([], ['otp' => '654321', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => OtpController::OTP_INVALID]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals(2, $this->sessionData[OtpController::SESSION_INVALID_OTP_COUNT]);
        $this->assertEquals('Invalid OTP. Please enter the correct OTP.', $this->sessionData[OtpController::SESSION_ERROR]);
    }

    public function testHandleOtpFormVerificationFailsInvalidOtpShowsSmsLinkAfterThreeAttempts(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'test-ref-123'],
            OtpController::SESSION_INVALID_OTP_COUNT => 2
        ];
        $request = new Request([], ['otp' => '654321', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => OtpController::OTP_INVALID]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals(3, $this->sessionData[OtpController::SESSION_INVALID_OTP_COUNT]);
        $this->assertTrue($this->sessionData[OtpController::SESSION_SHOW_SMS_LINK]);
        $this->assertArrayNotHasKey(OtpController::SESSION_ERROR, $this->sessionData);
    }

    public function testHandleOtpFormVerificationFailsOtpNotFoundShowsSmsLink(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'test-ref-123'],
        ];
        $request = new Request([], ['otp' => '654321', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => OtpController::OTP_NOT_FOUND]);

        $this->controller->handleOtpForm($request);

        $this->assertTrue($this->sessionData[OtpController::SESSION_SHOW_SMS_LINK]);
        $this->assertEquals('otp', $this->controller->redirectUrl);
    }

    public function testHandleSuccessfulVerificationResetsCountAndLink(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'test-ref-123', 'platform' => 'ideamart'],
            OtpController::SESSION_PHONE_DATA => ['platform' => 'ideamart'],
            OtpController::SESSION_INVALID_OTP_COUNT => 2,
            OtpController::SESSION_SHOW_SMS_LINK => true
        ];
        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => OtpController::OTP_SUCCESS, 'subscriptionStatus' => OtpController::SUB_STATUS_REGISTERED]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('thanks', $this->controller->redirectUrl);
        $this->assertArrayNotHasKey(OtpController::SESSION_INVALID_OTP_COUNT, $this->sessionData);
        $this->assertArrayNotHasKey(OtpController::SESSION_SHOW_SMS_LINK, $this->sessionData);
    }

    public function testHandleOtpFormSuccessRandomBytesFailure(): void
    {
        $GLOBALS['mock_random_bytes_fail_otp'] = true;

        $this->sessionData[OtpController::SESSION_OTP_TOKEN] = ['referenceNo' => 'ref-123', 'platform' => 'mspace', 'usedApiUrl' => 'url'];
        $this->sessionData[OtpController::SESSION_PHONE_DATA] = ['platform' => 'mspace', 'telco_format' => 'tel:123'];

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => 'success', 'subscriptionStatus' => 'REGISTERED']);

        $this->controller->handleOtpForm($request);

        $this->assertStringStartsWith('reg-', $this->sessionData[OtpController::SESSION_REG_ID]);
        // Verify that redirects to thank you page
        $this->assertEquals('thanks', $this->controller->redirectUrl);
        $this->assertArrayHasKey(OtpController::SESSION_REG_ID, $this->sessionData);
    }

    public function testHandleSuccessfulVerificationMissingPlatform(): void
    {
        // Setup session with NO platform info
        $this->sessionData[OtpController::SESSION_OTP_TOKEN] = ['referenceNo' => 'ref-123']; // No platform here
        $this->sessionData[OtpController::SESSION_PHONE_DATA] = []; // No platform here

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => 'success']);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertEquals('Registration failed. Please try again.', $this->sessionData[OtpController::SESSION_ERROR]);
    }

    public function testHandleFailedVerificationMissingData(): void
    {
        // Setup session with missing data for fallback
        $this->sessionData[OtpController::SESSION_OTP_TOKEN] = ['referenceNo' => 'ref-123']; // Missing usedApiUrl/platform

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => 'error']);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertEquals('An error occurred. Please try again later.', $this->sessionData[OtpController::SESSION_ERROR]);
    }

    public function testHandleOtpFormSuccessNotSubscribed(): void
    {
        $this->sessionData[OtpController::SESSION_OTP_TOKEN] = ['referenceNo' => 'ref-123', 'platform' => 'ideamart'];
        $this->sessionData[OtpController::SESSION_PHONE_DATA] = ['platform' => 'ideamart'];

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        // Status success but subscription status is NOT registered/pending
        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => 'success', 'subscriptionStatus' => 'FAILED']);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Registration failed. Please try again.', $this->sessionData[OtpController::SESSION_ERROR]);
    }

    public function testHandleFailedVerificationFallbackSuccess(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'ref-123', 'platform' => 'ideamart', 'usedApiUrl' => 'url2', 'failedUrls' => ['url0', 'url1']],
            OtpController::SESSION_PHONE_DATA => ['platform' => 'ideamart', 'telco_format' => 'tel:123']
        ];

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => 'error']);

        // Fallback succeeds
        // Expect failedUrls to include url0, url1, and url2
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', 'tel:123', $this->anything(), ['url0', 'url1', 'url2'])
            ->willReturn(['status' => 'success', 'verificationToken' => ['referenceNo' => 'new-ref']]);

        // Expect rate limit clear
        $this->rateLimiterMock->expects($this->once())->method('clear');

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Please try again with the new OTP sent to your phone.', $this->sessionData[OtpController::SESSION_ERROR]);
        $this->assertEquals('new-ref', $this->sessionData[OtpController::SESSION_OTP_TOKEN]['referenceNo']);
        // Check if failedUrls are updated in new token
        $this->assertEquals(['url0', 'url1', 'url2'], $this->sessionData[OtpController::SESSION_OTP_TOKEN]['failedUrls']);
    }

    public function testHandleFallbackSuccessResetsCountAndLink(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'ref-123', 'platform' => 'ideamart', 'usedApiUrl' => 'url2'],
            OtpController::SESSION_PHONE_DATA => ['platform' => 'ideamart', 'telco_format' => 'tel:123'],
            OtpController::SESSION_INVALID_OTP_COUNT => 3,
            OtpController::SESSION_SHOW_SMS_LINK => true
        ];

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => 'error']);

        $this->otpServiceMock->method('getOtp')->willReturn(['status' => 'success', 'verificationToken' => ['referenceNo' => 'new-ref']]);
        $this->rateLimiterMock->method('clear');

        $this->controller->handleOtpForm($request);

        $this->assertArrayNotHasKey(OtpController::SESSION_INVALID_OTP_COUNT, $this->sessionData);
        $this->assertArrayNotHasKey(OtpController::SESSION_SHOW_SMS_LINK, $this->sessionData);
    }

    public function testHandleFailedVerificationFallbackFails(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => ['referenceNo' => 'ref-123', 'platform' => 'ideamart', 'usedApiUrl' => 'url1'],
            OtpController::SESSION_PHONE_DATA => ['platform' => 'ideamart', 'telco_format' => 'tel:123']
        ];

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => 'error']);

        // Fallback fails
        $this->otpServiceMock->method('getOtp')->willReturn(['status' => 'error']);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('An error occurred. Please try again later.', $this->sessionData[OtpController::SESSION_ERROR]);
    }

    public function testHandleOtpFormVerificationExpiredTriggersRenew(): void
    {
        $this->sessionData = [
            OtpController::SESSION_OTP_TOKEN => [
                'referenceNo' => 'expired-ref',
                'platform' => 'ideamart',
                'usedApiUrl' => 'url1'
            ],
            OtpController::SESSION_PHONE_DATA => [
                'platform' => 'ideamart',
                'telco_format' => 'tel:123'
            ]
        ];

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        // 1. Verify fails with EXPIRED status
        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['status' => OtpController::OTP_STATUS_EXPIRED]);

        // 2. Expect re-request (with empty failedUrls because we don't exclude on expiry)
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', 'tel:123', $this->anything(), [])
            ->willReturn([
                'status' => 'success',
                'verificationToken' => ['referenceNo' => 'new-ref-123']
            ]);

        $this->controller->handleOtpForm($request);

        // 3. Verify success redirect and message
        $this->assertEquals('otp', $this->controller->redirectUrl);
        // "Your OTP expired..." message
        $this->assertStringContainsString('Your OTP expired', $this->sessionData[OtpController::SESSION_ERROR]);
        // Token updated
        $this->assertEquals('new-ref-123', $this->sessionData[OtpController::SESSION_OTP_TOKEN]['referenceNo']);
    }
}