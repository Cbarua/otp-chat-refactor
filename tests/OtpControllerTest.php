<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Tests\Support\SessionFixtures;
use Tests\Support\TrackingVariables;
use App\Service\OtpFlowState;
use App\Enum\SessionKey;
use App\Controller\OtpController;
use App\Enum\OtpRequestOutcome;
use App\Enum\OtpVerifyOutcome;
use App\Service\FacebookCapiService;
use App\Service\OtpApiService;
use App\Service\SessionService;
use App\Service\UserInfoService;
use App\Service\CsrfService;
use App\Service\RateLimiterService;
use App\Service\AnalyticsTrackerService;
use App\Service\SimpleUserLoggerService;
use App\Service\OtpFlowService;
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
    use SessionFixtures;
    use TrackingVariables;

    private TestableOtpController $controller;
    private array $config;
    private MockObject|OtpApiService $otpServiceMock;
    private MockObject|FacebookCapiService $capiServiceMock;
    private MockObject|LoggerInterface $loggerMock;
    private MockObject|UserInfoService $userInfoServiceMock;
    private MockObject|SessionService $sessionServiceMock;
    private MockObject|CsrfService $csrfServiceMock;
    private MockObject|RateLimiterService $rateLimiterMock;
    private MockObject|SimpleUserLoggerService $userLoggerMock;
    private AnalyticsTrackerService $analyticsTracker;
    private OtpFlowService $otpFlowService;

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
            'sms' => ['number' => '77000', 'keyword' => 'chat'],
            'google' => ['ga_measurement_id' => 'G-TEST123'],
        ];

        $this->otpServiceMock = $this->createMock(OtpApiService::class);
        $this->capiServiceMock = $this->createMock(FacebookCapiService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->userInfoServiceMock = $this->createMock(UserInfoService::class);
        $this->sessionServiceMock = $this->createMock(SessionService::class);
        $this->csrfServiceMock = $this->createMock(CsrfService::class);
        $this->rateLimiterMock = $this->createMock(RateLimiterService::class);
        $this->userLoggerMock = $this->createMock(SimpleUserLoggerService::class);

        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);
        $this->sessionServiceMock->method('get')->willReturnCallback(function (\App\Enum\SessionKey|string $key, $default = null) {
            $k = $key instanceof \App\Enum\SessionKey ? $key->value : $key;
            return $this->sessionData[$k] ?? $default;
        });
        $this->sessionServiceMock->method('set')->willReturnCallback(function (\App\Enum\SessionKey|string $key, $value): void {
            $k = $key instanceof \App\Enum\SessionKey ? $key->value : $key;
            $this->sessionData[$k] = $value;
        });
        $this->sessionServiceMock->method('has')->willReturnCallback(function (\App\Enum\SessionKey|string $key): bool {
            $k = $key instanceof \App\Enum\SessionKey ? $key->value : $key;
            return isset($this->sessionData[$k]);
        });
        $this->sessionServiceMock->method('unset')->willReturnCallback(function (\App\Enum\SessionKey|string $key): void {
            $k = $key instanceof \App\Enum\SessionKey ? $key->value : $key;
            unset($this->sessionData[$k]);
        });

        $this->analyticsTracker = new AnalyticsTrackerService(
            $this->config,
            $this->capiServiceMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );

        $this->otpFlowService = new OtpFlowService(
            $this->config,
            require __DIR__ . '/../config/carriers.php',
            $this->otpServiceMock,
            $this->analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->rateLimiterMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $this->controller = new TestableOtpController(
            $this->config,
            $this->otpFlowService,
            $this->analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock,
            new OtpFlowState($this->sessionServiceMock)
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
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'test-ref-123'),
            SessionKey::LEAD_ID->value => 'lead-abc-999',
            SessionKey::PHONE_DATA->value => self::phone(capiFormat: '94771234567'),
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
        $this->assertArrayNotHasKey(SessionKey::LEAD_ID->value, $this->sessionData);
        $this->assertArrayHasKey('page_view_id_otp', $this->sessionData);
        $this->assertEquals('csrf-token-123', $this->controller->renderData['csrfToken']);
    }

    public function testShowOtpFormPinsThePageViewTheLeadAndTheLayoutVariables(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'test-ref-123'),
            SessionKey::LEAD_ID->value => 'lead-abc-999',
            SessionKey::PHONE_DATA->value => self::phone(capiFormat: '94771234567', value: 5.0),
            SessionKey::VISITOR_ID->value => 'v_test123',
            SessionKey::FBP->value => 'fb.1.session_fbp',
            SessionKey::FBC->value => 'fb.1.session_fbc',
        ];
        $request = Request::createFromGlobals();
        $this->csrfServiceMock->method('getToken')->willReturn('csrf-token-123');
        $this->capiServiceMock->method('processRequest')
            ->willReturn(['fbc' => 'fb.1.request_fbc', 'fbp' => null, 'client_ip_address' => '10.0.0.9']);

        $events = [];
        $this->capiServiceMock->expects($this->exactly(2))->method('sendEvent')
            ->willReturnCallback(function (string $name, string $id, string $url, array $userData, ?array $customData = null) use (&$events): array {
                $events[] = [$name, $id, $url, $userData, $customData];
                return [];
            });
        $logs = [];
        $this->loggerMock->method('info')->willReturnCallback(function (string $message, array $context = []) use (&$logs): void {
            $logs[] = [$message, $context];
        });

        $this->controller->showOtpForm($request);

        $pageViewId = $events[0][1];
        $this->assertMatchesRegularExpression('/^pgview-otp-[0-9a-f]{32}$/', $pageViewId);
        $this->assertSame([
            ['PageView', $pageViewId, $request->getUri(), [
                'ip' => '10.0.0.9',
                'agent' => 'TestAgent',
                'phone' => '94771234567',
                'fbp' => 'fb.1.session_fbp',
                'fbc' => 'fb.1.request_fbc',
                'external_id' => 'v_test123',
                'country' => 'lk',
            ], null],
            // Lead reads fbp/fbc from the session, where the PageView just stored the request's fbc.
            ['Lead', 'lead-abc-999', $request->getUri(), [
                'ip' => '10.0.0.9',
                'agent' => 'TestAgent',
                'phone' => '94771234567',
                'fbp' => 'fb.1.session_fbp',
                'fbc' => 'fb.1.request_fbc',
                'external_id' => 'v_test123',
                'country' => 'lk',
            ], ['currency' => 'USD', 'value' => '5']],
        ], $events);
        $this->assertSame($pageViewId, $this->sessionData[SessionKey::PAGE_VIEW_ID_OTP->value]);
        $this->assertTrackingVariables([
            'pixelId' => 'fb-pixel-123',
            'externalId' => 'v_test123',
            'phoneCapi' => '94771234567',
            'country' => 'lk',
            'pageViewEventId' => $pageViewId,
            'gaMeasurementId' => 'G-TEST123',
            'leadEventId' => 'lead-abc-999',
        ], $this->controller->renderData);
        $this->assertSame([
            ['New page visit. /otp', ['ip' => '127.0.0.1', 'useragent' => 'TestAgent']],
            ['New PageView triggered. /otp', ['page_view_id' => $pageViewId, 'phone_for_matching' => '94771234567']],
            ['Lead event triggered.', ['lead_id' => 'lead-abc-999', 'phone' => '94771234567']],
        ], $logs);
    }

    public function testShowOtpFormWithAnErrorSkipsThePageViewButSendsThePendingLead(): void
    {
        // SMS fallback entered at the request step with a flash: the first /otp render shows an error.
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => true,
            SessionKey::SHOW_SMS_LINK->value => true,
            SessionKey::LEAD_ID->value => 'lead-abc-999',
            SessionKey::PHONE_DATA->value => self::phone(capiFormat: '94771234567', value: 5.0),
            SessionKey::VISITOR_ID->value => 'v_test123',
            SessionKey::FBP->value => 'fb.1.session_fbp',
            SessionKey::FBC->value => 'fb.1.session_fbc',
            SessionKey::ERROR_MESSAGE->value => 'Send the SMS instead.',
            SessionKey::PAGE_VIEW_ID_OTP->value => 'pgview-otp-earlier',
        ];
        $request = Request::createFromGlobals();
        $this->csrfServiceMock->method('getToken')->willReturn('csrf-token-123');
        $this->capiServiceMock->method('processRequest')
            ->willReturn(['fbc' => 'fb.1.request_fbc', 'fbp' => null, 'client_ip_address' => '10.0.0.9']);

        $events = [];
        $this->capiServiceMock->expects($this->once())->method('sendEvent')
            ->willReturnCallback(function (string $name, string $id, string $url, array $userData, ?array $customData = null) use (&$events): array {
                $events[] = [$name, $id, $url, $userData, $customData];
                return [];
            });
        $notices = [];
        $this->loggerMock->method('notice')->willReturnCallback(function (string $message, array $context = []) use (&$notices): void {
            $notices[] = [$message, $context];
        });

        $this->controller->showOtpForm($request);

        $this->assertSame([
            ['Lead', 'lead-abc-999', $request->getUri(), [
                'ip' => '10.0.0.9',
                'agent' => 'TestAgent',
                'phone' => '94771234567',
                'fbp' => 'fb.1.session_fbp',
                'fbc' => 'fb.1.session_fbc',
                'external_id' => 'v_test123',
                'country' => 'lk',
            ], ['currency' => 'USD', 'value' => '5']],
        ], $events);
        $this->assertTrackingVariables([
            'pixelId' => 'fb-pixel-123',
            'externalId' => 'v_test123',
            'phoneCapi' => '94771234567',
            'country' => 'lk',
            'pageViewEventId' => 'pgview-otp-earlier',
            'gaMeasurementId' => 'G-TEST123',
            'leadEventId' => 'lead-abc-999',
        ], $this->controller->renderData);
        $this->assertSame(['Rendering /otp to display error, skipping new PageView.', ['error' => 'Send the SMS instead.']], $notices[0]);
    }

    public function testShowOtpFormNotFireEventsWhenCapiServiceNull(): void
    {
        $analyticsTrackerWithoutCapi = new AnalyticsTrackerService(
            $this->config,
            null,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );

        $otpFlowServiceWithoutCapi = new OtpFlowService(
            $this->config,
            require __DIR__ . '/../config/carriers.php',
            $this->otpServiceMock,
            $analyticsTrackerWithoutCapi,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->rateLimiterMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $controller = new TestableOtpController(
            $this->config,
            $otpFlowServiceWithoutCapi,
            $analyticsTrackerWithoutCapi,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $request = Request::createFromGlobals();
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref-123');
        // set in FormController
        $this->sessionData[SessionKey::LEAD_ID->value] = 'lead-123';
        $this->sessionData[SessionKey::PHONE_DATA->value] = self::phone(capiFormat: '94771234567');

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('token');

        $controller->showOtpForm($request);

        // Pageview and lead events are not fired when Capi service is not initiated
        $this->assertArrayNotHasKey(SessionKey::PAGE_VIEW_ID_OTP->value, $this->sessionData);
        $this->assertEquals('otp_form', $controller->renderedView);
    }

    /**
     * Security checks and validation error messages before OTP verification.
     */
    public function testHandleOtpFormCsrfCheckFails(): void
    {
        $request = new Request([], ['csrf_token' => 'invalid-token']);
        $this->csrfServiceMock->expects($this->once())->method('validate')->with('invalid-token')->willReturn(false);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Security check failed. Please try again.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
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

    public function testValidateOtpRequestInvalidTokenStructure(): void
    {
        // SMS fallback entered at the request step stores true instead of a token.
        $this->sessionData[SessionKey::OTP_TOKEN->value] = true;
        $this->csrfServiceMock->method('validate')->willReturn(true);

        $request = new Request([], ['csrf_token' => 'valid']);
        $this->controller->handleOtpForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
    }

    public function testHandleOtpFormRateLimitExceededTriggersFallbackSuccess(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'ref-123', platform: 'ideamart', usedApiUrl: 'url1'),
            SessionKey::PHONE_DATA->value => self::phone(platform: 'ideamart', telcoFormat: 'tel:123')
        ];
        $request = new Request([], ['csrf_token' => 'valid-token']);
        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);

        // Rate limit check fails
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(false);

        // Expect fallback call
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', 'tel:123', $this->anything(), ['url1'])
            ->willReturn(['outcome' => OtpRequestOutcome::SENT, 'verificationToken' => self::token(referenceNo: 'new-ref'), 'usedApiUrl' => 'url2']);

        // Expect rate limit clear
        $this->rateLimiterMock->expects($this->once())->method('clear');

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Please try again with the new OTP sent to your phone.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
        $this->assertEquals('new-ref', $this->sessionData[SessionKey::OTP_TOKEN->value]->referenceNo);
    }

    public function testHandleOtpFormRateLimitExceededFallbackFails(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'ref-123', platform: 'ideamart', usedApiUrl: 'url1'),
            SessionKey::PHONE_DATA->value => self::phone(platform: 'ideamart', telcoFormat: 'tel:123')
        ];
        $request = new Request([], ['csrf_token' => 'valid-token']);
        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);

        // Rate limit check fails
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(false);

        // Fallback also fails
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->willReturn(['outcome' => OtpRequestOutcome::FAILED]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        // Should show rate limit error since it was triggered by rate limit
        $this->assertEquals('Too many attempts. Please try again later.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
    }

    public function testHandleOtpFormInvalidOtpFormat(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'test-ref-123'),
        ];
        $request = new Request([], ['otp' => '123', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->controller->handleOtpForm($request);
        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Invalid OTP. Must be 6 digits.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
    }
    /**************************************************************************************************************** */

    /**
     * OTP verification api call and handling response
     */

    public function testHandleOtpFormVerificationFailsInvalidOtp(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'test-ref-123'),
        ];
        $request = new Request([], ['otp' => '654321', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['outcome' => OtpVerifyOutcome::INVALID]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Invalid OTP. Please enter the correct OTP.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
    }

    public function testHandleOtpFormVerificationExpiredTriggersRenew(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(
                referenceNo: 'expired-ref',
                platform: 'ideamart',
                usedApiUrl: 'url1',
                createdAt: time()
            ),
            SessionKey::PHONE_DATA->value => self::phone(
                platform: 'ideamart',
                telcoFormat: 'tel:123',
                capiFormat: '94771234567'
            ),
            SessionKey::INVALID_OTP_COUNT->value => 1,
            SessionKey::SHOW_SMS_LINK->value => false
        ];

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        // 1. Verify fails with EXPIRED status
        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['outcome' => OtpVerifyOutcome::EXPIRED]);

        // 2. Expect re-request (with empty failedUrls because we don't exclude on expiry)
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', 'tel:123', $this->anything(), [])
            ->willReturn([
                'outcome' => OtpRequestOutcome::SENT,
                'verificationToken' => self::token(
                    referenceNo: 'new-ref-123',
                    usedApiUrl: 'url1',
                    createdAt: strtotime('2023-10-10 10:00:00')
                )
            ]);

        $this->controller->handleOtpForm($request);

        // 3. Verify success redirect and message
        $this->assertEquals('otp', $this->controller->redirectUrl);
        // "Your OTP expired..." message
        $this->assertStringContainsString('Your OTP expired', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
        // Token updated
        $this->assertEquals('new-ref-123', $this->sessionData[SessionKey::OTP_TOKEN->value]->referenceNo);

        // Reset invalid otp count and show sms link
        $this->assertArrayNotHasKey(SessionKey::INVALID_OTP_COUNT->value, $this->sessionData);
        $this->assertArrayNotHasKey(SessionKey::SHOW_SMS_LINK->value, $this->sessionData);
    }

    public function testHandleOtpFormVerificationExpiredTriggersRenewMissingDataFails(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(
            referenceNo: 'ref',
            usedApiUrl: 'url1',
            createdAt: time(),
            // Missing platform
            platform: '',
        );
        // Missing phone data
        $this->sessionData[SessionKey::PHONE_DATA->value] = [];

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::EXPIRED]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);

        $this->controller->handleOtpForm($request);
        $this->assertEquals('./', $this->controller->redirectUrl);
    }

    public function testHandleExpiredTokenRenewFails(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(
                referenceNo: 'expired-ref',
                platform: 'ideamart',
                usedApiUrl: 'url1',
                createdAt: time()
            ),
            SessionKey::PHONE_DATA->value => self::phone(
                telcoFormat: '0771234567',
                platform: 'ideamart',
                capiFormat: '94771234567'
            )
        ];
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::EXPIRED]);
        $this->otpServiceMock->method('getOtp')->willReturn(['outcome' => OtpRequestOutcome::FAILED]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);

        $this->controller->handleOtpForm($request);
        $this->assertEquals('./', $this->controller->redirectUrl);
        // Ensure the error message is displayed in homepage
        $this->assertEquals('An error occurred. Please try again later.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
    }

    public function testHandleExpiredTokenRenewFallbackUrl(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(
                referenceNo: 'expired-ref',
                platform: 'ideamart',
                usedApiUrl: 'url1',
                createdAt: time()
            ),
            SessionKey::PHONE_DATA->value => self::phone(
                telcoFormat: '0771234567',
                platform: 'ideamart',
                capiFormat: '94771234567'
            )
        ];
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::EXPIRED]);
        $this->otpServiceMock->method('getOtp')->willReturn([
            'outcome' => OtpRequestOutcome::SENT,
            'verificationToken' => self::token(
                referenceNo: 'new-ref-fallback',
                usedApiUrl: 'url2',
                createdAt: time()
            )
        ]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $this->controller->handleOtpForm($request);
        $this->assertEquals('otp', $this->controller->redirectUrl);
    }

    public function testHandleOtpFormVerificationFailsInvalidOtpIncrementsCountWhenSmsLinkIsSetup(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'test-ref-123'),
            SessionKey::INVALID_OTP_COUNT->value => 1,
            // Shouldn't be possible, but if it is, it should be reset
            SessionKey::SHOW_SMS_LINK->value => true,
        ];
        $request = new Request([], ['otp' => '654321', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::INVALID]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals(2, $this->sessionData[SessionKey::INVALID_OTP_COUNT->value]);
        $this->assertEquals(false, $this->sessionData[SessionKey::SHOW_SMS_LINK->value]);
        $this->assertEquals('Invalid OTP. Please enter the correct OTP.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
    }

    public function testHandleOtpFormVerificationFailsInvalidOtpShowsSmsLinkAfterThreeAttempts(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'test-ref-123'),
            SessionKey::INVALID_OTP_COUNT->value => 2
        ];
        $request = new Request([], ['otp' => '654321', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::INVALID]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals(3, $this->sessionData[SessionKey::INVALID_OTP_COUNT->value]);
        $this->assertTrue($this->sessionData[SessionKey::SHOW_SMS_LINK->value]);
        $this->assertArrayNotHasKey(SessionKey::ERROR_MESSAGE->value, $this->sessionData);
    }

    public function testHandleOtpFormVerificationFailsOtpNotFoundShowsSmsLink(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'test-ref-123'),
        ];
        $request = new Request([], ['otp' => '654321', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::NOT_FOUND]);

        $this->controller->handleOtpForm($request);

        $this->assertTrue($this->sessionData[SessionKey::SHOW_SMS_LINK->value]);
        $this->assertEquals('otp', $this->controller->redirectUrl);
    }

    public function testHandleOtpFormVerificationFailsInvalidOtpWithoutSmsConfig(): void
    {
        $configNoSms = $this->config;
        unset($configNoSms['sms']);

        $analyticsTracker = new AnalyticsTrackerService(
            $configNoSms,
            $this->capiServiceMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );

        $otpFlowService = new OtpFlowService(
            $configNoSms,
            require __DIR__ . '/../config/carriers.php',
            $this->otpServiceMock,
            $analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->rateLimiterMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $controller = new TestableOtpController(
            $configNoSms,
            $otpFlowService,
            $analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref', usedApiUrl: 'url1');
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::INVALID]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);

        $controller->handleOtpForm($request);
        // show error
        $this->assertEquals('Invalid OTP. Please enter the correct OTP.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
        $this->assertEquals('otp', $controller->redirectUrl);
        // Assert no otp invalid count and show sms link has been set
        $this->assertArrayNotHasKey(SessionKey::INVALID_OTP_COUNT->value, $this->sessionData);
        $this->assertArrayNotHasKey(SessionKey::SHOW_SMS_LINK->value, $this->sessionData);
    }

    public function testHandleOtpFormVerificationFailsOtpNotFoundWithoutSmsConfig(): void
    {
        $configNoSms = $this->config;
        unset($configNoSms['sms']);

        $analyticsTracker = new AnalyticsTrackerService(
            $configNoSms,
            $this->capiServiceMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );

        $otpFlowService = new OtpFlowService(
            $configNoSms,
            require __DIR__ . '/../config/carriers.php',
            $this->otpServiceMock,
            $analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->rateLimiterMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $controller = new TestableOtpController(
            $configNoSms,
            $otpFlowService,
            $analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(
                referenceNo: 'ref-123',
                usedApiUrl: self::PRIMARY_API_URL,
                createdAt: time(),
                platform: 'ideamart'
            ),
            SessionKey::PHONE_DATA->value => self::phone(
                telcoFormat: '0771234567',
                platform: 'ideamart',
                capiFormat: '94771234567'
            )
        ];

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::NOT_FOUND]);
        $this->otpServiceMock->method('getOtp')->willReturn([
            'outcome' => OtpRequestOutcome::SENT,
            'verificationToken' => self::token(
                referenceNo: 'new-ref-456',
                usedApiUrl: self::PRIMARY_API_URL,
                createdAt: time()
            )
        ]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);

        $controller->handleOtpForm($request);

        $this->assertEquals('otp', $controller->redirectUrl);
        $this->assertEquals('Your OTP expired. A new OTP has been sent to your phone.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
        $this->assertEquals('new-ref-456', $this->sessionData[SessionKey::OTP_TOKEN->value]->referenceNo);
    }

    /**************************************************************************************************************** */

    public function testHandleOtpFormVerificationSuccessNotSubscribed(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref-123', platform: 'ideamart');
        $this->sessionData[SessionKey::PHONE_DATA->value] = self::phone(platform: 'ideamart');

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        // Status success but subscription status is NOT registered/pending
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::NOT_SUBSCRIBED]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Registration failed. Please try again.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
    }

    public function testHandleOtpFormVerificationSuccessAndSubscribed(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'test-ref-123', platform: 'ideamart'),
            SessionKey::PHONE_DATA->value => self::phone(platform: 'ideamart'),
            SessionKey::INVALID_OTP_COUNT->value => 2,
            // Shouldn't be possible to reach here with invalid count > 2, but if it is, it should be reset
            SessionKey::SHOW_SMS_LINK->value => true
        ];
        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->willReturn(true);
        $this->rateLimiterMock->expects($this->once())->method('check')->willReturn(true);

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->with(self::token(referenceNo: 'test-ref-123', platform: 'ideamart'), '123456')
            ->willReturn(['outcome' => OtpVerifyOutcome::VERIFIED]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('thanks', $this->controller->redirectUrl);
        $this->assertArrayHasKey(SessionKey::REG_ID->value, $this->sessionData);
        // Reset invalid otp count and link
        $this->assertArrayNotHasKey(SessionKey::INVALID_OTP_COUNT->value, $this->sessionData);
        $this->assertArrayNotHasKey(SessionKey::SHOW_SMS_LINK->value, $this->sessionData);
    }

    /**************************************************************************************************************** */

    public function testHandleFailedVerificationMissingData(): void
    {
        // Setup session with missing data for fallback
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref-123'); // Missing usedApiUrl/platform

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::PROVIDER_ERROR]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertEquals('An error occurred. Please try again later.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
    }

    public function testHandleFailedVerificationFallbackSuccess(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'ref-123', platform: 'ideamart', usedApiUrl: 'url2', failedUrls: ['url0', 'url1']),
            SessionKey::PHONE_DATA->value => self::phone(platform: 'ideamart', telcoFormat: 'tel:123')
        ];

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::PROVIDER_ERROR]);

        // Fallback succeeds
        // Expect failedUrls to include url0, url1, and url2
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', 'tel:123', $this->anything(), ['url0', 'url1', 'url2'])
            ->willReturn(['outcome' => OtpRequestOutcome::SENT, 'verificationToken' => self::token(referenceNo: 'new-ref', usedApiUrl: 'url3')]);

        // Expect rate limit clear
        $this->rateLimiterMock->expects($this->once())->method('clear');

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('Please try again with the new OTP sent to your phone.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
        $this->assertEquals('new-ref', $this->sessionData[SessionKey::OTP_TOKEN->value]->referenceNo);
        // Check if failedUrls are updated in new token
        $this->assertEquals(['url0', 'url1', 'url2'], $this->sessionData[SessionKey::OTP_TOKEN->value]->failedUrls);

        // reset invalid otp count and show sms link
        $this->assertArrayNotHasKey(SessionKey::INVALID_OTP_COUNT->value, $this->sessionData);
        $this->assertArrayNotHasKey(SessionKey::SHOW_SMS_LINK->value, $this->sessionData);
    }

    public function testHandleFailedVerificationFallbackFails(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'ref-123', platform: 'ideamart', usedApiUrl: 'url1'),
            SessionKey::PHONE_DATA->value => self::phone(platform: 'ideamart', telcoFormat: 'tel:123')
        ];

        $request = new Request([], ['otp' => '123456', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::PROVIDER_ERROR]);

        // Fallback fails
        $this->otpServiceMock->method('getOtp')->willReturn(['outcome' => OtpRequestOutcome::FAILED]);

        $this->controller->handleOtpForm($request);

        $this->assertEquals('otp', $this->controller->redirectUrl);
        $this->assertEquals('An error occurred. Please try again later.', $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
    }

    /**************************************************************************************************************** */

    public function testShowOtpFormNoticeWhenSmsLinkShown(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref');
        $this->sessionData[SessionKey::SHOW_SMS_LINK->value] = true;

        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('SMS link shown', ['number' => '77000', 'keyword' => 'chat']);

        $this->controller->showOtpForm(Request::createFromGlobals());
        $this->assertEquals('otp_form', $this->controller->renderedView);
    }

    public function testHandlePageViewEventNoticeWhenErrorInSession(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref');
        $this->sessionData[SessionKey::ERROR_MESSAGE->value] = 'Some error';

        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('Rendering /otp to display error, skipping new PageView.', ['error' => 'Some error']);

        $this->controller->showOtpForm(Request::createFromGlobals());
    }

    public function testValidateOtpRequestInvalidTokenStructureAjax(): void
    {
        // SMS fallback entered at the request step stores true instead of a token.
        $this->sessionData[SessionKey::OTP_TOKEN->value] = true;
        $this->csrfServiceMock->method('validate')->willReturn(true);

        $request = new Request([], ['csrf_token' => 'valid']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('./', $data['redirect']);
    }

    public function testHandleOtpFormAjaxCsrfCheckFails(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref');
        $this->csrfServiceMock->method('validate')->willReturn(false);

        $request = new Request([], ['csrf_token' => 'bad']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('otp', $data['redirect']);
    }

    public function testHandleOtpFormAjaxNoToken(): void
    {
        $this->csrfServiceMock->method('validate')->willReturn(true);

        $request = new Request([], ['csrf_token' => 'valid']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('./', $data['redirect']);
    }

    public function testHandleOtpFormAjaxInvalidFormat(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref');
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '12']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Invalid OTP. Must be 6 digits.', $data['message']);
    }

    public function testHandleOtpFormAjaxSuccessAndSubscribed(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref', platform: 'ideamart');
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::VERIFIED]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('success', $data['status']);
        $this->assertEquals('thanks', $data['redirect']);
    }

    public function testHandleOtpFormAjaxSuccessUnknownSubStatus(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref', platform: 'ideamart');
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::NOT_SUBSCRIBED]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Registration failed. Please try again.', $data['message']);
    }

    public function testHandleOtpFormAjaxVerificationFailsInvalidOtpWithSmsConfig(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref', usedApiUrl: 'url1');
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::INVALID]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertNull($data['redirect']);
    }

    public function testHandleOtpFormAjaxThirdInvalidOtpSendsVisitorToSmsLink(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref', usedApiUrl: 'url1');
        $this->sessionData[SessionKey::INVALID_OTP_COUNT->value] = 2;
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::INVALID]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);

        $data = json_decode($response->getContent(), true);
        $this->assertSame(
            ['status' => 'error', 'message' => 'Invalid OTP. Please enter the correct OTP.', 'redirect' => 'otp'],
            $data
        );
        $this->assertTrue($this->sessionData[SessionKey::SHOW_SMS_LINK->value]);
        $this->assertArrayNotHasKey(SessionKey::ERROR_MESSAGE->value, $this->sessionData);
    }

    public function testHandleOtpFormAjaxVerificationFailsOtpNotFoundWithSmsConfig(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref', usedApiUrl: 'url1');
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::NOT_FOUND]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('OTP not found.', $data['message']);
        $this->assertSame('otp', $data['redirect']);
    }

    public function testHandleOtpFormAjaxVerificationFailsInvalidOtpWithoutSmsConfig(): void
    {
        $configNoSms = $this->config;
        unset($configNoSms['sms']);

        $analyticsTracker = new AnalyticsTrackerService(
            $configNoSms,
            $this->capiServiceMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );

        $otpFlowService = new OtpFlowService(
            $configNoSms,
            require __DIR__ . '/../config/carriers.php',
            $this->otpServiceMock,
            $analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->rateLimiterMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $controller = new TestableOtpController(
            $configNoSms,
            $otpFlowService,
            $analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref', usedApiUrl: 'url1');
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::INVALID]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $controller->handleOtpForm($request);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Invalid OTP. Please enter the correct OTP.', $data['message']);
    }

    public function testHandleOtpFormAjaxVerificationFailsOtpNotFoundWithoutSmsConfig(): void
    {
        $configNoSms = $this->config;
        unset($configNoSms['sms']);

        $analyticsTracker = new AnalyticsTrackerService(
            $configNoSms,
            $this->capiServiceMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );

        $otpFlowService = new OtpFlowService(
            $configNoSms,
            require __DIR__ . '/../config/carriers.php',
            $this->otpServiceMock,
            $analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->rateLimiterMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $controller = new TestableOtpController(
            $configNoSms,
            $otpFlowService,
            $analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock,
            new OtpFlowState($this->sessionServiceMock)
        );

        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(
                referenceNo: 'ref-123',
                usedApiUrl: self::PRIMARY_API_URL,
                createdAt: time(),
                platform: 'ideamart'
            ),
            SessionKey::PHONE_DATA->value => self::phone(
                telcoFormat: '0771234567',
                platform: 'ideamart',
                capiFormat: '94771234567'
            )
        ];

        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::NOT_FOUND]);
        $this->otpServiceMock->method('getOtp')->willReturn([
            'outcome' => OtpRequestOutcome::SENT,
            'verificationToken' => self::token(
                referenceNo: 'new-ref-456',
                usedApiUrl: self::PRIMARY_API_URL,
                createdAt: time()
            )
        ]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $controller->handleOtpForm($request);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Your OTP expired. A new OTP has been sent to your phone.', $data['message']);
    }

    public function testHandleFailedVerificationMissingDataAjax(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref');
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::PROVIDER_ERROR]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('./', $data['redirect']);
    }

    public function testHandleFailedVerificationFallbackSuccessAjax(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'ref', usedApiUrl: 'url1', platform: 'ideamart'),
            SessionKey::PHONE_DATA->value => self::phone(telcoFormat: '0771234567', platform: 'ideamart')
        ];
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::PROVIDER_ERROR]);
        $this->otpServiceMock->method('getOtp')->willReturn([
            'outcome' => OtpRequestOutcome::SENT,
            'verificationToken' => self::token(referenceNo: 'new-ref', usedApiUrl: 'url2')
        ]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Please try again with the new OTP sent to your phone.', $data['message']);
    }

    public function testHandleFailedVerificationFallbackFailsAjax(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(referenceNo: 'ref', usedApiUrl: 'url1'),
            SessionKey::PHONE_DATA->value => self::phone(telcoFormat: '0771234567', platform: 'ideamart')
        ];
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::PROVIDER_ERROR]);
        $this->otpServiceMock->method('getOtp')->willReturn(['outcome' => OtpRequestOutcome::FAILED]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('An error occurred. Please try again later.', $data['message']);
    }

    public function testHandleExpiredTokenMissingDataAjax(): void
    {
        $this->sessionData[SessionKey::OTP_TOKEN->value] = self::token(
            referenceNo: 'ref',
            usedApiUrl: 'url1',
            createdAt: time(),
            platform: '',
        );
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::EXPIRED]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('./', $data['redirect']);
    }

    public function testHandleExpiredTokenRenewFailsAjax(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(
                referenceNo: 'expired-ref',
                platform: 'ideamart',
                usedApiUrl: 'url1',
                createdAt: time()
            ),
            SessionKey::PHONE_DATA->value => self::phone(
                telcoFormat: '0771234567',
                platform: 'ideamart',
                capiFormat: '94771234567'
            )
        ];
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::EXPIRED]);
        $this->otpServiceMock->method('getOtp')->willReturn(['outcome' => OtpRequestOutcome::FAILED]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('./', $data['redirect']);
    }

    public function testHandleExpiredTokenRenewSuccessAjax(): void
    {
        $this->sessionData = [
            SessionKey::OTP_TOKEN->value => self::token(
                referenceNo: 'expired-ref',
                platform: 'ideamart',
                usedApiUrl: 'url1',
                createdAt: time()
            ),
            SessionKey::PHONE_DATA->value => self::phone(
                telcoFormat: '0771234567',
                platform: 'ideamart',
                capiFormat: '94771234567'
            )
        ];
        $this->csrfServiceMock->method('validate')->willReturn(true);
        $this->rateLimiterMock->method('check')->willReturn(true);
        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::EXPIRED]);
        $this->otpServiceMock->method('getOtp')->willReturn([
            'outcome' => OtpRequestOutcome::SENT,
            'verificationToken' => self::token(
                referenceNo: 'new-ref-123',
                usedApiUrl: 'url1',
                createdAt: time()
            )
        ]);

        $request = new Request([], ['csrf_token' => 'valid', 'otp' => '123456']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = $this->controller->handleOtpForm($request);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Your OTP expired. A new OTP has been sent to your phone.', $data['message']);
    }
}