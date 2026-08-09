# Registration & OTP Flow Documentation

**Last Updated:** August 10, 2026

This document details the user registration flow within the application, analyzing the interaction between Controllers, Services, Session state, Facebook CAPI events, and External Telco APIs.

---

## Flow Overview Diagram

```
[ Visitor Landing ] (GET /)
        │
        ▼
[ FormController::showPhoneForm ] ──► (Renders Phone Form & PageView CAPI)
        │
        ▼ (POST /)
[ FormController::handlePhoneForm ]
        ├── Rate Limit Check (5/min per IP)
        ├── CSRF & Phone Normalization
        ├── Check 60-min Block (otp_max_requests)
        └── Token Reuse (< 5 min old) OR OtpApiService::getOtp()
                │
                ├─► Already Registered: Redirects to / with notice
                ├─► Max Requests / Temp Failure: Enables SMS Fallback -> /otp
                └─► Success: Sets otp_token & lead_id -> Redirects to /otp
                        │
                        ▼ (GET /otp)
        [ OtpController::showOtpForm ] ──► (Fires PageView & Lead CAPI, Renders OTP or SMS link)
                        │
                        ▼ (POST /otp)
        [ OtpController::handleOtpForm ]
                ├── CSRF & Rate Limit Check (5/10min per visitor)
                ├── Input Format Check (6-digit numeric)
                └── OtpApiService::verifyOtp()
                        │
                        ├─► Success: Sets reg_id -> Redirects to /thanks
                        ├─► Expired Token / Not Found (No SMS): Auto-renew via handleExpiredToken()
                        ├─► Invalid OTP (>= 3 attempts) / Not Found (With SMS): Shows SMS fallback link
                        └─► Provider API Failure: Triggers multi-URL fallback via handleFailedVerification()
                                │
                                ▼ (GET /thanks)
                [ ThankYouController::showThankYouPage ] ──► (Fires PageView & CompleteRegistration CAPI -> Clears Session)
```

---

## Detailed Step-by-Step Analysis

### 1. Landing Page (`GET /`)

*   **Controller**: `src/Controller/FormController.php`
*   **Method**: `showPhoneForm()`
*   **Actions**:
    *   **Session Setup**: Generates or retrieves long-lived `visitor_id`.
    *   **Facebook CAPI**: Calls `trackVisit()`. Fires `PageView` CAPI event with customer match keys (`ip`, `agent`, `external_id`). Skips `PageView` if there is a pending `SESSION_ERROR`.
    *   **View Preparation**: Renders `phone_form.php` template with CSRF token, GA Measurement ID, Pixel configs, and normalized carrier formats.
    *   **Session Cleanup**: Clears flash state (`SESSION_ERROR`, `SESSION_ALREADY_REGISTERED`, `SESSION_INVALID_OTP_COUNT`, `SESSION_SHOW_SMS_LINK`).

---

### 2. Phone Submission (`POST /`)

*   **Controller**: `src/Controller/FormController.php`
*   **Method**: `handlePhoneForm()`
*   **Request Modes**: Supports both standard HTML form posts and AJAX (`Request::isXmlHttpRequest`).
*   **Validation Pipeline**:
    1.  **CSRF Protection**: Validates token using `CsrfService`. Returns JSON/redirects with `ERROR_CSRF` on failure.
    2.  **IP Rate Limiting**: Checks `phone_submission:{ip}` via `RateLimiterService` (limit: **5 attempts per minute**).
    3.  **Phone Normalization**: Validates and formats number using `Validator::normalizePhone` (identifies carrier and platform, e.g., `mspace`).
    4.  **60-Minute Block Check**: Checks if number is currently blocked (`otp_max_requests:{phone}`). If blocked, returns remaining minutes formatted error.
*   **Token Optimization (Reuse)**:
    *   Checks if an `otp_token` exists in session for the same phone number created less than **5 minutes (300s)** ago.
    *   If valid, reuses the existing token without making redundant external API requests.
