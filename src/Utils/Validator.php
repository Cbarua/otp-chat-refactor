<?php
// src/Utils/Validator.php

namespace App\Utils;

use Psr\Log\LoggerInterface;

/**
 * Handles input validation and normalization.
 */
class Validator
{
    /**
     * Validates, normalizes, and identifies platform for a phone number.
     * Replaces formatNumberAndIdentifyPlatform
     *
     * @param string $rawPhone The phone number from user input.
     * @param array $carrierConfig The carrier config array from config/carriers.php
     * @param string $countryCode The country to validate against (e.g., 'LK', 'BD')
     * @return array|null Returns structured data or null if invalid.
     */
    public static function normalizePhone(string $rawPhone, array $carrierConfig, string $countryCode = 'LK', ?LoggerInterface $logger = null): ?array
    {
        // Country not configured
        if (!isset($carrierConfig[$countryCode])) {
            $logger?->warning("Validation failed: Country code not configured.", ['country' => $countryCode]);
            return null;
        }
        
        $config = $carrierConfig[$countryCode];
        $countryCodeDigits = $config['country_code'];
        
        // 1. Validate format
        // Sri Lanka: 07XXXXXXXX (10 digits)
        // Bangladesh: 01XXXXXXXXX (11 digits)
        $pattern = ($countryCode === 'LK') ? '/^07\d{8}$/' : '/^01\d{9}$/';
        
        if (!preg_match($pattern, $rawPhone)) {
            $logger?->warning("Validation failed: Phone number format mismatch.", ['raw_phone' => $rawPhone]);
            return null;
        }

        // 2. Normalize numbers
        $normalized = ltrim($rawPhone, '0'); // 771234567
        $capi = $countryCodeDigits . $normalized; // 94771234567
        $telco = 'tel:' . $capi; // tel:94771234567
        $prefix = substr($normalized, 0, 2); // 77

        // 3. Determine platform
        $platform = $config['platform_map'][$prefix] ?? $config['platform_default'];

        // 4. Determine conversion value
        $value = $config['values']['default'];
        foreach ($config['prefixes'] as $carrier => $carrierPrefixes) {
            if (in_array($prefix, $carrierPrefixes)) {
                $value = $config['values'][$carrier];
                break;
            }
        }

        return [
            'telco_format' => $telco,      // For the OTP API
            'capi_format' => $capi,       // For Facebook CAPI
            'platform' => $platform,  // 'ideamart' or 'mspace'
            'value' => $value         // 0.015, 0.01, etc.
        ];
    }

    /**
     * Validates the OTP format.
     * @param string $otp
     * @return bool
     */
    public static function validateOtp(string $otp): bool
    {
        // Must be exactly 6 digits.
        // This removes the '123456' backdoor.
        return (bool)preg_match('/^\d{6}$/', $otp);
    }
}