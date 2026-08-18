<?php
// src/DTO/PhoneNumber.php
declare(strict_types=1);

namespace App\DTO;

use ArrayAccess;
use JsonSerializable;
use InvalidArgumentException;

/**
 * Immutable value object representing a normalized phone number.
 */
class PhoneNumber implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public readonly string $rawNumber,
        public readonly string $telcoFormat,
        public readonly string $capiFormat,
        public readonly string $platform,
        public readonly float $value,
        public readonly string $country = 'LK'
    ) {
    }

    /**
     * Creates a PhoneNumber instance from an array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rawNumber: (string) ($data['raw_number'] ?? $data['rawNumber'] ?? $data['capi_format'] ?? ''),
            telcoFormat: (string) ($data['telco_format'] ?? $data['telcoFormat'] ?? ''),
            capiFormat: (string) ($data['capi_format'] ?? $data['capiFormat'] ?? ''),
            platform: (string) ($data['platform'] ?? ''),
            value: (float) ($data['value'] ?? 0.0),
            country: (string) ($data['country'] ?? 'LK')
        );
    }

    /**
     * Converts the DTO to an associative array with standard keys.
     */
    public function toArray(): array
    {
        return [
            'telco_format' => $this->telcoFormat,
            'capi_format' => $this->capiFormat,
            'platform' => $this->platform,
            'value' => $this->value,
            'country' => $this->country,
            'raw_number' => $this->rawNumber,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ArrayAccess implementation for full backward compatibility
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string)$offset, $this->toArray()) ||
               property_exists($this, (string)$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'telco_format', 'telcoFormat' => $this->telcoFormat,
            'capi_format', 'capiFormat' => $this->capiFormat,
            'platform' => $this->platform,
            'value' => $this->value,
            'country' => $this->country,
            'raw_number', 'rawNumber' => $this->rawNumber,
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new InvalidArgumentException('PhoneNumber DTO is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new InvalidArgumentException('PhoneNumber DTO is immutable.');
    }
}
