<?php
// src/DTO/OtpVerificationToken.php
declare(strict_types=1);

namespace App\DTO;

use ArrayAccess;
use JsonSerializable;
use InvalidArgumentException;

/**
 * Immutable value object representing an OTP verification token issued by a carrier.
 */
class OtpVerificationToken implements ArrayAccess, JsonSerializable
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
     * Creates an instance from an array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            referenceNo: (string) ($data['referenceNo'] ?? $data['reference_no'] ?? ''),
            usedApiUrl: (string) ($data['usedApiUrl'] ?? $data['used_api_url'] ?? ''),
            platform: (string) ($data['platform'] ?? ''),
            createdAt: (int) ($data['createdAt'] ?? $data['created_at'] ?? time()),
            failedUrls: (array) ($data['failedUrls'] ?? $data['failed_urls'] ?? [])
        );
    }

    /**
     * Checks if the verification token is older than the TTL in seconds.
     */
    public function isExpired(int $ttlSeconds = 300): bool
    {
        return (time() - $this->createdAt) > $ttlSeconds;
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

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string)$offset, $this->toArray()) ||
               property_exists($this, (string)$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'referenceNo', 'reference_no' => $this->referenceNo,
            'usedApiUrl', 'used_api_url' => $this->usedApiUrl,
            'platform' => $this->platform,
            'createdAt', 'created_at' => $this->createdAt,
            'failedUrls', 'failed_urls' => $this->failedUrls,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidArgumentException('OtpVerificationToken DTO is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidArgumentException('OtpVerificationToken DTO is immutable.');
    }
}
