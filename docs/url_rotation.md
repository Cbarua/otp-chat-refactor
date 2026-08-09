# URL Rotation Feature

**Last Updated:** August 10, 2026

## Overview
The URL Rotation feature enhances the reliability of the OTP delivery system by dynamically reordering the API URLs used for sending OTPs. This ensures that if the primary URL is experiencing issues or rate limiting, alternative URLs (specifically "priority" URLs) are attempted.

## Configuration
The feature is configured via environment variables in the `.env` file:

- **`OTP_ROTATION_PLATFORM`**: Specifies the platform for which rotation is enabled (e.g., `ideamart`).
- **`OTP_ROTATION_EXCLUDED_PHONES`**: A JSON array of phone numbers (in local format, e.g., `077...`) that should **not** trigger rotation or increment the submission counter.
- **`OTP_URL_PRIORITY`**: A JSON array of string identifiers. When rotation is triggered, URLs containing these strings are prioritized.
- **`URL_ROTATION_INTERVAL`**: *(Optional)* Integer specifying the submission frequency interval for rotation (e.g., `3` for every 3rd submission). Defaults to `3` if omitted.

**Example `.env` configuration:**
```env
OTP_ROTATION_PLATFORM=ideamart
OTP_ROTATION_EXCLUDED_PHONES=["0771234568"]
OTP_URL_PRIORITY=["success2", "success1"]
URL_ROTATION_INTERVAL=3
```

## Logic Flow

1.  **Submission Tracking**:
    -   A global submission counter is maintained in a SQLite database (`logs/userlog.sqlite`).
    -   The counter is incremented for each valid OTP request for the configured platform.
    -   **Exclusion**: Requests from phone numbers listed in `OTP_ROTATION_EXCLUDED_PHONES` do **not** increment the counter.

2.  **Rotation Trigger**:
    -   Rotation is triggered when the submission count is a multiple of **`URL_ROTATION_INTERVAL`** (e.g., every 3rd submission by default, or every 5th submission if `URL_ROTATION_INTERVAL=5`).

3.  **URL Reordering**:
    -   When rotation is triggered, the `UrlRotationService` reorders the available API URLs.
    -   It iterates through the `OTP_URL_PRIORITY` list.
    -   For each priority keyword, it finds the first matching URL in the available list and moves it to the front.
    -   **Constraint**: Only **one** URL is selected per priority keyword to prevent redundancy.

4.  **Execution**:
    -   The `FormController` passes the reordered list of URLs (`customUrls`) to the `OtpApiService`.
    -   The `OtpApiService` attempts to send the OTP using the URLs in the new order.

## Components

-   **`App\Service\UrlRotationService`**: Core logic for counting submissions and sorting URLs.
-   **`App\Controller\FormController`**: Orchestrates the check for rotation conditions and calls the service (injected as an optional dependency).
-   **`App\Service\OtpApiService`**: Accepts and uses a custom list of URLs for OTP dispatch.

## Testing
-   **Unit Tests**: `tests/Service/UrlRotationServiceTest.php`, `tests/Controller/FormControllerRotationTest.php`
-   **Acceptance Tests**: `tests/Acceptance/UrlRotationCest.php` (verifies rotation triggers and priority usage).
