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
    }
}
