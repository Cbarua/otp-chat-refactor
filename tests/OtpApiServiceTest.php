<?php
// tests/OtpApiServiceTest.php

use PHPUnit\Framework\TestCase;
use App\Service\OtpApiService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;
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
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('12345-abc', $result['referenceNo']);
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
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('67890-def', $result['referenceNo']);
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

        $this->assertEquals('error', $result['status']);
        // The last error should be from a correctly caught RequestException
        // The service returns 'statusCode' => null if the last response was an exception
        $this->assertNull($result['statusCode']);
    }

    public function testGetOtpUnconfiguredPlatform(): void
    {
        $mockClient = $this->createMockClient([]);
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $result = $service->getOtp('unconfigured', 'tel:94771234567', []);

        $this->assertEquals([
            'status' => 'error',
            'message' => 'Configuration error for platform.'
        ], $result);
    }

    public function testVerifyOtpSuccess(): void
    {
        $mockClient = $this->createMockClient([
            new Response(200, [], json_encode(['status' => 'success', 'subscriptionStatus' => 'REGISTERED']))
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $token = ['referenceNo' => 'ref-999', 'usedApiUrl' => 'https://mspace.mock/'];
        $result = $service->verifyOtp($token, '123456');

        $this->assertIsArray($result);
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('REGISTERED', $result['subscriptionStatus']);
    }

    public function testRequestReturnsInvalidJson(): void
    {
        $mockClient = $this->createMockClient([
            new Response(200, [], '<html>This is not json</html>')
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $result = $service->getOtp('mspace', 'tel:94771234567', []);

        // The service returns the last response structure, which in this case is the error from sendRequest
        $this->assertEquals('error', $result['status']);
        $this->assertEquals('Invalid JSON response from API', $result['originalResponse']['message']);
    }

    public function testRequestThrowsRequestExceptionDuringVerify(): void
    {
        $mockClient = $this->createMockClient([
            new RequestException("Error Communicating with Server", new Request('POST', 'test'))
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $token = ['referenceNo' => 'ref-999', 'usedApiUrl' => 'https://mspace.mock/'];
        $result = $service->verifyOtp($token, '123456');

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('API request failed', $result['originalResponse']['message']);
    }

    public function testGetOtpAllUrlsExcluded(): void
    {
        $mockClient = $this->createMockClient([]);
        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $excludedUrls = ['https://ideamart.mock/primary/', 'https://ideamart.mock/fallback/'];
        $result = $service->getOtp('ideamart', 'tel:94771234567', [], $excludedUrls);

        $this->assertEquals([
            'status' => 'error',
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
            'status' => 'error',
            'message' => 'Invalid verification token.'
        ], $result1);

        // Test with missing reference number
        $result2 = $service->verifyOtp(['usedApiUrl' => 'https://mspace.mock/'], '123456');
        $this->assertEquals([
            'status' => 'error',
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

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('A system error occurred', $result['originalResponse']['message']);
    }

    public function testGetOtpReturnsTimestamp(): void
    {
        $mockClient = $this->createMockClient([
            new Response(200, [], json_encode(['statusCode' => 'S1000', 'referenceNo' => '12345-abc']))
        ]);

        $service = new OtpApiService($this->apiConfig, $this->loggerMock, $mockClient);

        $result = $service->getOtp('mspace', 'tel:94711234567', []);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('createdAt', $result['verificationToken']);
        $this->assertIsInt($result['verificationToken']['createdAt']);
    }
}