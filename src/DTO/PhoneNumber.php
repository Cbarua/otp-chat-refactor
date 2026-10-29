<?php
// src/DTO/PhoneNumber.php
declare(strict_types=1);

namespace App\DTO;

use JsonSerializable;

/**
 * Immutable value object representing a normalized phone number.
 */
class PhoneNumber implements JsonSerializable
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
}
