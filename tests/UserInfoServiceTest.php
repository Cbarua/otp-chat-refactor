<?php
// tests/UserInfoServiceTest.php

use App\Service\UserInfoService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class UserInfoServiceTest extends TestCase
{
    private UserInfoService $userInfoService;

    protected function setUp(): void
    {
        $this->userInfoService = new UserInfoService();
    }

    private function createRequestWithHeaders(array $headers): Request
    {
        $request = new Request();
        foreach ($headers as $key => $value) {
            if (in_array($key, ['REMOTE_ADDR'])) {
                $request->server->set($key, $value);
            } else {
                $request->headers->set($key, $value);
            }
        }
        return $request;
    }

    public function testAndroidDeviceDetection(): void
    {
        $userAgent = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/113.0.0.0 Mobile Safari/537.36';
        $request = $this->createRequestWithHeaders(['User-Agent' => $userAgent, 'REMOTE_ADDR' => '127.0.0.1']);

        $userInfo = $this->userInfoService->get($request);

        $this->assertEquals('Android 10', $userInfo['os']);
        $this->assertEquals('K', $userInfo['device']);
        $this->assertEquals($userAgent, $userInfo['useragent']);
    }
    
    public function testIosDeviceDetection(): void
    {
        $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.5 Mobile/15E148 Safari/604.1';
        $request = $this->createRequestWithHeaders(['User-Agent' => $userAgent, 'REMOTE_ADDR' => '127.0.0.1']);

        $userInfo = $this->userInfoService->get($request);

        $this->assertEquals('iOS 16.5', $userInfo['os']);
        $this->assertEquals('iPhone', $userInfo['device']);
        $this->assertEquals($userAgent, $userInfo['useragent']);
    }

    public function testWindowsDeviceDetection(): void
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36';
        $request = $this->createRequestWithHeaders(['User-Agent' => $userAgent, 'REMOTE_ADDR' => '127.0.0.1']);

        $userInfo = $this->userInfoService->get($request);

        $this->assertEquals('Windows 10.0', $userInfo['os']);
        $this->assertEquals('Windows NT 10.0', $userInfo['device']);
        $this->assertEquals($userAgent, $userInfo['useragent']);
    }

    public function testMacOsDetection(): void
    {
        $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36';
        $request = $this->createRequestWithHeaders(['User-Agent' => $userAgent, 'REMOTE_ADDR' => '127.0.0.1']);

        $userInfo = $this->userInfoService->get($request);

        $this->assertEquals('macOS', $userInfo['os']);
        $this->assertEquals('Macintosh', $userInfo['device']);
        $this->assertEquals($userAgent, $userInfo['useragent']);
    }
    
    public function testLinuxDetection(): void
    {
        $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36';
        $request = $this->createRequestWithHeaders(['User-Agent' => $userAgent, 'REMOTE_ADDR' => '127.0.0.1']);

        $userInfo = $this->userInfoService->get($request);

        $this->assertEquals('Linux', $userInfo['os']);
        $this->assertEquals('X11', $userInfo['device']);
        $this->assertEquals($userAgent, $userInfo['useragent']);
    }

    public function testUnknownUserAgent(): void
    {
        $request = $this->createRequestWithHeaders(['REMOTE_ADDR' => '127.0.0.1']); // No User-Agent header

        $userInfo = $this->userInfoService->get($request);

        $this->assertEquals('Unknown OS', $userInfo['os']);
        $this->assertEquals('Unknown Device', $userInfo['device']);
        $this->assertEquals('UNKNOWN_UA', $userInfo['useragent']);
    }

    public function testIpAddressViaCloudflareHeader(): void
    {
        $request = $this->createRequestWithHeaders(['CF-Connecting-IP' => '192.168.1.1']);
        $userInfo = $this->userInfoService->get($request);
        $this->assertEquals('192.168.1.1', $userInfo['ip']);
    }

    public function testIpAddressViaXForwardedForHeader(): void
    {
        $request = $this->createRequestWithHeaders(['X-Forwarded-For' => '192.168.1.2, 10.0.0.1']);
        $userInfo = $this->userInfoService->get($request);
        $this->assertEquals('192.168.1.2', $userInfo['ip']);
    }
    
    public function testIpAddressViaRemoteAddr(): void
    {
        $request = $this->createRequestWithHeaders(['REMOTE_ADDR' => '192.168.1.3']);
        $userInfo = $this->userInfoService->get($request);
        $this->assertEquals('192.168.1.3', $userInfo['ip']);
    }

    public function testIpAddressFallback(): void
    {
        $request = $this->createRequestWithHeaders([]);
        $userInfo = $this->userInfoService->get($request);
        $this->assertEquals('0.0.0.0', $userInfo['ip']);
    }

    public function testIpHeaderPreferenceOrder(): void
    {
        $request = $this->createRequestWithHeaders([
            'CF-Connecting-IP' => '1.1.1.1',
            'X-Forwarded-For' => '2.2.2.2',
            'REMOTE_ADDR' => '3.3.3.3',
        ]);
        $userInfo = $this->userInfoService->get($request);
        $this->assertEquals('1.1.1.1', $userInfo['ip']);
    }
}
