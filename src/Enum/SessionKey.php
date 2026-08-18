<?php
// src/Enum/SessionKey.php
declare(strict_types=1);

namespace App\Enum;

/**
 * Centralized session keys used across controllers, services, and middleware.
 */
enum SessionKey: string
{
    case ERROR_MESSAGE = 'error_message';
    case ALREADY_REGISTERED = 'already_registered';
    case PAGE_VIEW_ID = 'page_view_id';
    case PAGE_VIEW_ID_OTP = 'page_view_id_otp';
    case PAGE_VIEW_ID_THANK_YOU = 'page_view_id_thank_you';
    case VISITOR_ID = 'visitor_id';
    case PHONE_DATA = 'phone_data';
    case FBP = 'fbp';
    case FBC = 'fbc';
    case LEAD_ID = 'lead_id';
    case REG_ID = 'reg_id';
    case OTP_TOKEN = 'otp_token';
    case INVALID_OTP_COUNT = 'invalid_otp_count';
    case SHOW_SMS_LINK = 'show_sms_link';
    case CSRF_TOKEN = 'csrf_token';
}
