<?php
// src/Enum/OtpVerifyOutcome.php
declare(strict_types=1);

namespace App\Enum;

/**
 * What the platform answered to an OTP verification. OtpApiService maps the platform's
 * statusCode and subscriptionStatus to it (see docs/provider_status_codes.md).
 */
enum OtpVerifyOutcome: string
{
    case VERIFIED = 'verified';
    case NOT_SUBSCRIBED = 'not_subscribed';
    case INVALID = 'invalid';
    case NOT_FOUND = 'not_found';
    case EXPIRED = 'expired';
    case PROVIDER_ERROR = 'provider_error';
}
