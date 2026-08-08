<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\FacebookCapiService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Psr\Log\LoggerInterface;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\UserData;
use FacebookAds\Object\ServerSide\CustomData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/**
 * Unit tests for FacebookCapiService.
 * This test file uses Mockery to mock the Facebook SDK.
 */
#[CoversClass(FacebookCapiService::class)]
class FacebookCapiServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private array $config;
    private Mockery\MockInterface|LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create a mock logger
        $this->mockLogger = Mockery::mock(LoggerInterface::class);

        // 2. Define mock config
        $this->config = [
            'facebook' => [
                'pixel_id' => 'fb-pixel-123',
                'capi_token' => 'test-token-xyz',
                'test_event_code' => 'TEST123'
            ],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInitializationFailsWithoutConfig(): void
    {
        // 1. Arrange: Create a bad config
        $badConfig = [
            'facebook' => ['pixel_id' => '', 'capi_token' => '']
        ];

        // 2. Set Expectations
        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with('CAPI: Pixel ID or Access Token is missing.');

        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with('CAPI: sendEvent called but API not initialized.');

        // 3. Act
        $service = new FacebookCapiService($badConfig, $this->mockLogger);

        $userDataArray = ['ip' => '127.0.0.1', 'agent' => 'TestAgent'];
        $response = $service->sendEvent(
            'PageView',
            'evt_1',
            'http://url.com',
            $userDataArray
        );

        // 4. Assert: No event should be sent
        $this->assertNull($response);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInitializationThrowsException(): void
    {
        // 1. Arrange
        $mockApi = Mockery::mock('alias:\FacebookAds\Api');

        $mockApi->shouldReceive('init')
            ->with(null, null, 'test-token-xyz')
            ->andThrow(new \Exception('Facebook SDK Down'))
            ->once();

        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with('CAPI: Init Error', Mockery::on(function ($context) {
                return $context['error'] === 'Facebook SDK Down';
            }));

        // 2. Act
        new FacebookCapiService($this->config, $this->mockLogger);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSendEventWithAllData(): void
    {
        // 1. Arrange
        $phone = '94771234567';
        $hashedPhone = hash('sha256', $phone);
        $customData = ['value' => 1.50, 'currency' => 'LKR'];
        $mockFbResponse = json_encode(['events_received' => 1]);

        $userDataArray = [
            'ip' => '1.2.3.4',
            'agent' => 'TestAgent2',
            'phone' => $phone,
            'fbp' => 'fb.1.1698754321000.sessionfbp',
            'fbc' => 'fb.1.1698754321000.sessionfbc',
            'external_id' => 'vis_123',
            'country' => 'lk'
        ];

        // 2. Act (Mockery)
        $mockApi = Mockery::mock('alias:\FacebookAds\Api');
        $mockApi->shouldReceive('init')
            ->with(null, null, 'test-token-xyz')
            ->once();

        $mockEventRequest = Mockery::mock('overload:FacebookAds\Object\ServerSide\EventRequest');

        $mockEventRequest->shouldReceive('__construct')->with('fb-pixel-123')->once();

        $mockEventRequest->shouldReceive('setEvents')
            ->with(Mockery::on(function ($events) use ($hashedPhone) {
                $this->assertIsArray($events);
                $this->assertCount(1, $events);
                $event = $events[0];

                $this->assertInstanceOf(Event::class, $event);
                $this->assertEquals('Purchase', $event->getEventName());
                $this->assertEquals('evt_purchase', $event->getEventId());

                // Check UserData
                $userData = $event->getUserData();
                $this->assertInstanceOf(UserData::class, $userData);
                // Expect the COOKIE value, not the SESSION value
                $this->assertEquals('fb.1.1698754321000.sessionfbp', $userData->getFbp());
                $this->assertEquals('fb.1.1698754321000.sessionfbc', $userData->getFbc());
                $this->assertEquals($hashedPhone, $userData->getPhone());
                $this->assertEquals('1.2.3.4', $userData->getClientIpAddress());

                // Check CustomData
                $customData = $event->getCustomData();
                $this->assertInstanceOf(CustomData::class, $customData);
                $this->assertEquals(1.50, $customData->getValue());
                $this->assertEquals('LKR', $customData->getCurrency());

                return true; // Return true to confirm the argument matches
            }))
            ->once();

        $mockEventRequest->shouldReceive('setTestEventCode')->with('TEST123')->once();

        $mockEventRequest->shouldReceive('execute')
            ->andReturn($mockFbResponse)
            ->once();

        $this->mockLogger->shouldNotReceive('error');
        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with('CAPI: Event Sent', Mockery::on(function ($context) use ($mockFbResponse) {
                return $context['event_name'] === 'Purchase' &&
                    $context['event_id'] === 'evt_purchase' &&
                    $context['response'] === json_decode($mockFbResponse, true);
            }));

        // 3. Act (Service)
        $service = new FacebookCapiService($this->config, $this->mockLogger);
        $response = $service->sendEvent(
            'Purchase',
            'evt_purchase',
            'http://url.com/thanks',
            $userDataArray,
            $customData
        );

        // 4. Assert
        $this->assertEquals(['events_received' => 1], $response);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApiExceptionIsCaught(): void
    {
        // 1. Arrange (Mockery)
        $mockApi = Mockery::mock('alias:\FacebookAds\Api');
        $mockApi->shouldReceive('init')
            ->with(null, null, 'test-token-xyz')
            ->once();

        $mockEventRequest = Mockery::mock('overload:FacebookAds\Object\ServerSide\EventRequest');
        $mockEventRequest->shouldReceive('__construct');
        $mockEventRequest->shouldReceive('setEvents');
        $mockEventRequest->shouldReceive('setTestEventCode');
        $mockEventRequest->shouldReceive('execute')
            ->andThrow(new \Exception('Facebook API Down'))
            ->once();

        // 2. Mock Logger
        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with('CAPI: SendEvent Failed', Mockery::on(function ($context) {
                return $context['event_id'] === 'evt_fail' &&
                    $context['error'] === 'Facebook API Down';
            }));
        $this->mockLogger->shouldNotReceive('error')->with('FacebookCapiService Init Error');
        $this->mockLogger->shouldNotReceive('error')->with('CAPI Error: sendEvent called but API not initialized.');

        // 3. Act
        $service = new FacebookCapiService($this->config, $this->mockLogger);
        $userDataArray = ['ip' => '127.0.0.1', 'agent' => 'TestAgent'];
        $response = $service->sendEvent(
            'PageView',
            'evt_fail',
            'http://url.com',
            $userDataArray
        );

        // 4. Assert
        $this->assertNull($response, 'Service should return null on exception');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBadCookiesAreIgnored(): void
    {
        // 1. Arrange: Malicious or malformed data
        $userDataArray = [
            'ip' => '127.0.0.1',
            'agent' => 'UA',
            'fbp' => '<script>alert(1)</script>', // XSS attempt
            'fbc' => 'just_random_text'           // Invalid format
        ];

        $mockFbResponse = json_encode(['events_received' => 1]);

        $mockApi = Mockery::mock('alias:\FacebookAds\Api');
        $mockApi->shouldReceive('init')
           ->with(null, null, 'test-token-xyz')
           ->once();

        // 2. Expectation: We verify FBP/FBC are NULL (ignored)
        $mockEventRequest = Mockery::mock('overload:FacebookAds\Object\ServerSide\EventRequest');
        $mockEventRequest->shouldReceive('__construct');

        $mockEventRequest->shouldReceive('setEvents')
            ->with(Mockery::on(function ($events) {
                $userData = $events[0]->getUserData();

                // Assert that the bad data was filtered out
                $this->assertNull($userData->getFbp(), 'Bad FBP should be ignored');
                $this->assertNull($userData->getFbc(), 'Bad FBC should be ignored');
                return true;
            }))
            ->once();

        $mockEventRequest->shouldReceive('setTestEventCode');
        $mockEventRequest->shouldReceive('execute')->andReturn($mockFbResponse)
            ->once();

        $this->mockLogger->shouldNotReceive('error');
        $this->mockLogger->shouldReceive('info')->once();

        // 3. Act
        $service = new FacebookCapiService($this->config, $this->mockLogger);
        $service->sendEvent('PageView', 'evt_1', 'http://site.com', $userDataArray);
    }
}