# Registration & OTP Flow Documentation

**Last Updated:** October 29, 2026

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
    *   **Facebook CAPI**: Calls `AnalyticsTrackerService::trackPhoneForm()`, which returns the Pixel and GA variables for the view. Fires `PageView` CAPI event with customer match keys (`ip`, `agent`, `external_id`). Skips `PageView` if there is a pending `error_message`.
    *   **View Preparation**: Renders `phone_form.php` template with CSRF token, GA Measurement ID, Pixel configs, and normalized carrier formats.
    *   **Session Cleanup**: Clears the flash (`error_message`, `already_registered`) and, through `OtpFlowState::resetAttempts()`, the invalid OTP count and SMS fallback (`invalid_otp_count`, `show_sms_link`).

---

### 2. Phone Submission (`POST /`)

*   **Controller**: `src/Controller/FormController.php`, method `handlePhoneForm()`: logs the submission, validates the CSRF token, then calls the service and answers with the result.
*   **Service**: `src/Service/OtpFlowService.php`, method `requestOtp(Request $request, string $rawPhone): OtpFlowResult`.
*   **Request Modes**: Supports both standard HTML form posts and AJAX (`Request::isXmlHttpRequest`). The service returns an `OtpFlowResult` and writes no flash; `BaseController::respond()` returns `$result->toArray()` as JSON and clears any stale `error_message` / `already_registered` (AJAX), or writes the result's message as the flash and redirects to `$result->next`, or back to the submitting page when it is null (HTML). The flash goes under `already_registered` for an already registered number, and there is none for success, the SMS-fallback results or an expired session.
*   **Validation Pipeline**:
    1.  **CSRF Protection** (controller): Validates token using `CsrfService`. Returns JSON/redirects with `ERROR_CSRF` on failure without calling the service; both send the visitor back to `/`, because only rendering the form issues a new token.
    2.  **IP Rate Limiting**: Checks `phone_submission:{ip}` via `RateLimiterService` (limit: **5 attempts per minute**).
    3.  **Phone Normalization**: Validates and formats number using `Validator::normalizePhone` (identifies carrier and platform, e.g., `mspace`).
    4.  **60-Minute Block Check**: Checks if number is currently blocked (`otp_max_requests:{phone}`). If blocked, returns remaining minutes formatted error.
*   **Token Optimization (Reuse)**:
    *   Checks if an `otp_token` exists in session for the same phone number created less than **5 minutes (300s)** ago.
    *   If valid, reuses the existing token without making redundant external API requests.
