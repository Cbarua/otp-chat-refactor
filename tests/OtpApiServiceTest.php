<?php
// tests/OtpApiServiceTest.php

use PHPUnit\Framework\TestCase;
use App\Enum\OtpRequestOutcome;
use App\Enum\OtpVerifyOutcome;
use App\Service\OtpApiService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

class OtpApiServiceTest extends TestCase
{
    private MockObject|LoggerInterface $loggerMock;
    private array $apiConfig;

    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->apiConfig = [
            'ideamart' => [
                'https://ideamart.mock/primary/',
                'https://ideamart.mock/fallback/'
            ],
            'mspace' => [
                'https://mspace.mock/'
            ]
        ];
    }

    private function createMockClient(array $queue): Client
    {
        $mock = new MockHandler($queue);
        $handlerStack = HandlerStack::create($mock);
        return new Client(['handler' => $handlerStack]);
    }

    public function testGetOtpSuccess(): void
    {
        $mockClient = $this->createMockClient([
            new Response(200, [], json_encode(['statusCode' => 'S1000', 'referenceNo' => '12345-abc']))
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $this->loggerMock->expects($this->never())->method('error');

        $result = $service->getOtp('mspace', 'tel:94711234567', []);

        $this->assertIsArray($result);
        $this->assertSame(OtpRequestOutcome::SENT, $result['outcome']);
        $this->assertEquals('12345-abc', $result['verificationToken']['referenceNo']);
    }

    public function testGetOtpFirstUrlFailsThenSecondSucceeds(): void
    {
        $mockClient = $this->createMockClient([
            new RequestException("Error Communicating with Server", new Request('POST', 'test')),
            new Response(200, [], json_encode(['statusCode' => 'S1000', 'referenceNo' => '67890-def']))
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $result = $service->getOtp('ideamart', 'tel:94771234567', []);

        $this->assertIsArray($result);
        $this->assertSame(OtpRequestOutcome::SENT, $result['outcome']);
        $this->assertEquals('67890-def', $result['verificationToken']['referenceNo']);
        $this->assertEquals('https://ideamart.mock/fallback/', $result['verificationToken']['usedApiUrl']);
    }

    public function testGetOtpAllUrlsFail(): void
    {
        $mockClient = $this->createMockClient([
            new RequestException("Error first API", new Request('POST', 'test')),
            new RequestException("Error second API", new Request('POST', 'test'))
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $result = $service->getOtp('ideamart', 'tel:94771234567', []);

        $this->assertSame(OtpRequestOutcome::FAILED, $result['outcome']);
        // first api call failed
        $this->assertEquals('API request failed', $result['failedAttempts'][0]['response']['message']);
        // second api call failed
        $this->assertEquals('API request failed', $result['finalResponse']['message']);
    }

    public function testGetOtpUnconfiguredPlatform(): void
    {
        $mockClient = $this->createMockClient([]);
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $result = $service->getOtp('unconfigured', 'tel:94771234567', []);

        $this->assertEquals([
            'outcome' => OtpRequestOutcome::FAILED,
            'message' => 'Url not found for platform.'
        ], $result);
    }

    public function testVerifyOtpSuccess(): void
    {
        $mockClient = $this->createMockClient([
            new Response(200, [], json_encode(['statusCode' => 'S1000', 'subscriptionStatus' => 'REGISTERED']))
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $token = ['referenceNo' => 'ref-999', 'usedApiUrl' => 'https://mspace.mock/'];
        $result = $service->verifyOtp($token, '123456');

        $this->assertIsArray($result);
        $this->assertSame(OtpVerifyOutcome::VERIFIED, $result['outcome']);
        $this->assertEquals('REGISTERED', $result['originalResponse']['subscriptionStatus']);
    }

    public function testRequestReturnsInvalidJson(): void
    {
        $mockClient = $this->createMockClient([
            new Response(200, [], '<html>This is not json</html>')
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $result = $service->getOtp('mspace', 'tel:94771234567', []);

        // The service returns the last response structure, which in this case is the error from sendRequest
        $this->assertSame(OtpRequestOutcome::FAILED, $result['outcome']);
        $this->assertEquals('Invalid JSON response from API', $result['finalResponse']['message']);
    }

    public function testRequestThrowsRequestExceptionDuringVerify(): void
    {
        $mockClient = $this->createMockClient([
            new RequestException("Error Communicating with Server", new Request('POST', 'test'))
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $token = ['referenceNo' => 'ref-999', 'usedApiUrl' => 'https://mspace.mock/'];
        $result = $service->verifyOtp($token, '123456');

        $this->assertSame(OtpVerifyOutcome::PROVIDER_ERROR, $result['outcome']);
        $this->assertEquals('API request failed', $result['originalResponse']['message']);
    }

    public function testGetOtpAllUrlsExcluded(): void
    {
        $mockClient = $this->createMockClient([]);
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $excludedUrls = ['https://ideamart.mock/primary/', 'https://ideamart.mock/fallback/'];
        $result = $service->getOtp('ideamart', 'tel:94771234567', [], $excludedUrls);

        $this->assertEquals([
            'outcome' => OtpRequestOutcome::FAILED,
            'message' => 'No available API endpoints to try.'
        ], $result);
    }

    public function testVerifyOtpFailsWithInvalidToken(): void
    {
        $mockClient = $this->createMockClient([]);
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        // Test with missing URL
        $result1 = $service->verifyOtp(['referenceNo' => 'ref-123'], '123456');
        $this->assertEquals([
            'outcome' => OtpVerifyOutcome::PROVIDER_ERROR,
            'message' => 'Invalid verification token.'
        ], $result1);

        // Test with missing reference number
        $result2 = $service->verifyOtp(['usedApiUrl' => 'https://mspace.mock/'], '123456');
        $this->assertEquals([
            'outcome' => OtpVerifyOutcome::PROVIDER_ERROR,
            'message' => 'Invalid verification token.'
        ], $result2);
    }

    public function testRequestThrowsGenericException(): void
    {
        $mockClient = $this->createMockClient([
            new \Exception("Random system error")
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $result = $service->getOtp('mspace', 'tel:94771234567', []);

        $this->assertSame(OtpRequestOutcome::FAILED, $result['outcome']);
        $this->assertEquals('A system error occurred', $result['finalResponse']['message']);
    }

    public function testGetOtpReturnsTimestamp(): void
    {
        $mockClient = $this->createMockClient([
            new Response(200, [], json_encode(['statusCode' => 'S1000', 'referenceNo' => '12345-abc']))
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $result = $service->getOtp('mspace', 'tel:94711234567', []);

        $this->assertSame(OtpRequestOutcome::SENT, $result['outcome']);
        $this->assertArrayHasKey('createdAt', $result['verificationToken']);
        $this->assertIsInt($result['verificationToken']['createdAt']);
    }

    public function testForwardsTestCookiesToTheProvider(): void
    {
        $handler = new MockHandler([new Response(200, [], json_encode(['statusCode' => 'S1000', 'referenceNo' => '1']))]);
        $client = new Client(['handler' => HandlerStack::create($handler)]);
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $client, ['TEST_LOG_DIR' => '/tmp/test logs', 'APP_ENV' => 'testing']);

        $service->getOtp('mspace', 'tel:94711234567', []);

        $this->assertSame('TEST_LOG_DIR=%2Ftmp%2Ftest%20logs; APP_ENV=testing', $handler->getLastRequest()->getHeaderLine('Cookie'));
    }

    public function testIgnoresVisitorCookiesWithoutTestCookies(): void
    {
        $handler = new MockHandler([new Response(200, [], json_encode(['statusCode' => 'S1000', 'referenceNo' => '1']))]);
        $client = new Client(['handler' => HandlerStack::create($handler)]);
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $client);

        $_COOKIE['TEST_LOG_DIR'] = '/tmp/evil';
        $service->getOtp('mspace', 'tel:94711234567', []);
        unset($_COOKIE['TEST_LOG_DIR']);

        $this->assertFalse($handler->getLastRequest()->hasHeader('Cookie'));
    }

    public static function requestOutcomeProvider(): array
    {
        // Final replies as production logged them; mapped by statusCode, never by statusDetail.
        return [
            'already registered' => [['statusCode' => 'E1351', 'statusDetail' => 'user already registered'], OtpRequestOutcome::ALREADY_REGISTERED],
            'temporary failure' => [['statusCode' => 'E1603', 'statusDetail' => 'Temporary System Error occurred while delivering your request.'], OtpRequestOutcome::TEMPORARY_FAILURE],
            'max requests' => [['statusCode' => 'E1853', 'statusDetail' => 'Maximum number of OTP requests reached for [app/tel:94771234567]'], OtpRequestOutcome::MAX_REQUESTS],
            'blacklisted' => [['statusCode' => 'E1342', 'statusDetail' => 'Sorry, Your phone number is blacklisted to use this application app.'], OtpRequestOutcome::FAILED],
            'middle-layer error' => [['status' => 'error', 'statusCode' => 'E1000', 'statusDetail' => 'Missing fields in telco response'], OtpRequestOutcome::FAILED],
            'prose without a code' => [['statusDetail' => 'user already registered'], OtpRequestOutcome::FAILED],
        ];
    }

    #[DataProvider('requestOutcomeProvider')]
    public function testGetOtpMapsTheFinalStatusCode(array $reply, OtpRequestOutcome $expected): void
    {
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $this->createMockClient([
            new Response(200, [], json_encode($reply))
        ]));

        $result = $service->getOtp('mspace', 'tel:94711234567', []);

        $this->assertSame($expected, $result['outcome']);
        $this->assertSame('https://mspace.mock/', $result['finalUrl']);
    }

    public function testGetOtpListsTheAppsThatSaidAlreadyRegistered(): void
    {
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $this->createMockClient([
            new Response(200, [], json_encode(['statusCode' => 'E1342', 'statusDetail' => 'Sorry, Your phone number is blacklisted'])),
            new Response(200, [], json_encode(['statusCode' => 'E1351', 'statusDetail' => 'user already registered'])),
        ]));

        $result = $service->getOtp('ideamart', 'tel:94771234567', []);

        $this->assertSame(OtpRequestOutcome::ALREADY_REGISTERED, $result['outcome']);
        $this->assertSame(['https://ideamart.mock/fallback/'], $result['alreadyRegisteredUrls']);
        $this->assertCount(2, $result['failedAttempts']);
    }

    public static function verifyOutcomeProvider(): array
    {
        return [
            'registered' => [['statusCode' => 'S1000', 'subscriptionStatus' => 'REGISTERED'], OtpVerifyOutcome::VERIFIED],
            'ideamart pending charge' => [['statusCode' => 'S1000', 'subscriptionStatus' => 'INITIAL CHARGING PENDING'], OtpVerifyOutcome::VERIFIED],
            'mspace pending charge' => [['statusCode' => 'S1000', 'subscriptionStatus' => 'INITIAL_CHARGING_PENDING'], OtpVerifyOutcome::VERIFIED],
            'mspace reg pending' => [['statusCode' => 'S1000', 'subscriptionStatus' => 'REG_PENDING'], OtpVerifyOutcome::NOT_SUBSCRIBED],
            'no subscription status' => [['statusCode' => 'S1000'], OtpVerifyOutcome::NOT_SUBSCRIBED],
            'invalid' => [['status' => 'Invalid OTP', 'statusCode' => 'E1850', 'statusDetail' => 'Invalid OTP'], OtpVerifyOutcome::INVALID],
            'not found' => [['status' => 'Could not find OTP', 'statusCode' => 'E1854', 'statusDetail' => 'Could not find OTP'], OtpVerifyOutcome::NOT_FOUND],
            'expired' => [['status' => 'OTP request has being expired', 'statusCode' => 'E1851', 'statusDetail' => 'OTP request has being expired'], OtpVerifyOutcome::EXPIRED],
            'attempts exhausted' => [['statusCode' => 'E1852', 'statusDetail' => 'Maximum number of OTP attempts had reached'], OtpVerifyOutcome::PROVIDER_ERROR],
            'request limit code on verify' => [['statusCode' => 'E1853'], OtpVerifyOutcome::PROVIDER_ERROR],
            'middle-layer error' => [['status' => 'error', 'statusCode' => 'E500', 'statusDetail' => 'Internal Error'], OtpVerifyOutcome::PROVIDER_ERROR],
            'success status without a code' => [['status' => 'success', 'subscriptionStatus' => 'REGISTERED'], OtpVerifyOutcome::PROVIDER_ERROR],
        ];
    }

    #[DataProvider('verifyOutcomeProvider')]
    public function testVerifyOtpMapsStatusCodeAndSubscriptionStatus(array $reply, OtpVerifyOutcome $expected): void
    {
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $this->createMockClient([
            new Response(200, [], json_encode($reply))
        ]));

        $result = $service->verifyOtp(['referenceNo' => 'ref-1', 'usedApiUrl' => 'https://mspace.mock/'], '123456');

        $this->assertSame($expected, $result['outcome']);
        $this->assertSame($reply, $result['originalResponse']);
    }
}
