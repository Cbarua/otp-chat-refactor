<?php
// src/DTO/OtpVerificationToken.php
declare(strict_types=1);

namespace App\DTO;

use JsonSerializable;

/**
 * Immutable value object representing an OTP verification token issued by a carrier.
 */
class OtpVerificationToken implements JsonSerializable
{
    public function __construct(
        public readonly string $referenceNo,
        public readonly string $usedApiUrl,
        public readonly string $platform,
        public readonly int $createdAt,
        public readonly array $failedUrls = []
    ) {
    }

    /**
     * Returns a new instance with updated failedUrls.
     */
    public function withFailedUrls(array $failedUrls): self
    {
        return new self(
            referenceNo: $this->referenceNo,
            usedApiUrl: $this->usedApiUrl,
            platform: $this->platform,
            createdAt: $this->createdAt,
            failedUrls: $failedUrls
        );
    }

    public function toArray(): array
    {
        return [
            'referenceNo' => $this->referenceNo,
            'usedApiUrl' => $this->usedApiUrl,
            'platform' => $this->platform,
            'createdAt' => $this->createdAt,
            'failedUrls' => $this->failedUrls,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
