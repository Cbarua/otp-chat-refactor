<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\FacebookCapiService;
use Mockery;
// FIX: We need this trait to handle Mockery's cleanup
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

// We must "use" the classes we intend to overload with Mockery
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\UserData;
use FacebookAds\Object\ServerSide\CustomData;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for FacebookCapiService.
 * This test file uses Mockery to mock the Facebook SDK.
 */
#[CoversClass(FacebookCapiService::class)]
class FacebookCapiServiceTest extends TestCase
{
    // FIX: Add the integration trait back in.
    // This handles Mockery::close() and handler cleanup.
    use MockeryPHPUnitIntegration;

    private array $config;
    private string $tempLogFile;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Reset global state before each test
        $_SESSION = [];
        $_COOKIE = [];
        $_GET = [];

        // 2. Define a temporary log file for testing file_put_contents
        $this->tempLogFile = sys_get_temp_dir() . '/capi_test.log';
        if (file_exists($this->tempLogFile)) {
            unlink($this->tempLogFile);
        }

        // 3. Define mock config (must be valid to pass constructor checks)
        $this->config = [
            'facebook' => [
                'pixel_id' => 'fb-pixel-123',
                'capi_token' => 'test-token-xyz', // Must be non-empty
                'test_event_code' => 'TEST123'
            ],
            'log_path' => [
                'capi' => $this->tempLogFile
            ]
        ];
    }

    protected function tearDown(): void
    {
        // Clean up the log file
        if (file_exists($this->tempLogFile)) {
            unlink($this->tempLogFile);
        }
        
        // Let the MockeryPHPUnitIntegration trait handle all cleanup
        parent::tearDown();
    }

    public function testInitializationFailsWithoutConfig(): void
    {
        // 1. Arrange: Create a bad config
        $badConfig = [
            'facebook' => [
                'pixel_id' => '', // FIX: Use empty string instead of null for string type
                'capi_token' => '', // FIX: Use empty string instead of null
                'test_event_code' => null
            ],
            'log_path' => ['capi' => $this->tempLogFile]
        ];

        // 2. Act: Instantiate service and call sendEvent
        // We expect error_log to be called, and apiInitialized to be false
        $service = new FacebookCapiService($badConfig);
        $response = $service->sendEvent(
            'PageView', 'evt_1', 'http://url.com', '127.0.0.1', 'TestAgent'
        );

        // 3. Assert: No event should be sent
        $this->assertNull($response);
    }

    public function testSendEventWithAllDataAndCookies(): void
    {
        // 1. Arrange: Set up global state
        $_SESSION['fbp'] = 'session.fbp.456'; // Session fbp (should be used)
        $_COOKIE['_fbp'] = 'cookie.fbp.123';  // Cookie fbp (should be ignored)
        $_COOKIE['_fbc'] = 'cookie.fbc.789';  // Cookie fbc
        
        $phone = '94771234567';
        $hashedPhone = hash('sha256', $phone);
        $customData = ['value' => 1.50, 'currency' => 'LKR'];
        $mockFbResponse = json_encode(['events_received' => 1]);

        // 2. Act (Mockery): Mock the EventRequest class
        // 'overload:' intercepts the 'new EventRequest(...)' call
        $mockEventRequest = Mockery::mock('overload:FacebookAds\Object\ServerSide\EventRequest');
        
        // Expect constructor to be called with our Pixel ID
        $mockEventRequest->shouldReceive('__construct')->with('fb-pixel-123')->once();

        // Expect setEvents to be called with a valid Event object
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
                $this->assertEquals('session.fbp.456', $userData->getFbp());
                $this->assertEquals('cookie.fbc.789', $userData->getFbc());
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

        // Expect test code to be set
        $mockEventRequest->shouldReceive('setTestEventCode')->with('TEST123')->once();

        // Expect execute() to be called and return our mock response
        $mockEventRequest->shouldReceive('execute')
            ->andReturn($mockFbResponse)
            ->once();


        // 3. Act (Service): Instantiate and call the real service method
        $service = new FacebookCapiService($this->config);
        $response = $service->sendEvent(
            'Purchase', 
            'evt_purchase', 
            'http://url.com/thanks', 
            '1.2.3.4', 
            'TestAgent2', 
            $phone, 
            $customData
        );

        // 4. Assert
        $this->assertEquals(['events_received' => 1], $response);
        $this->assertFileExists($this->tempLogFile);
        $logContent = file_get_contents($this->tempLogFile);
        $this->assertStringContainsString('event_id:evt_purchase', $logContent);
        $this->assertStringContainsString('response:' . $mockFbResponse, $logContent);
    }

    public function testApiExceptionIsCaught(): void
    {
        // 1. Arrange (Mockery): Mock EventRequest to throw an exception
        $mockEventRequest = Mockery::mock('overload:FacebookAds\Object\ServerSide\EventRequest');
        $mockEventRequest->shouldReceive('__construct');
        $mockEventRequest->shouldReceive('setEvents');
        $mockEventRequest->shouldReceive('setTestEventCode');
        $mockEventRequest->shouldReceive('execute')
            ->andThrow(new \Exception('Facebook API Down'))
            ->once();

        // 2. Act
        $service = new FacebookCapiService($this->config);
        $response = $service->sendEvent(
            'PageView', 'evt_fail', 'http://url.com', '127.0.0.1', 'TestAgent'
        );

        // 3. Assert
        $this->assertNull($response, 'Service should return null on exception');
        $this->assertFileDoesNotExist(
            $this->tempLogFile, 'Log file should not be written on exception'
        );
    }
}
