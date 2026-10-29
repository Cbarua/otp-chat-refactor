<?php
// src/Enum/OtpRequestOutcome.php
declare(strict_types=1);

namespace App\Enum;

/**
 * What the platform answered to an OTP request, after app fallback. OtpApiService maps the
 * platform's statusCode to it (see docs/provider_status_codes.md).
 */
enum OtpRequestOutcome: string
{
    case SENT = 'sent';
    case ALREADY_REGISTERED = 'already_registered';
    case TEMPORARY_FAILURE = 'temporary_failure';
    case MAX_REQUESTS = 'max_requests';
    case FAILED = 'failed';
}
