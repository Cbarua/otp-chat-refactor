<?php

namespace App\Tests\Controller;

use App\Controller\FormController;
use App\Service\CsrfService;
use App\Service\FacebookCapiService;
use App\Service\OtpApiInterface;
use App\Service\RateLimiterService;
use App\Service\SessionService;
use App\Service\UrlRotationService;
use App\Service\UserInfoService;
use App\Service\UserLoggerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class FormControllerRotationTest extends TestCase
{
    private $config;
    private $carrierConfig;
    private $otpService;
    private $userLogger;
    private $capiService;
    private $logger;
    private $userInfoService;
    private $sessionService;
    private $csrfService;
    private $rateLimiter;
    private $urlRotationService;
    private $controller;
    private $rotatedUrls;

    protected function setUp(): void
    {
        $this->config = [
            'api' => [
                'url_rotation_platform' => 'ideamart',
                'url_rotation_excluded_phones' => ['0771234568'],
                'url_rotation_priority' => ['url2', 'url1'],
                'ideamart' => ['url1', 'url2', 'url3']
            ],
            'facebook' => ['pixel_id' => '123'],
            'google' => ['ga_measurement_id' => 'UA-123']
        ];

        $this->carrierConfig = [
            'LK' => [
                'country_code' => '94',
                'prefixes' => ['dialog' => ['77']],
                'values' => ['default' => 0.01, 'dialog' => 0.02],
                'platform_map' => ['77' => 'ideamart'],
                'platform_default' => 'ideamart'
            ]
        ];

        $this->rotatedUrls = ['url2', 'url1', 'url3'];

        $this->otpService = $this->createMock(OtpApiInterface::class);
        $this->userLogger = $this->createMock(UserLoggerInterface::class);
        $this->capiService = $this->createMock(FacebookCapiService::class);
        $this->capiService->method('processRequest')->willReturn(['fbc' => null, 'fbp' => null, 'client_ip_address' => '127.0.0.1']);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userInfoService = $this->createMock(UserInfoService::class);
        $this->sessionService = $this->createMock(SessionService::class);
        $this->csrfService = $this->createMock(CsrfService::class);
        $this->rateLimiter = $this->createMock(RateLimiterService::class);
        $this->rateLimiter->method('getRemainingSeconds')->willReturn(0);
        $this->urlRotationService = $this->createMock(UrlRotationService::class);

        $this->controller = new FormController(
            $this->config,
            $this->carrierConfig,
            $this->otpService,
            $this->userLogger,
            $this->capiService,
            $this->logger,
            $this->userInfoService,
            $this->sessionService,
            $this->csrfService,
            $this->rateLimiter,
            $this->urlRotationService
        );
    }

    public function testHandlePhoneFormTriggersRotation()
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'valid_token']);

        // Mocks setup
        $this->csrfService->method('validate')->willReturn(true);
        $this->userInfoService->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);
        $this->rateLimiter->method('check')->willReturn(true);
        $this->sessionService->method('get')->willReturnMap([
            ['visitor_id', null, 'test_visitor_id'],
            ['fbp', null, null],
            ['fbc', null, null]
        ]);

        // Expect rotation logic interactions
        $this->urlRotationService->expects($this->once())
            ->method('incrementSubmissionCount')
            ->with('ideamart');

        $this->urlRotationService->expects($this->once())
            ->method('shouldRotate')
            ->with('ideamart')
            ->willReturn(true);

        $this->urlRotationService->expects($this->once())
            ->method('getRotatedUrls')
            ->with($this->config['api']['ideamart'], $this->config['api']['url_rotation_priority'])
            ->willReturn($this->rotatedUrls);

        // Expect OtpService to be called with rotated URLs
        $this->otpService->expects($this->once())
            ->method('getOtp')
            ->with(
                'ideamart',
                'tel:94771234567',
                $this->anything(),
                [],
                $this->rotatedUrls // Verify customUrls is passed
            )
            ->willReturn(['status' => 'success', 'verificationToken' => 'token']);

        $this->controller->handlePhoneForm($request);
    }

    public function testHandlePhoneFormDoesNotRotateWhenNotTriggered()
    {
        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'valid_token']);

        // Mocks setup
        $this->csrfService->method('validate')->willReturn(true);
        $this->userInfoService->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);
        $this->rateLimiter->method('check')->willReturn(true);
        $this->sessionService->method('get')->willReturnMap([
            ['visitor_id', null, 'test_visitor_id'],
            ['fbp', null, null],
            ['fbc', null, null]
        ]);

        // Expect rotation logic interactions
        $this->urlRotationService->expects($this->once())
            ->method('incrementSubmissionCount')
            ->with('ideamart');

        $this->urlRotationService->expects($this->once())
            ->method('shouldRotate')
            ->with('ideamart')
            ->willReturn(false); // Rotation NOT triggered

        $this->urlRotationService->expects($this->never())
            ->method('getRotatedUrls');

        // Expect OtpService to be called with NULL customUrls
        $this->otpService->expects($this->once())
            ->method('getOtp')
            ->with(
                'ideamart',
                'tel:94771234567',
                $this->anything(),
                [],
                null // Verify customUrls is null
            )
            ->willReturn(['status' => 'success', 'verificationToken' => 'token']);

        $this->controller->handlePhoneForm($request);
    }

    public function testHandlePhoneFormSkipsRotationForExcludedPhone()
    {
        $excludedPhone = '0771234568';
        $request = new Request([], ['mobile' => $excludedPhone, 'csrf_token' => 'valid_token']);

        // Mocks setup
        $this->csrfService->method('validate')->willReturn(true);
        $this->userInfoService->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);
        $this->rateLimiter->method('check')->willReturn(true);
        $this->sessionService->method('get')->willReturnMap([
            ['visitor_id', null, 'test_visitor_id'],
            ['fbp', null, null],
            ['fbc', null, null]
        ]);

        // Expect notice log for skipped rotation
        $this->logger->expects($this->once())
            ->method('notice')
            ->with(
                $this->equalTo('URL Rotation skipped: phone number excluded'),
                $this->anything()
            );

        // Expect NO rotation logic interactions
        $this->urlRotationService->expects($this->never())
            ->method('incrementSubmissionCount');

        $this->urlRotationService->expects($this->never())
            ->method('shouldRotate');

        $this->controller->handlePhoneForm($request);
    }

    public function testHandlePhoneFormSkipsRotationForExcludedUserAgent()
    {
        $config = $this->config;
        $config['api']['url_rotation_excluded_useragents'] = ['HeadlessChrome', 'Googlebot'];

        $controller = new FormController(
            $config,
            $this->carrierConfig,
            $this->otpService,
            $this->userLogger,
            $this->capiService,
            $this->logger,
            $this->userInfoService,
            $this->sessionService,
            $this->csrfService,
            $this->rateLimiter,
            $this->urlRotationService
        );

        $request = new Request([], ['mobile' => '0771234567', 'csrf_token' => 'valid_token']);

        $this->csrfService->method('validate')->willReturn(true);
        $this->userInfoService->method('get')->willReturn([
            'ip' => '127.0.0.1',
            'useragent' => 'Mozilla/5.0 HeadlessChrome/120.0.0.0'
        ]);
        $this->rateLimiter->method('check')->willReturn(true);
        $this->sessionService->method('get')->willReturnMap([
            ['visitor_id', null, 'test_visitor_id'],
            ['fbp', null, null],
            ['fbc', null, null]
        ]);

        // Expect notice log for skipped rotation with matched keyword
        $this->logger->expects($this->once())
            ->method('notice')
            ->with(
                $this->equalTo('URL Rotation skipped: user-agent keyword matched'),
                $this->callback(function ($context) {
                    return isset($context['matched_keyword']) && $context['matched_keyword'] === 'HeadlessChrome';
                })
            );

        // Expect NO rotation logic interactions
        $this->urlRotationService->expects($this->never())
            ->method('incrementSubmissionCount');

        $this->urlRotationService->expects($this->never())
            ->method('shouldRotate');

        $controller->handlePhoneForm($request);
    }

    public function testHandlePhoneFormTriggersMultiPlatformRotation()
    {
        $config = $this->config;
        $config['api']['url_rotation_platforms'] = ['ideamart', 'mspace'];
        $config['api']['url_rotation_priority'] = [
            'ideamart' => ['url2', 'url1'],
            'mspace' => ['murl2', 'murl1']
        ];
        $config['api']['mspace'] = ['murl1', 'murl2'];

        $carrierConfig = $this->carrierConfig;
        $carrierConfig['LK']['platform_map']['78'] = 'mspace';
        $carrierConfig['LK']['prefixes']['hutch'] = ['78'];

        $controller = new FormController(
            $config,
            $carrierConfig,
            $this->otpService,
            $this->userLogger,
            $this->capiService,
            $this->logger,
            $this->userInfoService,
            $this->sessionService,
            $this->csrfService,
            $this->rateLimiter,
            $this->urlRotationService
        );

        $request = new Request([], ['mobile' => '0781234567', 'csrf_token' => 'valid_token']);

        $this->csrfService->method('validate')->willReturn(true);
        $this->userInfoService->method('get')->willReturn(['ip' => '127.0.0.1', 'useragent' => 'TestAgent']);
        $this->rateLimiter->method('check')->willReturn(true);
        $this->sessionService->method('get')->willReturnMap([
            ['visitor_id', null, 'test_visitor_id'],
            ['fbp', null, null],
            ['fbc', null, null]
        ]);

        $this->urlRotationService->expects($this->once())
            ->method('incrementSubmissionCount')
            ->with('mspace');

        $this->urlRotationService->expects($this->once())
            ->method('shouldRotate')
            ->with('mspace')
            ->willReturn(true);

        $this->urlRotationService->expects($this->once())
            ->method('getRotatedUrls')
            ->with(['murl1', 'murl2'], ['murl2', 'murl1'])
            ->willReturn(['murl2', 'murl1']);

        $this->otpService->expects($this->once())
            ->method('getOtp')
            ->with('mspace', 'tel:94781234567', $this->anything(), [], ['murl2', 'murl1'])
            ->willReturn(['status' => 'success', 'verificationToken' => 'token']);

        $controller->handlePhoneForm($request);
    }
}