*   **API Execution**: Calls `OtpApiService::getOtp($platform, $telcoFormat, $metaData)`.
*   **Outcome Handling** (by `OtpRequestOutcome`, which `OtpApiService` maps from the last app's `statusCode`):
    *   **`SENT` (S1000)**:
        *   `OtpFlowState::startVerificationStep()` stores the phone and the OTP token (`phone_data`, `otp_token`) and a new `lead_id` (`lead-xxxx`) for tracking.
        *   Redirects to `/otp` (HTML) or returns `{"status": "success", "message": null, "redirect": "otp"}` (AJAX).
    *   **`ALREADY_REGISTERED` (E1351)**:
        *   Logs notice detailing which app services user is registered on (`alreadyRegisteredUrls` from `OtpApiService`).
        *   Flashes `You are already registered!` under `already_registered` and redirects to `/`, or returns it with the status `already_registered`, which `phone-form.js` shows in its green box.
    *   **`TEMPORARY_FAILURE` (E1603)**:
        *   If SMS fallback is configured (`sms.number` & `sms.keyword`), calls `startVerificationStep()` without a token, which stores `show_sms_link = true` and the sentinel `true` as `otp_token`, then redirects to `/otp`.
        *   Otherwise, flashes `ERROR_GENERIC` and redirects to `/`.
    *   **`MAX_REQUESTS` (E1853)**:
        *   Blocks phone number for 3600 seconds (`otp_max_requests:{phone}`).
        *   If SMS config present, enters SMS fallback the same way and redirects to `/otp`.
        *   Otherwise, flashes the formatted error message and redirects to `/`.
    *   **`FAILED` (any other code, e.g. E1342 blacklisted, or no reply)**: logs an ERROR, flashes `ERROR_GENERIC` and redirects to `/`.

---

### 3. OTP Entry Page (`GET /otp`)

*   **Controller**: `src/Controller/OtpController.php`
*   **Method**: `showOtpForm()`
*   **Prerequisites**: Requires `otp_token` in session. If absent, logs error and redirects to `/`.
*   **Actions**:
    *   **CAPI Events (`trackOtpForm()`)**:
        *   `PageView`: Fires `PageView` CAPI event for `/otp` with customer match data (skipped if displaying error).
        *   `Lead`: If `OtpFlowState::takeLeadId()` returns a pending `lead_id` (it clears it), fires the `Lead` event to Facebook CAPI and gives the Pixel the same id.
    *   **SMS Fallback Render**: If `$config['sms']` is configured and `show_sms_link` is true, renders the SMS link instructions instead of the OTP input field.
    *   **View Preparation & Cleanup**: Renders `otp_form.php` with CSRF token and unsets `error_message`.

---

### 4. OTP Verification (`POST /otp`)

*   **Controller**: `src/Controller/OtpController.php`, method `handleOtpForm()`: CSRF and session token checks, then calls the service and answers with the result (same dual-mode translation as `POST /`).
*   **Service**: `src/Service/OtpFlowService.php`, method `processVerification(Request $request, string $rawOtp): OtpFlowResult`.
*   **Request Modes**: Full dual-mode support for HTML form submits and AJAX JSON.
*   **Validation Pipeline**:
    1.  **CSRF Protection** (controller): Validates form token via `CsrfService`.
    2.  **Session Security** (controller): Checks `OtpFlowState::inVerificationStep()` and `token()`; the SMS-fallback sentinel `true` reads as no token, and both cases answer "Session expired" and send the visitor to `/`.
    3.  **Rate Limiting**: Checks `otp_verification:{visitorId}` via `RateLimiterService` (limit: **5 attempts per 10 minutes**). If exceeded, triggers `handleFailedVerification`.
    4.  **Format Validation**: Verifies OTP is exactly 6 numeric digits via `Validator::validateOtp`.
*   **API Verification**: Calls `OtpApiService::verifyOtp($token, $rawOtp)`.
*   **Outcome Handling (`handleVerificationResponse`, by `OtpVerifyOutcome`)**:
    *   **`VERIFIED` (S1000 with subscription status `REGISTERED`, `INITIAL CHARGING PENDING` or mspace's `INITIAL_CHARGING_PENDING`)**:
        *   `OtpFlowState::register()` stores a new `reg_id` (`reg-xxxx`) and clears `invalid_otp_count` and `show_sms_link`.
        *   Redirects to `/thanks` or returns `{"status": "success", "message": null, "redirect": "thanks"}`.
    *   **`NOT_SUBSCRIBED` (S1000 with any other subscription status)**: logs CRITICAL "Registration failed. Unexpected Subscription Status", flashes `ERROR_REGISTRATION_FAILED` and stays on `/otp`.
    *   **`EXPIRED` (E1851)**:
        *   Invokes `handleExpiredToken()` for a token renewal: the platform's apps are tried from the first again (apps that already failed are not excluded).
        *   Flashes `ERROR_OTP_EXPIRED` ("Your OTP expired. A new OTP has been sent...") and updates `otp_token`.
    *   **`INVALID` (E1850)**:
        *   With SMS config present: increments `invalid_otp_count`; at 3 sets `show_sms_link = true` and sends the visitor back to `/otp` (the AJAX script reloads after 2 s) to reveal the SMS link, otherwise flashes `ERROR_OTP_INVALID`.
        *   Without SMS config: flashes `ERROR_OTP_INVALID` (no counter).
    *   **`NOT_FOUND` (E1854)**:
        *   If SMS config present: Sets `show_sms_link = true` and sends the visitor back to `/otp` to show the SMS fallback link.
        *   If SMS config absent: Invokes `handleExpiredToken()` for a token renewal.
    *   **`ATTEMPTS_EXHAUSTED` (E1852)**: logs a notice and runs `handleFailedVerification()`, an app fallback to the next app that has not failed.
    *   **`PROVIDER_ERROR` (any other code, or no reply)**: logs CRITICAL "OTP verification failed with an unexpected status" and runs `handleFailedVerification()`, an app fallback that skips the apps recorded as failed in the token (`$allFailedUrls`).

---

### 5. Completion Page (`GET /thanks`)

*   **Controller**: `src/Controller/ThankYouController.php`
*   **Method**: `showThankYouPage()`
*   **Prerequisites**: Requires `reg_id` and `phone_data` in session. If missing, redirects to `/`.
*   **Actions**:
    *   **Facebook CAPI Events**:
        *   Fires `PageView` event for `/thanks`.
        *   Fires `CompleteRegistration` event using `reg_id` as event ID, sending conversion metadata (`currency => 'USD'`, `value` = the carrier's conversion value).
    *   **View Preparation**: Renders `thanks.php` view.
    *   **Session Cleanup**: `OtpFlowState::finish()` unsets `reg_id` and `otp_token` to prevent re-firing conversion events on page refresh; `phone_data` stays.

---

## Dual-Mode Response Summary (AJAX vs. HTML)

| Endpoint | Action / Result | HTML Behavior | AJAX Response (JSON) |
| :--- | :--- | :--- | :--- |
| `POST /` | CSRF Error | Redirect `/` + Session Error | `{"status": "error", "message": "Security check failed...", "redirect": "./"}` |
| `POST /` | Invalid Phone | Redirect `/` + Session Error | `{"status": "error", "message": "Invalid phone number...", "redirect": null}` |
| `POST /` | 60-min Blocked | Redirect `/` + Session Error | `{"status": "error", "message": "Maximum number of OTP requests...", "redirect": null}` |
| `POST /` | Already Registered | Redirect `/` + Already Registered | `{"status": "already_registered", "message": "You are already registered!", "redirect": null}` |
| `POST /` | OTP Requested | Redirect `/otp` | `{"status": "success", "message": null, "redirect": "otp"}` |
| `POST /` | SMS Fallback Triggered | Redirect `/otp` + SMS Mode | `{"status": "error", "message": "...", "redirect": "otp"}` |
| `POST /otp` | CSRF Error | Redirect `/otp` + Session Error | `{"status": "error", "message": "Security check failed...", "redirect": "otp"}` |
| `POST /otp` | Session Expired (no token) | Redirect `/` | `{"status": "error", "message": "Session expired...", "redirect": "./"}` |
| `POST /otp` | Invalid OTP (< 3 tries) | Redirect `/otp` + Session Error | `{"status": "error", "message": "Invalid OTP...", "redirect": null}` |
| `POST /otp` | 3 Tries / SMS Fallback | Redirect `/otp` + SMS Mode | `{"status": "error", "message": "Invalid OTP...", "redirect": "otp"}` |
| `POST /otp` | Token Renewed | Redirect `/otp` + Session Error | `{"status": "error", "message": "Your OTP expired...", "redirect": null}` |
| `POST /otp` | App Fallback Failed | Redirect `/otp` + Session Error | `{"status": "error", "message": "An error occurred..." or "Too many attempts...", "redirect": null}` |
| `POST /otp` | Renewal Failed / Missing Data | Redirect `/` + Session Error | `{"status": "error", "message": "An error occurred...", "redirect": "./"}` |
| `POST /otp` | Verification Success | Redirect `/thanks` | `{"status": "success", "message": null, "redirect": "thanks"}` |

The scripts go to `redirect` at once on success. On an error with a `redirect` they show the message and go after a delay (3 s on `/`, 2 s on `/otp`); `"redirect": null` keeps the visitor on the page.

---

## Service Layer & State Architecture

### 0. `App\Service\OtpFlowService`
The OTP flow module. Two public methods, one per step, both returning `App\DTO\OtpFlowResult` (`status`, `message`, `next`, `flash`; `toArray()` is the AJAX body):
*   `requestOtp($request, $rawPhone)`: rate limit, phone normalization, 60-minute block, token reuse, `OtpApiService::getOtp()` with app fallback, already-registered / SMS fallback / error outcomes.
*   `processVerification($request, $rawOtp)`: rate limit, format check, `OtpApiService::verifyOtp()`, token renewal and app fallback, SMS fallback after repeated failures.
Both write the session state for the next step and leave the HTML flash message to `BaseController::respond()`; they never read cookies or produce HTTP responses.

### 1. `App\Service\OtpApiService`
The only class that reads the platforms' replies. It maps each reply's `statusCode` (and, on verify, `subscriptionStatus`) to `App\Enum\OtpRequestOutcome` / `OtpVerifyOutcome`; the codes mean the same on all three platforms (`docs/provider_status_codes.md`):
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

### 4. `App\Service\OtpFlowState`
Owns the OTP flow's session keys, through `SessionService`. Sessions in flight hold these values serialized, so the keys and the stored types must stay readable across a deploy:
*   `phone_data`: the `PhoneNumber` submitted last.
*   `otp_token`: the `OtpVerificationToken`, or the literal `true` in SMS fallback entered at the request step (`token()` returns null for it).
*   `lead_id`: Pending Facebook Lead event ID.
*   `reg_id`: Proof of successful subscription verification.
*   `invalid_otp_count`: Failed OTP attempt counter.
*   `show_sms_link`: Boolean toggle for rendering SMS fallback.

`visitor_id` (the anonymous long-lived tracking ID), the flash and the analytics ids stay with `SessionService`.