*   **API Execution**: Calls `OtpApiService::getOtp($platform, $telcoFormat, $metaData)`.
*   **Outcome Handling**:
    *   **Success (`status: success`)**:
        *   Stores opaque `verificationToken` as `SESSION_OTP_TOKEN`.
        *   Generates `SESSION_LEAD_ID` (`lead-xxxx`) for tracking.
        *   Redirects to `/otp` (HTML) or returns `{"status": "success", "redirect": "otp"}` (AJAX).
    *   **Already Registered (`user already registered`)**:
        *   Logs notice detailing which app services user is registered on.
        *   Sets `SESSION_ALREADY_REGISTERED` and redirects to `/` or returns JSON error.
    *   **Temporary System Failure (`temporary system error`)**:
        *   If SMS fallback is configured (`sms.number` & `sms.keyword`), sets `SESSION_SHOW_SMS_LINK = true` and a dummy `SESSION_OTP_TOKEN`, then redirects to `/otp`.
        *   Otherwise, sets `ERROR_GENERIC` and redirects to `/`.
    *   **Maximum OTP Requests Reached (`maximum number of otp requests reached`)**:
        *   Blocks phone number for 3600 seconds (`otp_max_requests:{phone}`).
        *   If SMS config present, sets `SESSION_SHOW_SMS_LINK = true` and redirects to `/otp`.
        *   Otherwise, sets formatted error message and redirects to `/`.

---

### 3. OTP Entry Page (`GET /otp`)

*   **Controller**: `src/Controller/OtpController.php`
*   **Method**: `showOtpForm()`
*   **Prerequisites**: Requires `SESSION_OTP_TOKEN` in session. If absent, logs error and redirects to `/`.
*   **Actions**:
    *   **CAPI Events (`handleCapiEvents`)**:
        *   `PageView`: Fires `PageView` CAPI event for `/otp` with customer match data (skipped if displaying error).
        *   `Lead`: If `SESSION_LEAD_ID` exists, fires the pending `Lead` event to Facebook CAPI and clears `SESSION_LEAD_ID`.
    *   **SMS Fallback Render**: If `$config['sms']` is configured and `SESSION_SHOW_SMS_LINK` is true, renders the SMS link instructions instead of the OTP input field.
    *   **View Preparation & Cleanup**: Renders `otp_form.php` with CSRF token and unsets `SESSION_ERROR`.

---

### 4. OTP Verification (`POST /otp`)

*   **Controller**: `src/Controller/OtpController.php`
*   **Method**: `handleOtpForm()`
*   **Request Modes**: Full dual-mode support for HTML form submits and AJAX JSON.
*   **Validation Pipeline**:
    1.  **CSRF Protection**: Validates form token via `CsrfService`.
    2.  **Session Security**: Verifies `SESSION_OTP_TOKEN` exists and is a valid array.
    3.  **Rate Limiting**: Checks `otp_verification:{visitorId}` via `RateLimiterService` (limit: **5 attempts per 10 minutes**). If exceeded, triggers `handleFailedVerification`.
    4.  **Format Validation**: Verifies OTP is exactly 6 numeric digits via `Validator::validateOtp`.
*   **API Verification**: Calls `OtpApiInterface::verifyOtp($token, $rawOtp)`.
*   **Outcome Handling (`handleVerificationResponse`)**:
    *   **Success (`OTP_SUCCESS`)**:
        *   Checks subscription status (`REGISTERED`, `INITIAL CHARGING PENDING`, or `mspace` legacy bypass).
        *   Generates `SESSION_REG_ID` (`reg-xxxx`).
        *   Clears `SESSION_INVALID_OTP_COUNT` and `SESSION_SHOW_SMS_LINK`.
        *   Redirects to `/thanks` or returns `{"status": "success", "redirect": "thanks"}`.
    *   **Expired Token (`OTP_STATUS_EXPIRED`)**:
        *   Invokes `handleExpiredToken()` to auto-renew OTP across endpoints (without excluding current URL).
        *   Sets `ERROR_OTP_EXPIRED` ("Your OTP expired. A new OTP has been sent...") and updates `SESSION_OTP_TOKEN`.
    *   **Invalid OTP (`OTP_INVALID`)**:
        *   Increments `SESSION_INVALID_OTP_COUNT`.
        *   If count >= 3 AND SMS config is present, sets `SESSION_SHOW_SMS_LINK = true` to reveal SMS link.
        *   Otherwise, sets `ERROR_OTP_INVALID`.
    *   **OTP Not Found (`OTP_NOT_FOUND`)**:
        *   If SMS config present: Sets `SESSION_SHOW_SMS_LINK = true` and returns SMS fallback link.
        *   If SMS config absent: Invokes `handleExpiredToken()` to auto-renew token via alternative provider URLs.
    *   **API Provider Error**: Invokes `handleFailedVerification()` to attempt multi-URL fallback ($allFailedUrls).

