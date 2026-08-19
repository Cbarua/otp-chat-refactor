<?php
// tests/OtpFlowServiceTest.php
declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\OtpVerificationToken;
use App\Enum\ApiStatus;
use App\Enum\SessionKey;
use App\Service\AnalyticsTrackerService;
use App\Service\OtpApiInterface;
use App\Service\OtpFlowService;
use App\Service\RateLimiterService;
use App\Service\SessionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(OtpFlowService::class)]
class OtpFlowServiceTest extends TestCase
{
    private OtpFlowService $service;
    private array $config;
    private MockObject|OtpApiInterface $otpServiceMock;
    private MockObject|AnalyticsTrackerService $analyticsTrackerMock;
    private MockObject|LoggerInterface $loggerMock;
    private MockObject|SessionService $sessionServiceMock;
    private MockObject|RateLimiterService $rateLimiterMock;
    private array $sessionData;

    private const PRIMARY_API_URL = 'https://mock.api/primary.php';
    private const FALLBACK_API_URL = 'https://mock.api/fallback.php';

    protected function setUp(): void
    {
        $this->sessionData = [];
        $this->config = [
            'sms' => ['number' => '77000', 'keyword' => 'chat'],
            'api' => ['ideamart' => [self::PRIMARY_API_URL, self::FALLBACK_API_URL]]
        ];

        $this->otpServiceMock = $this->createMock(OtpApiInterface::class);
        $this->analyticsTrackerMock = $this->createMock(AnalyticsTrackerService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->sessionServiceMock = $this->createMock(SessionService::class);
        $this->rateLimiterMock = $this->createMock(RateLimiterService::class);

        $this->analyticsTrackerMock->method('getUserInfo')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);

        $this->sessionServiceMock->method('get')->willReturnCallback(function (SessionKey|string $key, $default = null) {
            $k = $key instanceof SessionKey ? $key->value : $key;
            return $this->sessionData[$k] ?? $default;
        });
        $this->sessionServiceMock->method('set')->willReturnCallback(function (SessionKey|string $key, $value): void {
            $k = $key instanceof SessionKey ? $key->value : $key;
            $this->sessionData[$k] = $value;
        });
        $this->sessionServiceMock->method('has')->willReturnCallback(function (SessionKey|string $key): bool {
            $k = $key instanceof SessionKey ? $key->value : $key;
            return isset($this->sessionData[$k]);
        });
        $this->sessionServiceMock->method('unset')->willReturnCallback(function (SessionKey|string $key): void {
            $k = $key instanceof SessionKey ? $key->value : $key;
            unset($this->sessionData[$k]);
        });

        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->service = new OtpFlowService(
            $this->config,
            $this->otpServiceMock,
            $this->analyticsTrackerMock,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->rateLimiterMock
        );
    }

