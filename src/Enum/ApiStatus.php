<?php
// src/Enum/ApiStatus.php
declare(strict_types=1);

namespace App\Enum;

/**
 * Standard API status and error strings from OTP carriers and providers.
 */
enum ApiStatus: string
{
    case SUCCESS = 'success';
    case ERROR = 'error';
    case ALREADY_REGISTERED = 'user already registered';
    case TEMPORARY_FAILURE = 'temporary system error';
    case MAX_REQUESTS_REACHED = 'maximum number of otp requests reached';
}
