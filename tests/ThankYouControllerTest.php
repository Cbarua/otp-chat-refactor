<?php
// tests/ThankYouControllerTest.php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use App\Controller\ThankYouController;
use App\Service\FacebookCapiService;
use App\Service\SessionService;
use App\Service\UserInfoService;
use App\Service\UserLoggerInterface;
use App\Service\AnalyticsTrackerService;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TestableThankYouController extends ThankYouController
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

#[CoversClass(ThankYouController::class)]
class ThankYouControllerTest extends TestCase
{
    private TestableThankYouController $controller;
    private array $config;
    private MockObject|FacebookCapiService|null $capiServiceMock;
    private MockObject|LoggerInterface $loggerMock;
    private MockObject|UserInfoService $userInfoServiceMock;
    private MockObject|SessionService $sessionServiceMock;
    private MockObject|UserLoggerInterface $userLoggerMock;
    private AnalyticsTrackerService $analyticsTracker;
    private array $sessionData;

    protected function setUp(): void
    {
        $this->sessionData = [];
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REQUEST_URI'] = '/thanks';
        $_SERVER['HTTPS'] = 'on';

        $this->config = ['facebook' => ['pixel_id' => 'fb-pixel-123', 'test_event_code' => 'TEST12345']];

        $this->capiServiceMock = $this->createMock(FacebookCapiService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->userInfoServiceMock = $this->createMock(UserInfoService::class);
        $this->sessionServiceMock = $this->createMock(SessionService::class);
        $this->userLoggerMock = $this->createMock(UserLoggerInterface::class);

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

        $this->controller = new TestableThankYouController(
            $this->config,
            $this->analyticsTracker,
            $this->loggerMock,
            $this->sessionServiceMock
        );
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
    }

    public function testShowThankYouPageFailsWithoutSession(): void
    {
        $this->controller->showThankYouPage(Request::createFromGlobals());
        $this->assertEquals('./', $this->controller->redirectUrl);
        $this->assertNull($this->controller->renderedView);
        $this->capiServiceMock->expects($this->never())->method('sendEvent');
    }

    public function testShowThankYouPageSuccess(): void
    {
        $this->sessionData = [
            'reg_id' => 'reg-test-12345',
            'phone_data' => ['platform' => 'ideamart', 'capi_format' => '94771234567', 'value' => 5.0],
            'otp_token' => 'otp-xyz',
            'lead_id' => 'lead-abc',
            'visitor_id' => 'v_test123',
            'fbp' => 'fb.1.test_fbp',
            'fbc' => 'fb.1.test_fbc'
        ];
        $request = Request::createFromGlobals();

        $this->capiServiceMock->expects($this->exactly(2))
            ->method('sendEvent')
            ->willReturnCallback(function (string $eventName, string $eventId, string $url, array $userData, ?array $customData = null) {
                if ($eventName === 'PageView') {
                    $this->assertStringContainsString('pgview-thanks-', $eventId);
                    $this->assertEquals('127.0.0.1', $userData['ip']);
                    $this->assertEquals('TestAgent', $userData['agent']);
                    $this->assertEquals('v_test123', $userData['external_id']);
                    $this->assertEquals('fb.1.test_fbp', $userData['fbp']);
                    $this->assertEquals('fb.1.test_fbc', $userData['fbc']);
                    $this->assertEquals('lk', $userData['country']);
                    $this->assertNull($customData);
                } elseif ($eventName === 'CompleteRegistration') {
                    $this->assertEquals('reg-test-12345', $eventId);
                    $this->assertEquals('94771234567', $userData['phone']);
                    $this->assertEquals(['currency' => 'USD', 'value' => '5'], $customData);
                }
                return [];
            });

        $this->controller->showThankYouPage($request);

        $this->assertEquals('thanks', $this->controller->renderedView);
        $this->assertNull($this->controller->redirectUrl);
        $this->assertEquals('reg-test-12345', $this->controller->renderData['regId']);
        $this->assertJsonStringEqualsJsonString('{"currency":"USD","value":"5"}', $this->controller->renderData['eventData']);
        $this->assertEquals('v_test123', $this->controller->renderData['externalId']);
        $this->assertEquals('lk', $this->controller->renderData['country']);

        // Assertions for session clearing
        $this->assertArrayNotHasKey('reg_id', $this->sessionData);
        $this->assertArrayNotHasKey('otp_token', $this->sessionData);

        // lead_id is NOT cleared by the controller, so we expect it to remain or we just don't check it.
        $this->assertArrayHasKey('lead_id', $this->sessionData);
        $this->assertArrayHasKey('phone_data', $this->sessionData);
    }
}

