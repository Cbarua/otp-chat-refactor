<?php
// tests/OtpVerificationTokenTest.php

use PHPUnit\Framework\TestCase;
use App\DTO\OtpVerificationToken;

class OtpVerificationTokenTest extends TestCase
{
    public function testInstantiateAndGetters(): void
    {
        $time = time();
        $token = new OtpVerificationToken(
            referenceNo: 'ref_123',
            usedApiUrl: 'https://api.example.com',
            platform: 'ideamart',
            createdAt: $time,
            failedUrls: ['https://fail.example.com']
        );

        $this->assertEquals('ref_123', $token->referenceNo);
        $this->assertEquals('https://api.example.com', $token->usedApiUrl);
        $this->assertEquals('ideamart', $token->platform);
        $this->assertEquals($time, $token->createdAt);
        $this->assertEquals(['https://fail.example.com'], $token->failedUrls);
        $this->assertFalse($token->isExpired(300));
    }

    public function testIsExpired(): void
    {
        $pastTime = time() - 400;
        $token = new OtpVerificationToken('ref_123', 'https://api.example.com', 'ideamart', $pastTime);

        $this->assertTrue($token->isExpired(300));
        $this->assertFalse($token->isExpired(500));
    }

    public function testArrayAccessAndBackwardCompatibility(): void
    {
        $token = new OtpVerificationToken(
            referenceNo: 'ref_123',
            usedApiUrl: 'https://api.example.com',
            platform: 'ideamart',
            createdAt: time()
        );

        $this->assertTrue(isset($token['referenceNo']));
        $this->assertTrue(isset($token['usedApiUrl']));
        $this->assertEquals('ref_123', $token['referenceNo']);
        $this->assertEquals('https://api.example.com', $token['usedApiUrl']);
    }

    public function testFromArrayFactory(): void
    {
        $data = [
            'referenceNo' => 'ref_999',
            'usedApiUrl' => 'https://api2.example.com',
            'platform' => 'mspace',
            'createdAt' => time()
        ];

        $token = OtpVerificationToken::fromArray($data);
        $this->assertEquals('ref_999', $token->referenceNo);
        $this->assertEquals('mspace', $token->platform);
    }
}
