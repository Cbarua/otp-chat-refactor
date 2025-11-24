<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\FacebookCapiService;
use Mockery;
// FIX: We need this trait to handle Mockery's cleanup
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Psr\Log\LoggerInterface;

// We must "use" the classes we intend to overload with Mockery
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\UserData;
use FacebookAds\Object\ServerSide\CustomData;
use PHPUnit\Framework\Attributes\CoversClass;
// Import the attributes
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

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
    private Mockery\MockInterface|LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Reset global state before each test
        $_SESSION = [];
        $_COOKIE = [];
        $_GET = [];

        // 2. Create a mock logger
        $this->mockLogger = Mockery::mock(LoggerInterface::class);

        // 3. Define mock config
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
        // Expectation 1: From the constructor
        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with('FacebookCapiService: Pixel ID or Access Token is missing.');

        // Add expectation for the second error call
        // This makes the test correctly reflect what happens.
        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with('CAPI Error: sendEvent called but API not initialized.');

        // 3. Act
        $service = new FacebookCapiService($badConfig, $this->mockLogger);
        
        $response = $service->sendEvent(
            'PageView', 'evt_1', 'http://url.com', '127.0.0.1', 'TestAgent'
        );

        // 4. Assert: No event should be sent
        $this->assertNull($response);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInitializationThrowsException(): void
    {
        // 1. Arrange
        // We need to mock the static Api class
        $mockApi = Mockery::mock('alias:\FacebookAds\Api');

        // Tell this mock to EXPECT static 'init' method and THROW an exception
        $mockApi->shouldReceive('init')
            ->with(null, null, 'test-token-xyz')
            ->andThrow(new \Exception('Facebook SDK Down'))
            ->once();

        // We expect the logger to report this specific error (lines 41-42)
        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with('FacebookCapiService Init Error', Mockery::on(function ($context) {
                return $context['error'] === 'Facebook SDK Down';
            }));

        // 2. Act
        // We instantiate the service, which triggers the constructor
        new FacebookCapiService($this->config, $this->mockLogger);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSendEventWithAllDataAndCookies(): void
    {
        // 1. Arrange: Set up global state
        // Use valid Facebook formats so validation passes
        $_SESSION['fbp'] = 'fb.1.1698754321000.sessionfbp'; // Session fbp (should be ignored)
        $_COOKIE['_fbp'] = 'fb.1.1698754321000.cookiefbp'; // Cookie now takes priority!
        $_COOKIE['_fbc'] = 'fb.1.1698754321000.cookiefbc';
        
        $phone = '94771234567';
        $hashedPhone = hash('sha256', $phone);
        $customData = ['value' => 1.50, 'currency' => 'LKR'];
        $mockFbResponse = json_encode(['events_received' => 1]);

        // 2. Act (Mockery): Mock the Api class
        // Use fully qualified class name for 'alias:'**
        // This ensures Mockery creates the alias before the class is loaded.
        $mockApi = Mockery::mock('alias:\FacebookAds\Api');
        $mockApi->shouldReceive('init')
           ->with(null, null, 'test-token-xyz')
           ->once();

        // Mock the EventRequest class
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
                // Expect the COOKIE value, not the SESSION value
                $this->assertEquals('fb.1.1698754321000.cookiefbp', $userData->getFbp());
                $this->assertEquals('fb.1.1698754321000.cookiefbc', $userData->getFbc());
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


        // Mock Logger
        // This test should *not* log any init errors
        $this->mockLogger->shouldNotReceive('error');
        // It *should* log the info message
        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with('CAPI Event Sent', Mockery::on(function ($context) use ($mockFbResponse) {
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
            '1.2.3.4', 
            'TestAgent2', 
            $phone, 
            $customData
        );

        // 4. Assert
        $this->assertEquals(['events_received' => 1], $response);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSendEventGeneratesFbcFromGetParam(): void
    {
        // 1. Arrange
        $_SESSION['fbc'] = '';
        $_COOKIE['_fbc'] = '';
        $_GET['fbclid'] = 'testfbclid';
        
        $mockFbResponse = json_encode(['events_received' => 1]);

        // 2. Act (Mockery)
        $mockApi = Mockery::mock('alias:\FacebookAds\Api');
        $mockApi->shouldReceive('init')
           ->with(null, null, 'test-token-xyz')
           ->once();

        $mockEventRequest = Mockery::mock('overload:FacebookAds\Object\ServerSide\EventRequest');
        $mockEventRequest->shouldReceive('__construct')->with('fb-pixel-123')->once();
        $mockEventRequest->shouldReceive('setEvents')
            ->with(Mockery::on(function ($events) {
                $event = $events[0];
                $this->assertInstanceOf(Event::class, $event);
                $this->assertEquals('PageView', $event->getEventName());
                
                $userData = $event->getUserData();
                $this->assertStringStartsWith('fb.1.', $userData->getFbc());
                $this->assertStringEndsWith('testfbclid', $userData->getFbc());
                return true;
            }))
            ->once();
        $mockEventRequest->shouldReceive('setTestEventCode')->with('TEST123')->once();
        $mockEventRequest->shouldReceive('execute')
            ->andReturn($mockFbResponse)
            ->once();

        // 3. Mock Logger
        // This test should *not* log any init errors
        $this->mockLogger->shouldNotReceive('error');
        // It *should* log the info message
        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with('CAPI Event Sent', Mockery::on(function ($context) use ($mockFbResponse) {
                return $context['event_name'] === 'PageView' &&
                       $context['event_id'] === 'evt_pgview' &&
                       $context['response'] === json_decode($mockFbResponse, true);
            }));

        // 4. Act (Service)
        $service = new FacebookCapiService($this->config, $this->mockLogger);
        $response = $service->sendEvent(
            'PageView', 
            'evt_pgview', 
            'http://url.com/', 
            '1.2.3.4', 
            'TestAgent2'
        );

        // 5. Assert
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
        
        // Mock EventRequest to throw an exception
        $mockEventRequest = Mockery::mock('overload:FacebookAds\Object\ServerSide\EventRequest');
        $mockEventRequest->shouldReceive('__construct');
        $mockEventRequest->shouldReceive('setEvents');
        $mockEventRequest->shouldReceive('setTestEventCode');
        $mockEventRequest->shouldReceive('execute')
            ->andThrow(new \Exception('Facebook API Down'))
            ->once();


        // 2. Mock Logger
        // It *should* log the 'CAPI SendEvent Error'
        $this->mockLogger->shouldReceive('error')
            ->once()
            ->with('CAPI SendEvent Error', Mockery::on(function ($context) {
                return $context['event_id'] === 'evt_fail' &&
                       $context['error'] === 'Facebook API Down';
            }));
        // It should *not* log any other errors (like init errors)
        $this->mockLogger->shouldNotReceive('error')->with('FacebookCapiService Init Error');
        $this->mockLogger->shouldNotReceive('error')->with('CAPI Error: sendEvent called but API not initialized.');


        // 3. Act
        $service = new FacebookCapiService($this->config, $this->mockLogger);
        $response = $service->sendEvent(
            'PageView', 'evt_fail', 'http://url.com', '127.0.0.1', 'TestAgent'
        );

        // 4. Assert
        $this->assertNull($response, 'Service should return null on exception');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBadCookiesAreIgnored(): void
    {
        // 1. Arrange: Malicious or malformed data
        $_COOKIE['_fbp'] = '<script>alert(1)</script>'; // XSS attempt
        $_COOKIE['_fbc'] = 'just_random_text';          // Invalid format

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

        // Ensure no error logging occurs
        $this->mockLogger->shouldNotReceive('error');

        // We expect the success message (Line ~185)
        $this->mockLogger->shouldReceive('info')->once();

        // 3. Act
        $service = new FacebookCapiService($this->config, $this->mockLogger);
        $service->sendEvent('PageView', 'evt_1', 'http://site.com', '127.0.0.1', 'UA');
    }
}