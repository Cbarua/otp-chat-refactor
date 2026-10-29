<?php
// tests/AnalyticsTrackerServiceTest.php

use PHPUnit\Framework\TestCase;
use App\Service\AnalyticsTrackerService;
use App\Service\FacebookCapiService;
use App\Service\UserInfoService;
use App\Service\SessionService;
use App\Service\SimpleUserLoggerService;
use App\DTO\PhoneNumber;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class AnalyticsTrackerServiceTest extends TestCase
{
    private array $config;
    private $capiServiceMock;
    private $userInfoServiceMock;
    private $userLoggerMock;
    private $loggerMock;
    private PhoneNumber $phone;
    private AnalyticsTrackerService $service;

    protected function setUp(): void
    {
        $_SESSION = ['visitor_id' => 'v_test123'];
        $this->config = ['facebook' => ['capi_token' => 'token123', 'pixel_id' => 'fb-pixel-123']];
        $this->capiServiceMock = $this->createMock(FacebookCapiService::class);
        $this->userInfoServiceMock = $this->createMock(UserInfoService::class);
        $this->userLoggerMock = $this->createMock(SimpleUserLoggerService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->phone = new PhoneNumber('0771234567', 'tel:94771234567', '94771234567', 'ideamart', 0.018);

        $this->userInfoServiceMock->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'PHPUnit']);
        $this->capiServiceMock->method('processRequest')->willReturn(['fbc' => 'fbc_1', 'fbp' => 'fbp_1', 'client_ip_address' => '127.0.0.1']);

        $this->service = $this->makeService($this->capiServiceMock);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function makeService(?FacebookCapiService $capiService): AnalyticsTrackerService
    {
        return new AnalyticsTrackerService(
            $this->config,
            $capiService,
            $this->userInfoServiceMock,
            new SessionService(),
            $this->userLoggerMock,
            $this->loggerMock
        );
    }

    public function testTrackPhoneFormSendsAPageViewAndLogsTheVisit(): void
    {
        $request = Request::create('http://localhost:8080/');
        $this->capiServiceMock->expects($this->once())->method('sendEvent')->with(
            'PageView',
            $this->stringStartsWith('pgview-'),
            'http://localhost:8080/',
            $this->callback(fn (array $userData) => $userData['phone'] === '94771234567')
        );
        $this->userLoggerMock->expects($this->once())->method('logVisit')->with('v_test123', '127.0.0.1', 'PHPUnit', null);

        $variables = $this->service->trackPhoneForm($request, $this->phone);

        $this->assertStringStartsWith('pgview-', $variables['pageViewEventId']);
        $this->assertSame($_SESSION['page_view_id'], $variables['pageViewEventId']);
        $this->assertSame('v_test123', $variables['externalId']);
        $this->assertSame('fb-pixel-123', $variables['pixelId']);
    }

    public function testTrackPhoneFormWithoutCapiLogsTheVisitOnly(): void
    {
        $request = Request::create('http://localhost:8080/');
        $this->userLoggerMock->expects($this->once())->method('logVisit');

        $variables = $this->makeService(null)->trackPhoneForm($request, null);

        $this->assertNull($variables['pageViewEventId']);
    }

    public function testTrackPhoneFormSkipsHeadRequests(): void
    {
        $request = Request::create('http://localhost:8080/', 'HEAD');

        $this->loggerMock->expects($this->never())->method('info');
        $this->userLoggerMock->expects($this->never())->method('logVisit');
        $this->capiServiceMock->expects($this->never())->method('sendEvent');

        $this->assertNull($this->service->trackPhoneForm($request, null)['pageViewEventId']);
    }

    public function testTrackPhoneFormSkipsThePageViewAndTheVisitWhileAnErrorShows(): void
    {
        $request = Request::create('http://localhost:8080/');
        $_SESSION['error_message'] = 'An error occurred';
        $_SESSION['page_view_id'] = 'pgview-earlier';

        $this->capiServiceMock->expects($this->never())->method('sendEvent');
        $this->userLoggerMock->expects($this->never())->method('logVisit');
        $this->loggerMock->expects($this->once())->method('notice');

        $this->assertSame('pgview-earlier', $this->service->trackPhoneForm($request, null)['pageViewEventId']);
    }

    public function testTrackPhoneFormAfterAlreadyRegisteredSendsAPageViewButLogsNoVisit(): void
    {
        $request = Request::create('http://localhost:8080/');
        $_SESSION['already_registered'] = 'You are registered';

        $this->capiServiceMock->expects($this->once())->method('sendEvent');
        $this->userLoggerMock->expects($this->never())->method('logVisit');

        $this->service->trackPhoneForm($request, $this->phone);
    }

    public function testTrackOtpFormSendsThePageViewAndTheLead(): void
    {
        $request = Request::create('http://localhost:8080/otp');
        $events = [];
        $this->capiServiceMock->expects($this->exactly(2))->method('sendEvent')
            ->willReturnCallback(function (string $name, string $id, string $url, array $userData, ?array $customData = null) use (&$events): array {
                $events[] = [$name, $id, $url, $customData];
                return [];
            });

        $variables = $this->service->trackOtpForm($request, $this->phone, 'lead-test-123');

        $this->assertSame(['PageView', $variables['pageViewEventId'], 'http://localhost:8080/otp', null], $events[0]);
        $this->assertSame(['Lead', 'lead-test-123', 'http://localhost:8080/otp', ['currency' => 'USD', 'value' => '0.018']], $events[1]);
        $this->assertSame($_SESSION['page_view_id_otp'], $variables['pageViewEventId']);
        $this->assertSame('lead-test-123', $variables['leadEventId']);
    }

    public function testTrackOtpFormWithoutAPendingLeadSendsOnlyThePageView(): void
    {
        $request = Request::create('http://localhost:8080/otp');
        $this->capiServiceMock->expects($this->once())->method('sendEvent')->with('PageView');

        $variables = $this->service->trackOtpForm($request, $this->phone, null);

        $this->assertNull($variables['leadEventId']);
    }

    public function testTrackThankYouPageSendsThePageViewAndTheRegistration(): void
    {
        $request = Request::create('http://localhost:8080/thanks');
        $events = [];
        $this->capiServiceMock->expects($this->exactly(2))->method('sendEvent')
            ->willReturnCallback(function (string $name, string $id, string $url, array $userData, ?array $customData = null) use (&$events): array {
                $events[] = [$name, $id, $url, $customData];
                return [];
            });

        $variables = $this->service->trackThankYouPage($request, $this->phone, 'reg-123');

        $this->assertSame(['PageView', $variables['pageViewEventId'], 'http://localhost:8080/thanks', null], $events[0]);
        $this->assertSame(['CompleteRegistration', 'reg-123', 'http://localhost:8080/thanks', ['currency' => 'USD', 'value' => '0.018']], $events[1]);
        $this->assertSame('reg-123', $variables['regId']);
        $this->assertSame('{"currency":"USD","value":"0.018"}', $variables['eventData']);
    }

    public function testTrackThankYouPageWithoutCapiStillReturnsThePixelConversion(): void
    {
        $request = Request::create('http://localhost:8080/thanks');
        // Only the visit line: no CompleteRegistration is logged without CAPI.
        $this->loggerMock->expects($this->once())->method('info')->with('New page visit. /thanks');

        $variables = $this->makeService(null)->trackThankYouPage($request, $this->phone, 'reg-123');

        $this->assertNull($variables['pageViewEventId']);
        $this->assertSame('reg-123', $variables['regId']);
        $this->assertSame('{"currency":"USD","value":"0.018"}', $variables['eventData']);
    }

    public function testLogVisitWithPhone(): void
    {
        $request = Request::create('http://localhost:8080/', 'POST', ['fbp' => 'fbp.1.test', 'fbc' => 'fbc.1.test']);

        $this->userLoggerMock->expects($this->once())->method('logVisit')->with(
            'v_test123',
            '127.0.0.1',
            'PHPUnit',
            '94771234567'
        );

        $this->service->logVisitWithPhone($request, $this->phone);

        $this->assertSame('fbp.1.test', $_SESSION['fbp']);
        $this->assertSame('fbc.1.test', $_SESSION['fbc']);
    }
}
