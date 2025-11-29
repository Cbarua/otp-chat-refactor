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

        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);
        $this->sessionServiceMock->method('get')->willReturnCallback(fn(string $key, $default = null) => $this->sessionData[$key] ?? $default);
        $this->sessionServiceMock->method('set')->willReturnCallback(function (string $key, $value): void {
            $this->sessionData[$key] = $value;
        });
        $this->sessionServiceMock->method('has')->willReturnCallback(fn(string $key): bool => isset($this->sessionData[$key]));
        $this->sessionServiceMock->method('unset')->willReturnCallback(function (string $key): void {
            unset($this->sessionData[$key]);
        });

        $this->controller = new TestableThankYouController($this->config, $this->capiServiceMock, $this->loggerMock, $this->userInfoServiceMock, $this->sessionServiceMock);
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
            'lead_id' => 'lead-abc'
        ];
        $request = Request::createFromGlobals();

        $this->capiServiceMock->expects($this->exactly(2))
            ->method('sendEvent')
            ->willReturnCallback(function (string $eventName, string $eventId, string $url, string $ip, string $userAgent, ?string $phone, ?array $customData) {
                if ($eventName === 'PageView') {
                    $this->assertStringContainsString('pgview-thanks-', $eventId);
                    $this->assertNull($phone);
                    $this->assertNull($customData);
                } elseif ($eventName === 'CompleteRegistration') {
                    $this->assertEquals('reg-test-12345', $eventId);
                    $this->assertEquals('94771234567', $phone);
                    $this->assertEquals(['currency' => 'USD', 'value' => 5.0], $customData);
                }
            });

        $this->controller->showThankYouPage($request);

        $this->assertEquals('thanks', $this->controller->renderedView);
        $this->assertNull($this->controller->redirectUrl);
        $this->assertEquals('reg-test-12345', $this->controller->renderData['regId']);
        $this->assertJsonStringEqualsJsonString('{"currency":"USD","value":5.0}', $this->controller->renderData['eventData']);

        // Assertions for session clearing
        $this->assertArrayNotHasKey('reg_id', $this->sessionData);
        $this->assertArrayNotHasKey('otp_token', $this->sessionData);

        // lead_id is NOT cleared by the controller, so we expect it to remain or we just don't check it.
        // If we want to be strict about what IS cleared:
        $this->assertArrayHasKey('lead_id', $this->sessionData);
        $this->assertArrayHasKey('phone_data', $this->sessionData);
    }
}
