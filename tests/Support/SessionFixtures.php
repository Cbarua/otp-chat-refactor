<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTO\OtpVerificationToken;
use App\DTO\PhoneNumber;

/**
 * Builds the DTOs the session holds. Fields a test leaves out are empty, so they read as missing.
 */
trait SessionFixtures
{
    private static function token(
        string $referenceNo = '',
        string $usedApiUrl = '',
        string $platform = '',
        int $createdAt = 0,
        array $failedUrls = []
    ): OtpVerificationToken {
        return new OtpVerificationToken($referenceNo, $usedApiUrl, $platform, $createdAt, $failedUrls);
    }

    private static function phone(
        string $capiFormat = '',
        string $telcoFormat = '',
        string $platform = '',
        float $value = 0.01,
        string $rawNumber = ''
    ): PhoneNumber {
        return new PhoneNumber($rawNumber, $telcoFormat, $capiFormat, $platform, $value);
    }
}