    public function testProcessVerificationInvalidOtpLength(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;

        $request = new Request();
        $result = $this->service->processVerification($request, '123'); // only 3 digits

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_OTP_INVALID_LENGTH, $result->message);
        $this->assertEquals('otp', $result->redirectUrl);
        $this->assertEquals(OtpFlowService::ERROR_OTP_INVALID_LENGTH, $this->sessionData[SessionKey::ERROR_MESSAGE->value]);
    }

    public function testProcessVerificationSuccessSubscribed(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;
        $this->sessionData[SessionKey::INVALID_OTP_COUNT->value] = 2;
        $this->sessionData[SessionKey::SHOW_SMS_LINK->value] = false;

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn([
                'status' => ApiStatus::SUCCESS->value,
                'subscriptionStatus' => OtpFlowService::SUB_STATUS_REGISTERED
            ]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('thanks', $result->redirectUrl);
        $this->assertArrayHasKey(SessionKey::REG_ID->value, $this->sessionData);
        $this->assertStringStartsWith('reg-', $this->sessionData[SessionKey::REG_ID->value]);
        $this->assertArrayNotHasKey(SessionKey::INVALID_OTP_COUNT->value, $this->sessionData);
        $this->assertArrayNotHasKey(SessionKey::SHOW_SMS_LINK->value, $this->sessionData);
    }

    public function testProcessVerificationSuccessMspacePlatform(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'mspace', time());
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn([
                'status' => ApiStatus::SUCCESS->value,
                'subscriptionStatus' => 'UNKNOWN_STATUS'
            ]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('thanks', $result->redirectUrl);
        $this->assertArrayHasKey(SessionKey::REG_ID->value, $this->sessionData);
    }

    public function testProcessVerificationMissingPlatformReturnsError(): void
    {
        $token = ['referenceNo' => 'ref123', 'usedApiUrl' => self::PRIMARY_API_URL]; // no platform
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['status' => ApiStatus::SUCCESS->value]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_REGISTRATION_FAILED, $result->message);
        $this->assertEquals('./', $result->redirectUrl);
    }

    public function testProcessVerificationUnexpectedSubscriptionStatus(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn([
                'status' => ApiStatus::SUCCESS->value,
                'subscriptionStatus' => 'UNSUBSCRIBED'
            ]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_REGISTRATION_FAILED, $result->message);
        $this->assertEquals('otp', $result->redirectUrl);
    }

    public function testProcessVerificationInvalidOtpIncrementsCountAndShowsSmsLinkOnThirdAttempt(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;

        $this->otpServiceMock->method('verifyOtp')->willReturn(['status' => OtpFlowService::OTP_INVALID]);

        $request = new Request();

        // Attempt 1
        $result1 = $this->service->processVerification($request, '123456');
        $this->assertFalse($result1->isSuccess());
        $this->assertFalse($result1->showSmsLink);
        $this->assertEquals(1, $this->sessionData[SessionKey::INVALID_OTP_COUNT->value]);

        // Attempt 2
        $result2 = $this->service->processVerification($request, '123456');
        $this->assertFalse($result2->isSuccess());
        $this->assertFalse($result2->showSmsLink);
        $this->assertEquals(2, $this->sessionData[SessionKey::INVALID_OTP_COUNT->value]);

        // Attempt 3
        $result3 = $this->service->processVerification($request, '123456');
        $this->assertFalse($result3->isSuccess());
        $this->assertTrue($result3->showSmsLink);
        $this->assertEquals(3, $this->sessionData[SessionKey::INVALID_OTP_COUNT->value]);
    }

    public function testProcessVerificationOtpNotFoundWithSmsConfigShowsSmsLink(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['status' => OtpFlowService::OTP_NOT_FOUND]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->showSmsLink);
        $this->assertEquals('OTP not found.', $result->message);
    }

    public function testProcessVerificationOtpNotFoundWithoutSmsConfigTriggersAutoRenewal(): void
    {
        $service = new OtpFlowService(
            [], // no sms config
            $this->otpServiceMock,
            $this->analyticsTrackerMock,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->rateLimiterMock
        );

        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;
        $this->sessionData[SessionKey::PHONE_DATA->value] = [
            'telco_format' => 'tel:94771234567',
            'capi_format' => '94771234567',
            'platform' => 'ideamart'
        ];

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['status' => OtpFlowService::OTP_NOT_FOUND]);

        $newToken = new OtpVerificationToken('ref456', self::PRIMARY_API_URL, 'ideamart', time());
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->willReturn([
                'status' => 'success',
                'verificationToken' => $newToken
            ]);

        $request = new Request();
        $result = $service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_OTP_EXPIRED, $result->message);
        $this->assertEquals('otp', $result->redirectUrl);
        $this->assertSame($newToken, $this->sessionData[SessionKey::OTP_TOKEN->value]);
    }

    public function testProcessVerificationExpiredTokenAutoRenewSuccess(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time() - 400);
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;
        $this->sessionData[SessionKey::PHONE_DATA->value] = [
            'telco_format' => 'tel:94771234567',
            'capi_format' => '94771234567',
            'platform' => 'ideamart'
        ];

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['status' => OtpFlowService::OTP_STATUS_EXPIRED]);

        $newToken = new OtpVerificationToken('refNew', self::PRIMARY_API_URL, 'ideamart', time());
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->willReturn([
                'status' => 'success',
                'verificationToken' => $newToken
            ]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_OTP_EXPIRED, $result->message);
        $this->assertEquals('otp', $result->redirectUrl);
        $this->assertSame($newToken, $this->sessionData[SessionKey::OTP_TOKEN->value]);
    }

    public function testProcessVerificationRateLimitExceededTriggersFallbackSuccess(): void
    {
        $this->rateLimiterMock = $this->createMock(RateLimiterService::class);
        $this->rateLimiterMock->method('check')->willReturn(false); // rate limit exceeded
        $this->rateLimiterMock->expects($this->once())->method('clear');

        $service = new OtpFlowService(
            $this->config,
            $this->otpServiceMock,
            $this->analyticsTrackerMock,
            $this->loggerMock,
            $this->sessionServiceMock,
            $this->rateLimiterMock
        );

        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;
        $this->sessionData[SessionKey::PHONE_DATA->value] = [
            'telco_format' => 'tel:94771234567',
            'capi_format' => '94771234567',
            'platform' => 'ideamart'
        ];

        $newToken = new OtpVerificationToken('refFallback', self::FALLBACK_API_URL, 'ideamart', time());
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', 'tel:94771234567', $this->anything(), [self::PRIMARY_API_URL])
            ->willReturn([
                'status' => 'success',
                'verificationToken' => $newToken
            ]);

        $request = new Request();
        $result = $service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_OTP_NEW, $result->message);
        $this->assertEquals('otp', $result->redirectUrl);
    }

    public function testProcessVerificationFallbackMissingDataRedirectsHome(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $this->sessionData[SessionKey::OTP_TOKEN->value] = $token;
        // No phone_data in session

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['status' => 'UNEXPECTED_ERROR']);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_GENERIC, $result->message);
        $this->assertEquals('./', $result->redirectUrl);
    }
}
