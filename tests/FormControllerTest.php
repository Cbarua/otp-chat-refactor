<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Controller\FormController;
use App\DTO\OtpFlowResult;
use App\Service\FacebookCapiService;
use App\Service\UserLoggerInterface;
use App\Service\UserInfoService;
use App\Service\SessionService;
use App\Service\CsrfService;
use App\Service\AnalyticsTrackerService;
use App\Service\OtpFlowService;
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
    private MockObject|OtpFlowService $otpFlowServiceMock;
    private MockObject|UserLoggerInterface $userLoggerMock;
    private MockObject|FacebookCapiService $capiServiceMock;
    private MockObject|LoggerInterface $loggerMock;
    private MockObject|UserInfoService $userInfoServiceMock;
    private MockObject|SessionService $sessionServiceMock;
    private MockObject|CsrfService $csrfServiceMock;
    private AnalyticsTrackerService $analyticsTracker;
    private array $sessionData;
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->sessionData = [];

        $this->serverBackup = $_SERVER;
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
        ];

        $this->otpFlowServiceMock = $this->createMock(OtpFlowService::class);
        $this->userLoggerMock = $this->createMock(UserLoggerInterface::class);
        $this->capiServiceMock = $this->createMock(FacebookCapiService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->userInfoServiceMock = $this->createMock(UserInfoService::class);
        $this->sessionServiceMock = $this->createMock(SessionService::class);
        $this->csrfServiceMock = $this->createMock(CsrfService::class);

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

        $this->sessionData[FormController::SESSION_VISITOR_ID] = 'v_test123';

        $this->analyticsTracker = new AnalyticsTrackerService(
            $this->config,
            $this->capiServiceMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );

        $this->controller = new TestableFormController(
            $this->config,
            $this->otpFlowServiceMock,
            $this->analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
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
                        !isset($userData['country']); // Country should NOT be set for new visit
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

    public function testShowPhoneFormWithExistingPhoneDataPassesCountryToCapi(): void
    {
        $request = Request::createFromGlobals();
        $this->sessionData[FormController::SESSION_PHONE_DATA] = ['capi_format' => '94771234567'];

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('csrf-token-123');
        $this->capiServiceMock->expects($this->once())
            ->method('sendEvent')
            ->with(
                'PageView',
                $this->anything(),
                $this->anything(),
                $this->callback(function ($userData) {
                    return isset($userData['country']) && $userData['country'] === 'lk';
                })
            );

        $this->controller->showPhoneForm($request);

        $this->assertEquals('phone_form', $this->controller->renderedView);
    }

    public function testShowPhoneFormGeneratesFbcFromQueryParam(): void
    {
        $request = Request::createFromGlobals();
        $request->query->set('fbclid', 'test_fbclid_val');

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('csrf-token-123');

        // Stub processRequest to simulate CAPI service returning an FBC
        $this->capiServiceMock->method('processRequest')->willReturn([
            'fbc' => 'fb.1.1234567890.test_fbclid_val',
            'fbp' => 'fb.1.1234567890.1234567890'
        ]);

        $this->capiServiceMock->expects($this->once())
            ->method('sendEvent')
            ->with(
                'PageView',
                $this->anything(),
                $this->anything(),
                $this->callback(function ($userData) {
                    // Check if fbc is generated and passed
                    return !empty($userData['fbc']) &&
                        strpos($userData['fbc'], 'fb.1.') === 0 &&
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

    public function testShowPhoneFormClearsSmsFallbackSession(): void
    {
        $request = Request::createFromGlobals();
        $this->sessionData[FormController::SESSION_SHOW_SMS_LINK] = true;

        $this->csrfServiceMock->expects($this->once())->method('getToken')->willReturn('csrf-token-123');

        $this->controller->showPhoneForm($request);

        $this->assertArrayNotHasKey(FormController::SESSION_SHOW_SMS_LINK, $this->sessionData);
        $this->assertArrayNotHasKey(FormController::SESSION_INVALID_OTP_COUNT, $this->sessionData);
    }

    public function testShowPhoneFormCapiServiceNull(): void
    {
        $analyticsTrackerWithoutCapi = new AnalyticsTrackerService(
            $this->config,
            null,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );

        $controller = new TestableFormController(
            $this->config,
            $this->otpFlowServiceMock,
            $analyticsTrackerWithoutCapi,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->csrfServiceMock
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
        $this->otpFlowServiceMock->expects($this->never())->method('requestOtp');

        $this->controller->handlePhoneForm($request);

        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertEquals('Security check failed. Please try again.', $this->sessionData[FormController::SESSION_ERROR]);
    }

    public function testHandlePhoneFormAjaxCsrfFailure(): void
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'invalid-token']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $this->csrfServiceMock->expects($this->once())->method('validate')->with('invalid-token')->willReturn(false);
        $this->otpFlowServiceMock->expects($this->never())->method('requestOtp');

        $response = $this->controller->handlePhoneForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Security check failed. Please try again.', $data['message']);
    }

    public function testHandlePhoneFormHtmlRedirectsToServiceErrorUrlAndKeepsFlashMessages(): void
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'valid-token']);

        // Stands in for the service's session writes: the redirected page renders these, so the
        // HTML path must leave them alone (only the AJAX path clears them).
        $this->sessionData[FormController::SESSION_ERROR] = 'some message';
        $this->sessionData[FormController::SESSION_ALREADY_REGISTERED] = 'You are already registered!';

        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);
        $this->otpFlowServiceMock->expects($this->once())
            ->method('requestOtp')
            ->with($this->identicalTo($request), '0771234567')
            ->willReturn(OtpFlowResult::error('some message', './'));

        $response = $this->controller->handlePhoneForm($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertSame('some message', $this->sessionData[FormController::SESSION_ERROR]);
        $this->assertSame(
            'You are already registered!',
            $this->sessionData[FormController::SESSION_ALREADY_REGISTERED]
        );
    }

    public function testHandlePhoneFormHtmlRedirectsToServiceSuccessUrl(): void
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'valid-token']);

        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);
        $this->otpFlowServiceMock->expects($this->once())
            ->method('requestOtp')
            ->with($this->identicalTo($request), '0771234567')
            ->willReturn(OtpFlowResult::success('otp'));

        $response = $this->controller->handlePhoneForm($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('otp', $this->controller->redirectUrl);
    }

    public function testHandlePhoneFormAjaxReturnsServiceResultAndClearsFlashMessages(): void
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'valid-token']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        // Stale flash messages from an earlier HTML round trip must not leak into the next page render.
        $this->sessionData[FormController::SESSION_ERROR] = 'stale error';
        $this->sessionData[FormController::SESSION_ALREADY_REGISTERED] = 'stale already registered';

        $result = OtpFlowResult::error('Temporary system error', 'otp', true, 'otp');
        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);
        $this->otpFlowServiceMock->expects($this->once())
            ->method('requestOtp')
            ->with($this->identicalTo($request), '0771234567')
            ->willReturn($result);

        $response = $this->controller->handlePhoneForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame($result->toArray(), json_decode($response->getContent(), true));
        $this->assertArrayNotHasKey(FormController::SESSION_ERROR, $this->sessionData);
        $this->assertArrayNotHasKey(FormController::SESSION_ALREADY_REGISTERED, $this->sessionData);
    }

    public function testHandlePhoneFormAjaxSuccess(): void
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'valid-token']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $this->csrfServiceMock->expects($this->once())->method('validate')->with('valid-token')->willReturn(true);
        $this->otpFlowServiceMock->expects($this->once())
            ->method('requestOtp')
            ->with($this->identicalTo($request), '0771234567')
            ->willReturn(OtpFlowResult::success('otp'));

        $response = $this->controller->handlePhoneForm($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame(['status' => 'success', 'redirect' => 'otp'], json_decode($response->getContent(), true));
    }
}
