# OtpController Documentation

## Overview

The `OtpController` is responsible for handling the OTP (One-Time Password) verification process in the registration flow. It manages the display of the OTP entry form, processes user submissions, validates OTPs against an external API, handles rate limiting, and manages fallback scenarios in case of API failures or verification errors.

## Key Functionalities

### 1. Displaying the OTP Form (`showOtpForm`)

-   **Security Check**: Verifies that the user has a valid `otp_token` in the session. If not, the user is redirected to the home page (`./`).
-   **CAPI Event Handling**:
    -   Triggers a `PageView` event for Facebook CAPI.
    -   Checks for a pending `Lead` event (from the previous phone number submission step) and fires it if present.
-   **View Preparation**:
    -   Retrieves configuration settings (pixels, test event codes).
    -   Generates a CSRF token for the form.
    -   Retrieves any error messages from the session to display to the user.
    -   Clears session error messages and lead event flags after use.

### 2. Handling OTP Submission (`handleOtpForm`)

This is the main entry point for processing the OTP form submission. It orchestrates the validation and verification process.

-   **Request Validation (`validateOtpRequest`)**:
    -   **CSRF Check**: Validates the submitted CSRF token. If invalid, logs a warning and redirects with a security error.
    -   **Session Check**: Ensures the `otp_token` still exists. If missing, redirects to the home page.
-   **Rate Limiting (`checkRateLimit`)**:
    -   Checks if the user (identified by `visitor_id`) has exceeded the maximum number of attempts (5 attempts per 10 minutes).
    -   If the limit is exceeded, it triggers the fallback mechanism (`handleFailedVerification`) with `isRateLimit = true`.
-   **OTP Processing (`processOtp`)**:
    -   **Format Validation**: Uses `Validator::validateOtp` to ensure the OTP is a 6-digit number. If invalid, redirects with an error message.
    -   **API Verification**: Calls `OtpApiService::verifyOtp` to verify the OTP with the external provider.
    -   **Response Handling**: Passes the API response to `handleVerificationResponse`.

### 3. Handling Verification Responses (`handleVerificationResponse`)

Routes the API response to the appropriate handler based on the status code.

-   **Success**: If status is `success`, calls `handleSuccessfulVerification`.
-   **Invalid OTP**: If status is `Invalid OTP`, sets an error message and redirects the user to try again.
-   **Unexpected Error**: For any other status, logs the warning and triggers the fallback mechanism (`handleFailedVerification`).

### 4. Successful Verification (`handleSuccessfulVerification`)

Finalizes the registration process upon successful OTP verification.

-   **Platform Check**: Ensures the platform (e.g., 'mspace', 'ideamart') is known.
-   **Subscription Status**: Checks if the user is `REGISTERED` or `INITIAL CHARGING PENDING`.
-   **Redirect**:
    -   If successful, generates a registration ID (`reg_id`), stores it in the session, and redirects to the `thanks` page.
    -   If the subscription status is invalid, redirects back to the OTP page with a generic error.

### 5. Fallback Mechanism (`handleFailedVerification`)

Handles scenarios where verification fails due to system errors, network issues, or rate limiting. It attempts to request a *new* OTP from a different gateway URL.

-   **Data Validation**: Checks if necessary data (subscriber ID, platform, failed URL) is available.
-   **Fallback Attempt (`attemptFallback`)**:
    -   Aggregates all previously failed URLs (`failedUrls`) to ensure they are excluded from the new request.
    -   Calls `OtpApiService::getOtp` to request a new OTP from a provider *not* in the failed list.
-   **Fallback Success (`handleFallbackSuccess`)**:
    -   If a new OTP is successfully retrieved:
        -   **Clear Rate Limit**: Resets the rate limiter for the user, allowing them to try verifying the new OTP immediately.
        -   **Update Token**: Updates the session with the new verification token and the updated list of `failedUrls`.
        -   **User Feedback**: Sets a "New OTP sent" message and redirects to the OTP page.
-   **Fallback Failure (`handleFallbackFailure`)**:
    -   If no new OTP could be retrieved (all URLs exhausted):
        -   Sets a specific error message (`ERROR_RATE_LIMIT` if triggered by rate limiting, otherwise `ERROR_GENERIC`).
        -   Redirects to the OTP page.

## User Scenarios

| Scenario | User Action | System Behavior | Outcome |
| :--- | :--- | :--- | :--- |
| **Normal Flow** | User enters valid OTP. | Verifies OTP -> Success -> Checks Subscription. | Redirects to `/thanks`. |
| **Invalid OTP** | User enters wrong OTP. | Verifies OTP -> Returns "Invalid OTP". | Redirects to `/otp` with "Invalid OTP" error. |
| **Invalid Format** | User enters "123" or "abc". | Validates format -> Fails. | Redirects to `/otp` with "Must be 6 digits" error. |
| **Rate Limit Exceeded** | User tries 6 times. | Checks limit -> Exceeded -> Triggers Fallback. | Tries to get NEW OTP. If success, user gets new OTP and can try again. |
| **System Error** | API returns 500/Error. | Verifies OTP -> Returns Error -> Triggers Fallback. | Tries to get NEW OTP from *different* URL. |
| **Fallback Success** | Fallback triggered. | `OtpApiService` finds working URL. | Clears rate limit, updates session, shows "New OTP sent". |
| **Fallback Failure** | Fallback triggered. | All URLs fail. | Shows "Too many attempts" or "An error occurred". |
| **Session Expiry** | User waits too long. | Session token expires. | Redirects to Home Page (`./`). |
| **CSRF Attack** | Malicious form submit. | CSRF token validation fails. | Redirects to `/otp` with "Security check failed". |

## Code Structure

The controller is refactored into smaller, focused private methods for better maintainability:

-   `validateOtpRequest(Request $request): ?Response`
-   `processOtp(Request $request, array $token): Response`
-   `attemptFallback(string $platform, string $subscriberId, Request $request, array $allFailedUrls): array`
-   `handleFallbackSuccess(array $response, array $allFailedUrls): Response`
-   `handleFallbackFailure(bool $isRateLimit): Response`
-   `generateRandomId(string $prefix): string`
-   `checkRateLimit(string $visitorId): bool`
