<?php
namespace App\Service;

interface OtpApiInterface
{
    public function getOtp(string $platform, string $subscriberId, array $metaData): array;
    public function verifyOtp(string $platform, string $referenceNo, string $otp): array;
}