# OtpController Documentation

**Last Updated:** August 10, 2026

## Overview

The `OtpController` is responsible for orchestrating the OTP (One-Time Password) verification stage within the user registration flow. It manages the display of the OTP form, processes both HTML and AJAX form submissions, validates user input, verifies OTPs via external API services (`OtpApiInterface`), handles rate limiting via `RateLimiterService`, manages Facebook CAPI tracking (`PageView` and `Lead` events), and provides robust fallback strategies (including multi-URL API fallback, token auto-renewal for expired/missing OTPs, and SMS fallback links).

## Constants & Configurations

### Session Keys
- `SESSION_OTP_TOKEN` (`'otp_token'`): Array containing the active OTP verification token and metadata (reference number, used API URL, platform, created time, failed URLs).
- `SESSION_PHONE_DATA` (`'phone_data'`): User phone number details in various formats (`capi_format`, `telco_format`, `platform`).
- `SESSION_ERROR` (`'error_message'`): Flash error message for display on the OTP form.
- `SESSION_PAGE_VIEW_ID` (`'page_view_id_otp'`): CAPI event ID for the OTP page view.
- `SESSION_REG_ID` (`'reg_id'`): Registration ID generated upon successful verification.
- `SESSION_LEAD_ID` (`'lead_id'`): CAPI event ID for a pending Lead event from the phone submission step.
- `SESSION_INVALID_OTP_COUNT` (`'invalid_otp_count'`): Counter tracking consecutive invalid OTP attempts.
- `SESSION_SHOW_SMS_LINK` (`'show_sms_link'`): Boolean flag indicating whether to render the SMS fallback link.

### API Statuses
- `OTP_SUCCESS` (`'success'`): OTP verified successfully.
- `OTP_INVALID` (`'Invalid OTP'`): OTP code entered by the user was rejected by the provider.
- `OTP_NOT_FOUND` (`'Could not find OTP'`): Provider could not locate the OTP reference.
- `OTP_STATUS_EXPIRED` (`'OTP request has being expired'`): OTP token expired on the provider backend.
- `SUB_STATUS_PENDING` (`'INITIAL CHARGING PENDING'`): Subscription charging pending.
- `SUB_STATUS_REGISTERED` (`'REGISTERED'`): User successfully subscribed.

### Error Messages
- `ERROR_RATE_LIMIT`: *"Too many attempts. Please try again later."*
- `ERROR_CSRF`: *"Security check failed. Please try again."*
- `ERROR_REGISTRATION_FAILED`: *"Registration failed. Please try again."*
- `ERROR_OTP_INVALID_LENGTH`: *"Invalid OTP. Must be 6 digits."*
- `ERROR_OTP_INVALID`: *"Invalid OTP. Please enter the correct OTP."*
- `ERROR_OTP_NEW`: *"Please try again with the new OTP sent to your phone."*
- `ERROR_OTP_EXPIRED`: *"Your OTP expired. A new OTP has been sent to your phone."*
- `ERROR_GENERIC`: *"An error occurred. Please try again later."*

---

## Key Functionalities

### 1. Displaying the OTP Form (`showOtpForm`)

- **Security Verification**: Ensures `SESSION_OTP_TOKEN` is present in session. If missing, logs error and redirects to home page (`./`).
- **Facebook CAPI Events (`handleCapiEvents`)**:
  - `PageView` Event: Fires `PageView` event with customer match parameters (`ip`, `useragent`, `phone`, `fbp`, `fbc`, `external_id`, `country`) only if there is no pending `SESSION_ERROR` (avoids duplicate tracking on error reloads).
  - `Lead` Event: If a pending `SESSION_LEAD_ID` exists from the phone step, sends the `Lead` CAPI event and clears `SESSION_LEAD_ID`.
- **SMS Fallback Link Visibility**:
  - Evaluates whether SMS configuration (`$config['sms']['number']` and `$config['sms']['keyword']`) is defined.
  - SMS link is rendered ONLY if both credentials exist AND `SESSION_SHOW_SMS_LINK` is `true`.
- **View Data & Flash Message Cleanup**:
  - Prepares template data including CSRF token, GA Measurement ID, Facebook Pixel/Test Event codes, and SMS configuration.
  - Unsets `SESSION_ERROR` and `SESSION_LEAD_ID` after preparing the view data.

### 2. Handling OTP Submission (`handleOtpForm`)

Orchestrates validation, rate limiting, OTP verification, and response handling for both standard HTML form submits and AJAX requests (`Request::isXmlHttpRequest`).

- **Request & Session Validation (`validateOtpRequest`)**:
  - **CSRF Token**: Validates form token via `CsrfService`. Returns JSON or redirects with `ERROR_CSRF` on failure.
  - **Session Token Structure**: Ensures `SESSION_OTP_TOKEN` exists and is a valid array. Returns JSON or redirects to `./` on failure.