---

### 5. Completion Page (`GET /thanks`)

*   **Controller**: `src/Controller/ThankYouController.php`
*   **Method**: `showThankYouPage()`
*   **Prerequisites**: Requires `SESSION_REG_ID` and `SESSION_PHONE_DATA` in session. If missing, redirects to `/`.
*   **Actions**:
    *   **Facebook CAPI Events**:
        *   Fires `PageView` event for `/thanks`.
        *   Fires `CompleteRegistration` event using `SESSION_REG_ID` as event ID, sending conversion metadata (`currency => 'USD'`, `value => '0.01'`).
    *   **View Preparation**: Renders `thanks.php` view.
    *   **Session Cleanup**: Unsets `SESSION_REG_ID` and `SESSION_OTP_TOKEN` to prevent re-firing conversion events on page refresh.

---

## Dual-Mode Response Summary (AJAX vs. HTML)

| Endpoint | Action / Result | HTML Behavior | AJAX Response (JSON) |
| :--- | :--- | :--- | :--- |
| `POST /` | CSRF Error | Redirect `/` + Session Error | `{"status": "error", "message": "Security check failed..."}` |
| `POST /` | Invalid Phone | Redirect `/` + Session Error | `{"status": "error", "message": "Invalid phone number..."}` |
| `POST /` | 60-min Blocked | Redirect `/` + Session Error | `{"status": "error", "message": "Maximum number of OTP requests..."}` |
| `POST /` | OTP Requested | Redirect `/otp` | `{"status": "success", "redirect": "otp"}` |
| `POST /` | SMS Fallback Triggered | Redirect `/otp` + SMS Mode | `{"status": "error", "message": "...", "showSmsLink": true, "redirect": "otp"}` |
| `POST /otp` | CSRF Error | Redirect `/otp` + Session Error | `{"status": "error", "message": "Security check failed...", "redirect": "otp"}` |
| `POST /otp` | Invalid OTP (< 3 tries) | Redirect `/otp` + Session Error | `{"status": "error", "message": "Invalid OTP...", "showSmsLink": false}` |
| `POST /otp` | 3 Tries / SMS Fallback | Redirect `/otp` + SMS Mode | `{"status": "error", "message": "...", "showSmsLink": true}` |
| `POST /otp` | Token Auto-Renewed | Redirect `/otp` + Session Error | `{"status": "error", "message": "Your OTP expired..."}` |
| `POST /otp` | Verification Success | Redirect `/thanks` | `{"status": "success", "redirect": "thanks"}` |

---

## Service Layer & State Architecture

### 1. `App\Service\OtpApiService`
Gateway interface (`OtpApiInterface`) for interacting with external telco endpoints:
*   `getOtp($platform, $subscriberId, $metaData, $failedUrls)`: Manages API endpoint rotation and returns an opaque verification token.
*   `verifyOtp($verificationToken, $otp)`: Uses token metadata (`usedApiUrl`, `referenceNo`) to target the exact endpoint for verification.

### 2. `App\Service\RateLimiterService`
Manages application-level rate limits:
*   `phone_submission:{ip}`: 5 requests per 60 seconds.
*   `otp_verification:{visitorId}`: 5 verification attempts per 600 seconds.
*   `otp_max_requests:{phone}`: 3600 seconds (60 minutes) temporary block.

### 3. `App\Service\FacebookCapiService`
Handles Server-Side Conversion API event dispatching:
*   Extracts `fbp`, `fbc`, IP address, and User-Agent.
*   Sends asynchronous event payloads for `PageView`, `Lead`, and `CompleteRegistration`.

### 4. `App\Service\SessionService`
Primary session keys powering the flow:
*   `visitor_id`: Anonymous long-lived tracking ID.
*   `phone_data`: Normalized phone data (`capi_format`, `telco_format`, `platform`).
*   `otp_token`: Opaque verification token array.
*   `lead_id`: Pending Facebook Lead event ID.
*   `reg_id`: Proof of successful subscription verification.
*   `invalid_otp_count`: Failed OTP attempt counter.
*   `show_sms_link`: Boolean toggle for rendering SMS fallback.
