<?php
// tests/AnalyticsTrackerServiceTest.php

use PHPUnit\Framework\TestCase;
use App\Service\AnalyticsTrackerService;
use App\Service\FacebookCapiService;
use App\Service\UserInfoService;
use App\Service\SessionService;
use App\Service\UserLoggerInterface;
use App\DTO\PhoneNumber;
use App\Enum\SessionKey;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class AnalyticsTrackerServiceTest extends TestCase
{
    private array $config;
    private $capiServiceMock;
    private $userInfoServiceMock;
    private $sessionServiceMock;
    private $userLoggerMock;
    private $loggerMock;
    private AnalyticsTrackerService $service;

    protected function setUp(): void
    {
        $this->config = ['facebook' => ['capi_token' => 'token123']];
        $this->capiServiceMock = $this->createMock(FacebookCapiService::class);
        $this->userInfoServiceMock = $this->createMock(UserInfoService::class);
        $this->sessionServiceMock = $this->createMock(SessionService::class);
        $this->userLoggerMock = $this->createMock(UserLoggerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->service = new AnalyticsTrackerService(
            $this->config,
            $this->capiServiceMock,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );
    }

    public function testGetUserInfoAndVisitorId(): void
    {
        $request = Request::create('http://localhost:8080/');
        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'PHPUnit']);
        $this->sessionServiceMock->method('get')->with(SessionKey::VISITOR_ID)->willReturn('v_test123');

        $info = $this->service->getUserInfo($request);
        $this->assertEquals('127.0.0.1', $info['ip']);
        $this->assertEquals('v_test123', $this->service->getVisitorId());
    }

    public function testTrackPageViewWithCapi(): void
    {
        $request = Request::create('http://localhost:8080/');
        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'PHPUnit']);
        $this->sessionServiceMock->method('get')->willReturnCallback(function ($key) {
            return match ($key) {
                SessionKey::VISITOR_ID => 'v_test123',
                SessionKey::PHONE_DATA => ['capi_format' => '94771234567'],
                default => null,
            };
        });

        $this->capiServiceMock->method('processRequest')->willReturn(['fbc' => 'fbc_1', 'fbp' => 'fbp_1', 'client_ip_address' => '127.0.0.1']);
        $this->capiServiceMock->expects($this->once())->method('sendEvent')->with(
            'PageView',
            $this->stringStartsWith('pgview-'),
            'http://localhost:8080/',
            $this->isType('array')
        );

        $this->userLoggerMock->expects($this->once())->method('logVisit');

        $eventId = $this->service->trackPageView($request, '/', 'pgview-', null, true);
        $this->assertNotNull($eventId);
        $this->assertStringStartsWith('pgview-', $eventId);
    }

    public function testTrackPageViewWithoutCapi(): void
    {
        $serviceWithoutCapi = new AnalyticsTrackerService(
            $this->config,
            null,
            $this->userInfoServiceMock,
            $this->sessionServiceMock,
            $this->userLoggerMock,
            $this->loggerMock
        );

        $request = Request::create('http://localhost:8080/');
        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'PHPUnit']);
        $this->userLoggerMock->expects($this->once())->method('logVisit');

        $eventId = $serviceWithoutCapi->trackPageView($request, '/', 'pgview-', null, true);
        $this->assertNull($eventId);
    }

    public function testTrackLead(): void
    {
        $request = Request::create('http://localhost:8080/otp');
        $phone = new PhoneNumber('0771234567', 'tel:94771234567', '94771234567', 'ideamart', 0.018);

        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'PHPUnit']);
        $this->sessionServiceMock->method('get')->willReturnCallback(function ($key) {
            return match ($key) {
                SessionKey::VISITOR_ID => 'v_test123',
                SessionKey::FBP => 'fbp_123',
                SessionKey::FBC => 'fbc_123',
                default => null,
            };
        });

        $this->capiServiceMock->method('processRequest')->willReturn(['client_ip_address' => '127.0.0.1']);
        $this->capiServiceMock->expects($this->once())->method('sendEvent')->with(
            'Lead',
            'lead-test-123',
            'http://localhost:8080/otp',
            $this->isType('array'),
            $this->isType('array')
        );

        $leadId = $this->service->trackLead($request, $phone, 'lead-test-123');
        $this->assertEquals('lead-test-123', $leadId);
    }

    public function testTrackCompleteRegistration(): void
    {
        $request = Request::create('http://localhost:8080/thanks');
        $phone = new PhoneNumber('0771234567', 'tel:94771234567', '94771234567', 'ideamart', 0.018);

        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'PHPUnit']);
        $this->sessionServiceMock->method('get')->willReturnCallback(function ($key) {
            return match ($key) {
                SessionKey::VISITOR_ID => 'v_test123',
                default => null,
            };
        });

        $this->capiServiceMock->method('processRequest')->willReturn(['client_ip_address' => '127.0.0.1']);
        $this->capiServiceMock->expects($this->once())->method('sendEvent')->with(
            'CompleteRegistration',
            'reg-123',
            'http://localhost:8080/thanks',
            $this->isType('array'),
            $this->isType('array')
        );

        $regId = $this->service->trackCompleteRegistration($request, $phone, 'reg-123');
        $this->assertEquals('reg-123', $regId);
    }
}