- **Rate Limiting (`isRateLimitExceeded`)**:
  - Uses `RateLimiterService` key `otp_verification:{visitorId}`.
  - Enforces maximum **5 attempts per 10-minute (600s) window**.
  - If limit is exceeded, logs warning and invokes `handleFailedVerification($request, $token, true)`.
- **OTP Input Validation & API Execution (`processOtp`)**:
  - Validates format using `Validator::validateOtp($rawOtp)` (must be exactly 6 digits). If invalid, sets `ERROR_OTP_INVALID_LENGTH`.
  - Calls `OtpApiInterface::verifyOtp($token, $rawOtp)`.
  - Routes result to `handleVerificationResponse`.
- **AJAX Error Cleanup**:
  - For AJAX requests, unsets `SESSION_ERROR` at the end of execution to prevent flash messages from leaking into subsequent page reloads.

### 3. Verification Response Handling (`handleVerificationResponse`)

Evaluates the API status and routes to appropriate outcome handlers:

- **Success (`OTP_SUCCESS`)**: Calls `handleSuccessfulVerification`.
- **Expired Token (`OTP_STATUS_EXPIRED`)**: Invokes `handleExpiredToken` to attempt token auto-renewal.
- **SMS Fallback Mode (SMS Config Present)**:
  - `OTP_INVALID`: Increments `SESSION_INVALID_OTP_COUNT`.
    - If count reaches **3**, sets `SESSION_SHOW_SMS_LINK = true` to switch view to SMS fallback.
    - If count < 3, sets `ERROR_OTP_INVALID` message.
    - Returns JSON `{status: 'error', message, showSmsLink}` for AJAX or redirects to `otp`.
  - `OTP_NOT_FOUND`: Immediately sets `SESSION_SHOW_SMS_LINK = true` and logs notice. Returns JSON or redirects to `otp`.
- **Non-SMS Fallback Mode (SMS Config Missing)**:
  - `OTP_INVALID`: Sets `ERROR_OTP_INVALID` message and redirects/returns JSON.
  - `OTP_NOT_FOUND`: Delegates to `handleExpiredToken` to attempt auto-renewal across alternative API endpoints without SMS fallback.
- **Unexpected API Errors**: Logs critical error and calls `handleFailedVerification`.

### 4. Successful Verification (`handleSuccessfulVerification`)

- **Platform Verification**: Validates platform parameter. If missing, logs critical error and redirects to `./`.
- **Subscription Status Check**: Checks if `subscriptionStatus` is `REGISTERED` or `INITIAL CHARGING PENDING`. Note: The `mspace` platform bypasses strict status check for backwards compatibility.
- **Completion & Cleanup**:
  - Generates a unique `reg_id` (`reg-xxxx`) and saves to `SESSION_REG_ID`.
  - Clears `SESSION_INVALID_OTP_COUNT` and `SESSION_SHOW_SMS_LINK`.
  - For AJAX: Returns JSON `{status: 'success', redirect: 'thanks'}`.
  - For HTML: Redirects to `/thanks`.
- **Failure**: If subscription status is invalid for non-mspace platforms, sets `ERROR_REGISTRATION_FAILED` and redirects/returns JSON.

### 5. Expired Token Auto-Renewal (`handleExpiredToken`)

Handles OTP expiration (`OTP_STATUS_EXPIRED`) and missing OTPs (`OTP_NOT_FOUND` when SMS config is absent).

- **Mechanism**:
  - Attempts to request a fresh OTP via `attemptFallback($platform, $subscriberId, $request, $failedUrls)`.
  - Does **not** exclude the current API URL from attempts, as token expiration is a time-bound state rather than an endpoint failure.
- **Success Outcome**:
  - Updates `SESSION_OTP_TOKEN` with the new token.
  - Sets `SESSION_ERROR` to `ERROR_OTP_EXPIRED`.
  - Resets `SESSION_INVALID_OTP_COUNT` and `SESSION_SHOW_SMS_LINK`.
  - Returns JSON error response or redirects to `otp`.
- **Failure Outcome**:
  - Logs error, sets `ERROR_GENERIC`, and redirects/returns JSON redirect to home (`./`).

### 6. Endpoint Fallback Mechanism (`handleFailedVerification`)

Triggered on rate limit exhaustion or unexpected API failures to switch to an alternative provider URL.

- **URL Exclusion**: Combines previously failed URLs (`failedUrls`) with the current endpoint into `$allFailedUrls`.
- **Fallback Execution (`attemptFallback`)**: Requests a new OTP from an endpoint not present in `$allFailedUrls`.
- **Fallback Success (`handleFallbackSuccess`)**:
  - Clears rate limiter counter for `otp_verification:{visitorId}`.
  - Updates `SESSION_OTP_TOKEN` with the new token and accumulated `$allFailedUrls`.
  - Sets `ERROR_OTP_NEW` message.
  - Clears invalid OTP count and SMS link state.
