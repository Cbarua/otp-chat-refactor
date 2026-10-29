<?php
// tests/OtpFlowServiceTest.php
declare(strict_types=1);

namespace App\Service;

// Shadows the global random_bytes() inside App\Service so OtpFlowState::generateRandomId()'s
// uniqid() fallback can be exercised. Delegates to the real function unless the global flag is set.
if (!function_exists('App\Service\random_bytes')) {
    function random_bytes(int $length): string
    {
        if (!empty($GLOBALS['mock_random_bytes_fail_service'])) {
            throw new \Exception('Random bytes failed');
        }
        return \random_bytes($length);
    }
}

namespace App\Tests\Service;

use App\DTO\OtpVerificationToken;
use App\DTO\PhoneNumber;
use App\Enum\OtpRequestOutcome;
use App\Enum\OtpVerifyOutcome;
use App\Enum\SessionKey;
use App\Service\AnalyticsTrackerService;
use App\Service\OtpApiService;
use App\Service\OtpFlowService;
use App\Service\OtpFlowState;
use App\Service\RateLimiterService;
use App\Service\SessionService;
use App\Utils\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\SessionFixtures;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(OtpFlowService::class)]
class OtpFlowServiceTest extends TestCase
{
    use SessionFixtures;

    private const PRIMARY_API_URL = 'https://mock.api/primary.php';
    private const FALLBACK_API_URL = 'https://mock.api/fallback.php';

    // config/carriers.php: prefix 77 -> ideamart (71 -> mspace)
    private const RAW_PHONE = '0771234567';
    private const CAPI_PHONE = '94771234567';
    private const TELCO_PHONE = 'tel:94771234567';
    private const SMS_CONFIG = ['sms' => ['number' => '1234', 'keyword' => 'REG']];
    // Session phone_data left behind by an earlier request for RAW_PHONE.
    private const SAME_PHONE = [
        'capiFormat' => self::CAPI_PHONE,
        'telcoFormat' => self::TELCO_PHONE,
        'platform' => 'ideamart',
    ];

    private OtpFlowService $service;
    private array $config;
    private array $carrierConfig;
    private MockObject|OtpApiService $otpServiceMock;
    private MockObject|AnalyticsTrackerService $analyticsTrackerMock;
    private MockObject|LoggerInterface $loggerMock;
    private SessionService $sessionService;
    private MockObject|RateLimiterService $rateLimiterMock;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->config = [
            'sms' => ['number' => '77000', 'keyword' => 'chat'],
            'api' => ['ideamart' => [self::PRIMARY_API_URL, self::FALLBACK_API_URL]]
        ];
        $this->carrierConfig = require __DIR__ . '/../config/carriers.php';

        $this->otpServiceMock = $this->createMock(OtpApiService::class);
        $this->analyticsTrackerMock = $this->createMock(AnalyticsTrackerService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->sessionService = new SessionService();
        $this->rateLimiterMock = $this->createMock(RateLimiterService::class);

        $this->analyticsTrackerMock->method('getUserInfo')
            ->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);
        $this->rateLimiterMock->method('check')->willReturn(true);

