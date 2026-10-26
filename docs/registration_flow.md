# Registration & OTP Flow Documentation

**Last Updated:** October 26, 2026

This document details the visitor registration flow within the application, analyzing the interaction between Controllers, Services, Session state, Facebook CAPI events, and External Telco APIs. Domain terms (OTP flow, app, app fallback, token reuse, token renewal, SMS fallback, platform, carrier, visitor) are defined in `GLOSSARY.md`.

Both steps of the OTP flow live in `App\Service\OtpFlowService`: `requestOtp()` for the phone submission and `processVerification()` for the OTP submission. The controllers only validate the CSRF token (and, for `/otp`, the session token), call the service and translate its `OtpFlowResult` into an HTTP response.

---

## Flow Overview Diagram

```
[ Visitor Landing ] (GET /)
        │
        ▼
[ FormController::showPhoneForm ] ──► (Renders Phone Form & PageView CAPI)
        │
        ▼ (POST /)
[ FormController::handlePhoneForm ] ──► CSRF check, then OtpFlowService::requestOtp()
        ├── Rate Limit Check (5/min per IP)
        ├── Phone Normalization
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
        [ OtpController::handleOtpForm ] ──► CSRF & token checks, then OtpFlowService::processVerification()
                ├── Rate Limit Check (5/10min per visitor)
                ├── Input Format Check (6-digit numeric)
                └── OtpApiService::verifyOtp()
                        │
                        ├─► Success: Sets reg_id -> Redirects to /thanks
                        ├─► Expired Token / Not Found (No SMS): Token renewal via handleExpiredToken()
                        ├─► Invalid OTP (>= 3 attempts) / Not Found (With SMS): Shows SMS fallback link
                        └─► Platform API Failure: App fallback via handleFailedVerification()
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
    *   **Facebook CAPI**: Calls `AnalyticsTrackerService::trackPageView()`. Fires `PageView` CAPI event with customer match keys (`ip`, `agent`, `external_id`). Skips `PageView` if there is a pending `SESSION_ERROR`.
    *   **View Preparation**: Renders `phone_form.php` template with CSRF token, GA Measurement ID, Pixel configs, and normalized carrier formats.
    *   **Session Cleanup**: Clears flash state (`SESSION_ERROR`, `SESSION_ALREADY_REGISTERED`, `SESSION_INVALID_OTP_COUNT`, `SESSION_SHOW_SMS_LINK`).

---

### 2. Phone Submission (`POST /`)

*   **Controller**: `src/Controller/FormController.php`, method `handlePhoneForm()`: logs the submission, validates the CSRF token, then calls the service and answers with the result.
*   **Service**: `src/Service/OtpFlowService.php`, method `requestOtp(Request $request, string $rawPhone): OtpFlowResult`.
*   **Request Modes**: Supports both standard HTML form posts and AJAX (`Request::isXmlHttpRequest`). The service always writes the flash message for the HTML flow to the session and returns an `OtpFlowResult`; the controller redirects to `$result->redirectUrl` (HTML) or returns `$result->toArray()` as JSON and clears `SESSION_ERROR` / `SESSION_ALREADY_REGISTERED` (AJAX).
*   **Validation Pipeline**:
    1.  **CSRF Protection** (controller): Validates token using `CsrfService`. Returns JSON/redirects with `ERROR_CSRF` on failure without calling the service.
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

*   **Controller**: `src/Controller/OtpController.php`, method `handleOtpForm()`: CSRF and session token checks, then calls the service and answers with the result (same dual-mode translation as `POST /`).
*   **Service**: `src/Service/OtpFlowService.php`, method `processVerification(Request $request, string $rawOtp): OtpFlowResult`.
*   **Request Modes**: Full dual-mode support for HTML form submits and AJAX JSON.
*   **Validation Pipeline**:
    1.  **CSRF Protection** (controller): Validates form token via `CsrfService`.
    2.  **Session Security** (controller): Verifies `SESSION_OTP_TOKEN` exists and is an array or `ArrayAccess` token (the SMS-fallback sentinel `true` is rejected).
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
        *   Invokes `handleExpiredToken()` for a token renewal: the platform's apps are tried from the first again (apps that already failed are not excluded).
        *   Sets `ERROR_OTP_EXPIRED` ("Your OTP expired. A new OTP has been sent...") and updates `SESSION_OTP_TOKEN`.
    *   **Invalid OTP (`OTP_INVALID`)**:
        *   With SMS config present: increments `SESSION_INVALID_OTP_COUNT`; at 3 sets `SESSION_SHOW_SMS_LINK = true` to reveal the SMS link, otherwise sets `ERROR_OTP_INVALID`.
        *   Without SMS config: sets `ERROR_OTP_INVALID` (no counter).
    *   **OTP Not Found (`OTP_NOT_FOUND`)**:
        *   If SMS config present: Sets `SESSION_SHOW_SMS_LINK = true` and returns SMS fallback link.
        *   If SMS config absent: Invokes `handleExpiredToken()` for a token renewal.
    *   **Platform API Error**: Invokes `handleFailedVerification()` for an app fallback that skips the apps recorded as failed in the token (`$allFailedUrls`).

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
| `POST /otp` | Token Renewed | Redirect `/otp` + Session Error | `{"status": "error", "message": "Your OTP expired..."}` |
| `POST /otp` | Verification Success | Redirect `/thanks` | `{"status": "success", "redirect": "thanks"}` |

---

## Service Layer & State Architecture

### 0. `App\Service\OtpFlowService`
The OTP flow module. Two public methods, one per step, both returning `App\DTO\OtpFlowResult` (`status`, `message`, `redirectUrl`, `showSmsLink`, `ajaxRedirect`; `toArray()` is the AJAX body):
*   `requestOtp($request, $rawPhone)`: rate limit, phone normalization, 60-minute block, token reuse, `OtpApiService::getOtp()` with app fallback, already-registered / SMS fallback / error outcomes.
*   `processVerification($request, $rawOtp)`: rate limit, format check, `OtpApiService::verifyOtp()`, token renewal and app fallback, SMS fallback after repeated failures.
Both write the session state for the next step and the HTML flash message; they never read cookies or produce HTTP responses.

### 1. `App\Service\OtpApiService`
Interface (`OtpApiInterface`) to the platforms' apps:
*   `getOtp($platform, $subscriberId, $metaData, $failedUrls)`: Walks the platform's apps in order (app fallback), skipping `$failedUrls`, and returns an opaque OTP token.
*   `verifyOtp($verificationToken, $otp)`: Uses token metadata (`usedApiUrl`, `referenceNo`) to target the app that issued the OTP.

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