- **Fallback Failure (`handleFallbackFailure`)**:
  - If all fallback URLs fail: sets `ERROR_RATE_LIMIT` (if rate-limited) or `ERROR_GENERIC`.

---

## Response Dual-Mode (AJAX vs. HTML)

Every submission handler transparently supports both traditional HTML form posts and modern AJAX JSON interactions:

| Outcome | HTML Response | AJAX JSON Response Format |
| :--- | :--- | :--- |
| **CSRF Error** | Redirect `otp` + Session Error | `{"status": "error", "message": "Security check failed...", "redirect": "otp"}` |
| **Session Expired** | Redirect `./` | `{"status": "error", "message": "Session expired...", "redirect": "./"}` |
| **Invalid Format** | Redirect `otp` + Session Error | `{"status": "error", "message": "Invalid OTP. Must be 6 digits."}` |
| **Invalid OTP (< 3 tries)** | Redirect `otp` + Session Error | `{"status": "error", "message": "Invalid OTP...", "showSmsLink": false}` |
| **SMS Link Triggered (3 tries / Not Found)** | Redirect `otp` + Show SMS Link | `{"status": "error", "message": "...", "showSmsLink": true}` |
| **OTP Expired (Renewed)** | Redirect `otp` + Session Error | `{"status": "error", "message": "Your OTP expired. A new OTP..."}` |
| **Verification Success** | Redirect `thanks` | `{"status": "success", "redirect": "thanks"}` |
| **Fatal Failure / All Fallbacks Failed** | Redirect `./` or `otp` | `{"status": "error", "message": "...", "redirect": "./"}` |

---

## User Scenarios Matrix

| Scenario | Trigger / Condition | System Action | Result / UI Feedback |
| :--- | :--- | :--- | :--- |
| **Successful OTP Verification** | User submits valid 6-digit OTP | Verifies with API -> Checks subscription status -> Sets `SESSION_REG_ID` | Redirects to `/thanks`. |
| **Invalid OTP (< 3 attempts)** | User enters incorrect 6-digit OTP | Increments `SESSION_INVALID_OTP_COUNT` | Returns "Invalid OTP. Please enter the correct OTP." |
| **Invalid OTP (3rd attempt + SMS Config)** | User fails 3 consecutive times | Sets `SESSION_SHOW_SMS_LINK = true` | Renders SMS fallback link instead of form input. |
| **OTP Not Found (with SMS Config)** | API returns `Could not find OTP` | Sets `SESSION_SHOW_SMS_LINK = true` | Displays SMS link for registration via SMS. |
| **OTP Not Found (no SMS Config)** | API returns `Could not find OTP` | Invokes `handleExpiredToken` to request new OTP | Auto-renews OTP and prompts user to enter new OTP. |
| **Expired OTP Token** | API returns `OTP request has being expired` | Invokes `handleExpiredToken` | Issues new OTP, sets message "Your OTP expired...", resets attempt counter. |
| **Rate Limit Exceeded** | > 5 verification attempts in 10 mins | Triggers fallback to alternative API endpoint | Resets rate limit if new OTP acquired; shows "Too many attempts" if all fail. |
| **API Provider Failure / 500 Error** | Primary API URL fails unexpectedly | Triggers `handleFailedVerification` with `$allFailedUrls` | Obtains new OTP from secondary API URL. |
| **Invalid Input Format** | User enters non-digit or != 6 length | Validates input via `Validator::validateOtp` | Displays "Invalid OTP. Must be 6 digits." |
| **Missing Session Token** | Accessing form without `otp_token` | Checks `SESSION_OTP_TOKEN` | Redirects user to home page (`./`). |
| **CSRF Validation Failure** | Invalid or missing `csrf_token` | Validates via `CsrfService` | Returns security error message. |

---

## Code Structure & Method Reference

```
App\Controller\OtpController (extends BaseController)
├── public showOtpForm(Request $request): Response
├── public handleOtpForm(Request $request): Response
├── private validateOtpRequest(Request $request): ?Response
├── private processOtp(Request $request, array $token): Response
├── private handleCapiEvents(Request $request): void
│   ├── private handlePageViewEvent(Request $request, array $userInfo): void
│   └── private handleLeadEvent(Request $request, array $userInfo): void
├── private handleVerificationResponse(Request $request, array $response, array $token): Response
├── private handleSuccessfulVerification(Request $request, array $response, ?string $platform): Response
├── private handleExpiredToken(Request $request, array $token): Response
├── private handleFailedVerification(Request $request, array $token, bool $isRateLimit = false): Response
│   ├── private attemptFallback(string $platform, string $subscriberId, Request $request, array $allFailedUrls): array
│   ├── private handleFallbackSuccess(Request $request, array $response, array $allFailedUrls): Response
│   └── private handleFallbackFailure(Request $request, bool $isRateLimit): Response
└── private isRateLimitExceeded(string $visitorId): bool
```