        $this->service = $this->makeService($this->config);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($GLOBALS['mock_random_bytes_fail_service']);
    }

    /**
     * Builds an OtpFlowService over the shared mocks with the given app config (with or without 'sms').
     */
    private function makeService(array $config): OtpFlowService
    {
        return new OtpFlowService(
            $config,
            $this->carrierConfig,
            $this->otpServiceMock,
            $this->analyticsTrackerMock,
            $this->loggerMock,
            $this->sessionService,
            $this->rateLimiterMock,
            new OtpFlowState($this->sessionService)
        );
    }

    /**
     * The form POST FormController hands to requestOtp() alongside the raw phone.
     */
    private function phoneRequest(string $phone = self::RAW_PHONE): Request
    {
        return new Request([], ['mobile' => $phone]);
    }

    /**
     * Session state every SMS-fallback branch of requestOtp leaves behind.
     */
    private function assertSmsFallbackSessionState(): void
    {
        $this->assertStringStartsWith('lead-', $_SESSION[SessionKey::LEAD_ID->value]);
        $this->assertInstanceOf(PhoneNumber::class, $_SESSION[SessionKey::PHONE_DATA->value]);
        $this->assertTrue($_SESSION[SessionKey::SHOW_SMS_LINK->value]);
        $this->assertTrue($_SESSION[SessionKey::OTP_TOKEN->value]);
        $this->assertArrayNotHasKey(SessionKey::ERROR_MESSAGE->value, $_SESSION);
    }

    // --- requestOtp ---

    public function testRequestOtpRateLimitExceeded(): void
    {
        $this->rateLimiterMock = $this->createMock(RateLimiterService::class);
        $this->rateLimiterMock->method('check')->with('phone_submission:127.0.0.1', 5, 60)->willReturn(false);
        $this->rateLimiterMock->expects($this->never())->method('increment');
        $this->otpServiceMock->expects($this->never())->method('getOtp');
        $this->analyticsTrackerMock->expects($this->never())->method('logVisitWithPhone');
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Rate limit exceeded for phone submission.', ['rate_limit_key' => 'phone_submission:127.0.0.1']);

        $result = $this->makeService($this->config)->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->next);
        $this->assertSame(['status' => 'error', 'message' => OtpFlowService::ERROR_RATE_LIMIT, 'redirect' => null], $result->toArray());
        $this->assertTrue($result->flash);
    }

    public static function invalidPhoneProvider(): array
    {
        return [
            'malformed number' => ['12345'],
            'unsupported carrier' => ['0731234567'],
        ];
    }

    #[DataProvider('invalidPhoneProvider')]
    public function testRequestOtpInvalidPhone(string $raw): void
    {
        $this->rateLimiterMock->expects($this->once())->method('increment')->with('phone_submission:127.0.0.1');
        $this->otpServiceMock->expects($this->never())->method('getOtp');
        $this->analyticsTrackerMock->expects($this->never())->method('logVisitWithPhone');

        // Validator logs its own warning first (requestOtp hands it the logger); its wording belongs to ValidatorTest.
        $seen = false;
        $seenValidator = false;
        $this->loggerMock->expects($this->atLeastOnce())
            ->method('warning')
            ->willReturnCallback(function (string $message, array $context) use ($raw, &$seen, &$seenValidator): void {
                if (str_starts_with($message, 'Validation failed')) {
                    $seenValidator = true;
                }
                if ($message === 'Invalid phone number submitted') {
                    $seen = true;
                    $this->assertSame(['raw_phone' => $raw], $context);
                }
            });

        $result = $this->service->requestOtp($this->phoneRequest($raw), $raw);

        $this->assertTrue($seenValidator, 'Expected the Validator warning to be forwarded to the logger.');
        $this->assertTrue($seen, 'Expected the "Invalid phone number submitted" warning.');
        $this->assertSame(['status' => 'error', 'message' => OtpFlowService::ERROR_INVALID_PHONE, 'redirect' => null], $result->toArray());
        $this->assertNull($result->next);
        $this->assertTrue($result->flash);
    }

    public function testRequestOtpBlockedNumberReturnsMaxRequestsError(): void
    {
        $this->rateLimiterMock->method('getRemainingSeconds')
            ->with('otp_max_requests:' . self::CAPI_PHONE)
            ->willReturn(150);
        $this->otpServiceMock->expects($this->never())->method('getOtp');
        $this->analyticsTrackerMock->expects($this->never())->method('logVisitWithPhone');
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Phone number blocked due to max OTP requests limit', [
                'phone' => self::CAPI_PHONE,
                'remaining_seconds' => 150,
                'remaining_minutes' => 3,
            ]);

        $result = $this->service->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $expected = 'Maximum number of OTP requests reached for 0771234567. Please try again in 3 minutes';
        $this->assertSame(['status' => 'error', 'message' => $expected, 'redirect' => null], $result->toArray());
        $this->assertNull($result->next);
        $this->assertTrue($result->flash);
    }

    public function testRequestOtpReusesRecentTokenForSameNumber(): void
    {
        $createdAt = time() - 100;
        $_SESSION[SessionKey::PHONE_DATA->value] = self::phone(...self::SAME_PHONE);
        $_SESSION[SessionKey::OTP_TOKEN->value] = self::token(referenceNo: 'ref-123', createdAt: $createdAt);
        $request = $this->phoneRequest();

        $this->otpServiceMock->expects($this->never())->method('getOtp');
        $this->analyticsTrackerMock->expects($this->once())
            ->method('logVisitWithPhone')
            ->with($request, $this->callback(fn (PhoneNumber $p) => $p['capi_format'] === self::CAPI_PHONE));
        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('Reusing existing valid OTP token', $this->callback(
                fn (array $c) => $c['phone'] === self::CAPI_PHONE
                    && $c['created_at'] === date('Y-m-d H:i:s', $createdAt)
                    && $c['age'] >= 100
            ));

        $result = $this->service->requestOtp($request, self::RAW_PHONE);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('otp', $result->next);
        $this->assertSame(['status' => 'success', 'message' => null, 'redirect' => 'otp'], $result->toArray());
        $this->assertArrayNotHasKey(SessionKey::LEAD_ID->value, $_SESSION);
    }

    public function testRequestOtpReusesRecentOtpVerificationTokenDto(): void
    {
        // Production shape: OtpApiService stores the DTOs, so the reuse check takes the ArrayAccess branch.
        $token = new OtpVerificationToken('ref-123', self::PRIMARY_API_URL, 'ideamart', time() - 100);
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;
        $_SESSION[SessionKey::PHONE_DATA->value] = Validator::normalizePhone(self::RAW_PHONE, $this->carrierConfig);

        $this->otpServiceMock->expects($this->never())->method('getOtp');
        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('Reusing existing valid OTP token', $this->anything());

        $result = $this->service->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('otp', $result->next);
        $this->assertSame($token, $_SESSION[SessionKey::OTP_TOKEN->value]);
    }

    public static function staleTokenProvider(): array
    {
        // Token factories: time() must be read when the test runs, not when PHPUnit collects providers.
        return [
            'token older than 300s' => [
                fn () => new OtpVerificationToken('ref', self::PRIMARY_API_URL, 'ideamart', time() - 301),
                self::phone(...self::SAME_PHONE),
            ],
            'different number' => [
                fn () => self::token(referenceNo: 'ref', createdAt: time()),
                self::phone(...['capiFormat' => '94711234567'] + self::SAME_PHONE),
            ],
            'sms fallback sentinel' => [fn () => true, self::phone(...self::SAME_PHONE)],
        ];
    }

    #[DataProvider('staleTokenProvider')]
    public function testRequestOtpDoesNotReuseStaleOrMismatchedToken(
        \Closure $makeToken,
        PhoneNumber $existingPhoneData
    ): void {
        $_SESSION[SessionKey::OTP_TOKEN->value] = $makeToken();
        $_SESSION[SessionKey::PHONE_DATA->value] = $existingPhoneData;
        $newToken = new OtpVerificationToken('new-ref', self::PRIMARY_API_URL, 'ideamart', time());

        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->willReturn(['outcome' => OtpRequestOutcome::SENT, 'verificationToken' => $newToken]);
        $this->loggerMock->expects($this->never())->method('notice');

        $result = $this->service->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($newToken, $_SESSION[SessionKey::OTP_TOKEN->value]);
    }

    public function testRequestOtpSuccessStoresTokenAndIncrementsRateLimit(): void
    {
        $request = $this->phoneRequest();
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());

        $this->rateLimiterMock->expects($this->once())->method('increment')->with('phone_submission:127.0.0.1');
        $this->analyticsTrackerMock->expects($this->once())
            ->method('logVisitWithPhone')
            ->with($request, $this->callback(fn (PhoneNumber $p) => $p['capi_format'] === self::CAPI_PHONE));
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', self::TELCO_PHONE, $this->callback(
                fn (array $m) => $m['client'] === 'WEBAPP'
                    && $m['appCode'] === $request->getUri()
                    && $m['ip'] === '127.0.0.1'
            ))
            ->willReturn(['outcome' => OtpRequestOutcome::SENT, 'verificationToken' => $token]);

        $result = $this->service->requestOtp($request, self::RAW_PHONE);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('otp', $result->next);
        $this->assertSame(['status' => 'success', 'message' => null, 'redirect' => 'otp'], $result->toArray());
        $this->assertStringStartsWith('lead-', $_SESSION[SessionKey::LEAD_ID->value]);
        $this->assertEquals(
            Validator::normalizePhone(self::RAW_PHONE, $this->carrierConfig),
            $_SESSION[SessionKey::PHONE_DATA->value]
        );
        $this->assertSame($token, $_SESSION[SessionKey::OTP_TOKEN->value]);
        $this->assertArrayNotHasKey(SessionKey::ERROR_MESSAGE->value, $_SESSION);
    }

    public function testRequestOtpForwardsPlatformAndTelcoFormatOfTheNumber(): void
    {
        // config/carriers.php: prefix 71 -> mspace
        $token = new OtpVerificationToken('ref456', 'https://mspace.api/first', 'mspace', time());
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('mspace', 'tel:94711234567', $this->anything())
            ->willReturn(['outcome' => OtpRequestOutcome::SENT, 'verificationToken' => $token]);

        $result = $this->service->requestOtp($this->phoneRequest('0711234567'), '0711234567');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('mspace', $_SESSION[SessionKey::PHONE_DATA->value]['platform']);
        $this->assertSame($token, $_SESSION[SessionKey::OTP_TOKEN->value]);
    }

    public function testRequestOtpLeadIdFallsBackToUniqidWhenRandomBytesFails(): void
    {
        $GLOBALS['mock_random_bytes_fail_service'] = true;
        $this->otpServiceMock->method('getOtp')
            ->willReturn(['outcome' => OtpRequestOutcome::SENT, 'verificationToken' => self::token(referenceNo: 'ref')]);

        $result = $this->service->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $this->assertTrue($result->isSuccess());
        // uniqid() is 13 hex chars; bin2hex(random_bytes(16)) would be 32.
        $this->assertMatchesRegularExpression('/^lead-[0-9a-f]{13}$/', $_SESSION[SessionKey::LEAD_ID->value]);
    }

    public static function alreadyRegisteredProvider(): array
    {
        $app1 = 'https://ideamart.api/app1';
        $app2 = 'https://ideamart.api/app2';
        $attempts = ['failedAttempts' => [['base_url' => $app1], ['base_url' => $app2]], 'finalUrl' => $app2];
        return [
            // [extra response keys, scope, already_registered_apps, final_app]
            'all apps' => [$attempts + ['alreadyRegisteredUrls' => [$app1, $app2]], 'all', ['app1', 'app2'], 'app2'],
            'some apps' => [$attempts + ['alreadyRegisteredUrls' => [$app1]], 'some', ['app1'], 'app2'],
            // No failedAttempts and no finalUrl at all: the `?? []` / `?? ''` defaults must hold.
            'bare response' => [[], 'all', [], ''],
        ];
    }

    #[DataProvider('alreadyRegisteredProvider')]
    public function testRequestOtpAlreadyRegistered(
        array $responseDetails,
        string $scope,
        array $registeredApps,
        string $finalApp
    ): void {
        $this->otpServiceMock->method('getOtp')->willReturn(
            ['outcome' => OtpRequestOutcome::ALREADY_REGISTERED] + $responseDetails
        );
        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with("User already registered on $scope of the services for this platform.", [
                'platform' => 'ideamart',
                'phone' => self::CAPI_PHONE,
                'already_registered_apps' => $registeredApps,
                'final_app' => $finalApp,
            ]);

        $result = $this->service->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $this->assertSame(
            ['status' => 'already_registered', 'message' => OtpFlowService::ALREADY_REGISTERED, 'redirect' => null],
            $result->toArray()
        );
        $this->assertSame('already_registered', $result->status);
        $this->assertSame(OtpFlowService::ALREADY_REGISTERED, $result->message);
        $this->assertNull($result->next);
        $this->assertTrue($result->flash);
        $this->assertArrayNotHasKey(SessionKey::ALREADY_REGISTERED->value, $_SESSION);
        $this->assertInstanceOf(PhoneNumber::class, $_SESSION[SessionKey::PHONE_DATA->value]);
        $this->assertArrayNotHasKey(SessionKey::ERROR_MESSAGE->value, $_SESSION);
        $this->assertArrayNotHasKey(SessionKey::LEAD_ID->value, $_SESSION);
        $this->assertArrayNotHasKey(SessionKey::OTP_TOKEN->value, $_SESSION);
    }

    public function testRequestOtpTemporaryErrorWithoutSmsConfig(): void
    {
        $this->otpServiceMock->method('getOtp')->willReturn([
            'outcome' => OtpRequestOutcome::TEMPORARY_FAILURE,
            'finalUrl' => 'https://ideamart.api/app2',
        ]);
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Temporary system error encountered', ['platform' => 'ideamart', 'final_app' => 'app2']);

        $result = $this->makeService([])->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $this->assertSame(['status' => 'error', 'message' => OtpFlowService::ERROR_GENERIC, 'redirect' => null], $result->toArray());
        $this->assertNull($result->next);
        $this->assertTrue($result->flash);
        $this->assertArrayNotHasKey(SessionKey::SHOW_SMS_LINK->value, $_SESSION);
        $this->assertArrayNotHasKey(SessionKey::OTP_TOKEN->value, $_SESSION);
    }

    public function testRequestOtpTemporaryErrorWithSmsConfigShowsSmsFallback(): void
    {
        $this->otpServiceMock->method('getOtp')->willReturn([
            'outcome' => OtpRequestOutcome::TEMPORARY_FAILURE,
            'finalUrl' => 'https://ideamart.api/app2',
        ]);
        $this->loggerMock->expects($this->never())->method('error');
        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('Temporary system error encountered, showing SMS fallback link.', [
                'platform' => 'ideamart',
                'final_app' => 'app2',
            ]);

        $result = $this->makeService(self::SMS_CONFIG)->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $this->assertSame(
            ['status' => 'error', 'message' => 'Temporary system error', 'redirect' => 'otp'],
            $result->toArray()
        );
        $this->assertSame('otp', $result->next);
        $this->assertFalse($result->flash);
        $this->assertSmsFallbackSessionState();
    }

    public function testRequestOtpMaxRequestsWithSmsConfigBlocksAndShowsSmsFallback(): void
    {
        $this->otpServiceMock->method('getOtp')->willReturn([
            'outcome' => OtpRequestOutcome::MAX_REQUESTS,
        ]);
        $this->rateLimiterMock->expects($this->once())
            ->method('block')
            ->with('otp_max_requests:' . self::CAPI_PHONE, 3600);
        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('Maximum OTP requests reached, showing SMS fallback link.', [
                'platform' => 'ideamart',
                'phone' => self::CAPI_PHONE,
            ]);

        $result = $this->makeService(self::SMS_CONFIG)->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $this->assertSame(
            [
                'status' => 'error',
                'message' => 'Maximum number of OTP requests reached',
                'redirect' => 'otp',
            ],
            $result->toArray()
        );
        $this->assertSame('otp', $result->next);
        $this->assertFalse($result->flash);
        $this->assertSmsFallbackSessionState();
    }

    public function testRequestOtpMaxRequestsWithoutSmsConfigReturnsError(): void
    {
        $this->otpServiceMock->method('getOtp')->willReturn([
            'outcome' => OtpRequestOutcome::MAX_REQUESTS,
        ]);
        $this->rateLimiterMock->expects($this->once())
            ->method('block')
            ->with('otp_max_requests:' . self::CAPI_PHONE, 3600);
        $this->loggerMock->expects($this->once())
            ->method('notice')
            ->with('Maximum OTP requests reached without SMS fallback configured.', [
                'platform' => 'ideamart',
                'phone' => self::CAPI_PHONE,
            ]);

        $result = $this->makeService([])->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $expected = 'Maximum number of OTP requests reached for 0771234567. Please try again in 60 minutes';
        $this->assertSame(['status' => 'error', 'message' => $expected, 'redirect' => null], $result->toArray());
        $this->assertNull($result->next);
        $this->assertTrue($result->flash);
        $this->assertArrayNotHasKey(SessionKey::SHOW_SMS_LINK->value, $_SESSION);
        $this->assertArrayNotHasKey(SessionKey::OTP_TOKEN->value, $_SESSION);
    }

    public function testRequestOtpGenericFailure(): void
    {
        $response = ['outcome' => OtpRequestOutcome::FAILED, 'statusCode' => 'E1301', 'statusDetail' => 'Requested ApplicationID is not allowed'];
        $this->otpServiceMock->method('getOtp')->willReturn($response);
        $this->rateLimiterMock->expects($this->never())->method('block');
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'All OTP request attempts failed for the user.',
                ['platform' => 'ideamart', 'phone' => self::CAPI_PHONE] + $response
            );

        $result = $this->service->requestOtp($this->phoneRequest(), self::RAW_PHONE);

        $this->assertSame(['status' => 'error', 'message' => OtpFlowService::ERROR_GENERIC, 'redirect' => null], $result->toArray());
        $this->assertNull($result->next);
        $this->assertTrue($result->flash);
        $this->assertArrayNotHasKey(SessionKey::ALREADY_REGISTERED->value, $_SESSION);
    }

    // --- processVerification ---

    public function testProcessVerificationInvalidOtpLength(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;

        $request = new Request();
        $result = $this->service->processVerification($request, '123'); // only 3 digits

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_OTP_INVALID_LENGTH, $result->message);
        $this->assertNull($result->next);
        $this->assertTrue($result->flash);
    }

    public function testProcessVerificationSuccessSubscribed(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;
        $_SESSION[SessionKey::INVALID_OTP_COUNT->value] = 2;
        $_SESSION[SessionKey::SHOW_SMS_LINK->value] = false;

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['outcome' => OtpVerifyOutcome::VERIFIED]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('thanks', $result->next);
        $this->assertArrayHasKey(SessionKey::REG_ID->value, $_SESSION);
        $this->assertStringStartsWith('reg-', $_SESSION[SessionKey::REG_ID->value]);
        $this->assertArrayNotHasKey(SessionKey::INVALID_OTP_COUNT->value, $_SESSION);
        $this->assertArrayNotHasKey(SessionKey::SHOW_SMS_LINK->value, $_SESSION);
    }

    public function testProcessVerificationUnexpectedSubscriptionStatus(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['outcome' => OtpVerifyOutcome::NOT_SUBSCRIBED]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_REGISTRATION_FAILED, $result->message);
        $this->assertNull($result->next);
    }

    public function testProcessVerificationInvalidOtpIncrementsCountAndShowsSmsLinkOnThirdAttempt(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::INVALID]);

        $request = new Request();

        // Attempt 1
        $result1 = $this->service->processVerification($request, '123456');
        $this->assertFalse($result1->isSuccess());
        $this->assertNull($result1->next);
        $this->assertTrue($result1->flash);
        $this->assertEquals(1, $_SESSION[SessionKey::INVALID_OTP_COUNT->value]);

        // Attempt 2
        $result2 = $this->service->processVerification($request, '123456');
        $this->assertFalse($result2->isSuccess());
        $this->assertNull($result2->next);
        $this->assertTrue($result2->flash);
        $this->assertEquals(2, $_SESSION[SessionKey::INVALID_OTP_COUNT->value]);

        // Attempt 3
        $result3 = $this->service->processVerification($request, '123456');
        $this->assertFalse($result3->isSuccess());
        $this->assertSame('otp', $result3->next);
        $this->assertTrue($_SESSION[SessionKey::SHOW_SMS_LINK->value]);
        $this->assertFalse($result3->flash);
        $this->assertSame(OtpFlowService::ERROR_OTP_INVALID, $result3->message);
        $this->assertEquals(3, $_SESSION[SessionKey::INVALID_OTP_COUNT->value]);
    }

    public function testProcessVerificationOtpNotFoundWithSmsConfigShowsSmsLink(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['outcome' => OtpVerifyOutcome::NOT_FOUND]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertSame('otp', $result->next);
        $this->assertTrue($_SESSION[SessionKey::SHOW_SMS_LINK->value]);
        $this->assertEquals('OTP not found.', $result->message);
        $this->assertFalse($result->flash);
    }

    public function testProcessVerificationOtpNotFoundWithoutSmsConfigTriggersAutoRenewal(): void
    {
        $service = $this->makeService([]); // no sms config

        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;
        $_SESSION[SessionKey::PHONE_DATA->value] = self::phone(
            telcoFormat: 'tel:94771234567',
            capiFormat: '94771234567',
            platform: 'ideamart'
        );

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['outcome' => OtpVerifyOutcome::NOT_FOUND]);

        $newToken = new OtpVerificationToken('ref456', self::PRIMARY_API_URL, 'ideamart', time());
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->willReturn([
                'outcome' => OtpRequestOutcome::SENT,
                'verificationToken' => $newToken
            ]);

        $request = new Request();
        $result = $service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_OTP_EXPIRED, $result->message);
        $this->assertNull($result->next);
        $this->assertSame($newToken, $_SESSION[SessionKey::OTP_TOKEN->value]);
    }

    public function testProcessVerificationExpiredTokenAutoRenewSuccess(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time() - 400);
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;
        $_SESSION[SessionKey::PHONE_DATA->value] = self::phone(
            telcoFormat: 'tel:94771234567',
            capiFormat: '94771234567',
            platform: 'ideamart'
        );

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['outcome' => OtpVerifyOutcome::EXPIRED]);

        $newToken = new OtpVerificationToken('refNew', self::PRIMARY_API_URL, 'ideamart', time());
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->willReturn([
                'outcome' => OtpRequestOutcome::SENT,
                'verificationToken' => $newToken
            ]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_OTP_EXPIRED, $result->message);
        $this->assertNull($result->next);
        $this->assertSame($newToken, $_SESSION[SessionKey::OTP_TOKEN->value]);
    }

    public function testProcessVerificationRateLimitExceededTriggersFallbackSuccess(): void
    {
        $this->rateLimiterMock = $this->createMock(RateLimiterService::class);
        $this->rateLimiterMock->method('check')->willReturn(false); // rate limit exceeded
        $this->rateLimiterMock->expects($this->once())->method('clear');

        $service = $this->makeService($this->config);

        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;
        $_SESSION[SessionKey::PHONE_DATA->value] = self::phone(
            telcoFormat: 'tel:94771234567',
            capiFormat: '94771234567',
            platform: 'ideamart'
        );

        $newToken = new OtpVerificationToken('refFallback', self::FALLBACK_API_URL, 'ideamart', time());
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', 'tel:94771234567', $this->anything(), [self::PRIMARY_API_URL])
            ->willReturn([
                'outcome' => OtpRequestOutcome::SENT,
                'verificationToken' => $newToken
            ]);

        $request = new Request();
        $result = $service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_OTP_NEW, $result->message);
        $this->assertNull($result->next);
    }

    public function testProcessVerificationAttemptsExhaustedFallsBackWithoutCriticalLog(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;
        $_SESSION[SessionKey::PHONE_DATA->value] = self::phone(...self::SAME_PHONE);

        $this->otpServiceMock->method('verifyOtp')->willReturn(['outcome' => OtpVerifyOutcome::ATTEMPTS_EXHAUSTED]);
        $this->otpServiceMock->expects($this->once())
            ->method('getOtp')
            ->with('ideamart', self::TELCO_PHONE, $this->anything(), [self::PRIMARY_API_URL])
            ->willReturn([
                'outcome' => OtpRequestOutcome::SENT,
                'verificationToken' => new OtpVerificationToken('refFallback', self::FALLBACK_API_URL, 'ideamart', time())
            ]);
        $this->loggerMock->expects($this->never())->method('critical');
        $notices = [];
        $this->loggerMock->method('notice')->willReturnCallback(function (string $message) use (&$notices): void {
            $notices[] = $message;
        });

        $result = $this->service->processVerification(new Request(), '123456');

        $this->assertContains('OTP attempts exhausted, trying another app.', $notices);
        $this->assertSame(OtpFlowService::ERROR_OTP_NEW, $result->message);
        $this->assertNull($result->next);
        $this->assertSame('refFallback', $_SESSION[SessionKey::OTP_TOKEN->value]->referenceNo);
    }

    public function testProcessVerificationFallbackMissingDataRedirectsHome(): void
    {
        $token = new OtpVerificationToken('ref123', self::PRIMARY_API_URL, 'ideamart', time());
        $_SESSION[SessionKey::OTP_TOKEN->value] = $token;
        // No phone_data in session

        $this->otpServiceMock->expects($this->once())
            ->method('verifyOtp')
            ->willReturn(['outcome' => OtpVerifyOutcome::PROVIDER_ERROR]);

        $request = new Request();
        $result = $this->service->processVerification($request, '123456');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals(OtpFlowService::ERROR_GENERIC, $result->message);
        $this->assertSame('./', $result->next);
    }

}
