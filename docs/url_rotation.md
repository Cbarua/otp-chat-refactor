# URL Rotation Feature

**Last Updated:** August 10, 2026

## Overview
The URL Rotation feature enhances the reliability of the OTP delivery system by dynamically reordering the API URLs used for sending OTPs. This ensures that if the primary URL is experiencing issues or rate limiting, alternative URLs (specifically "priority" URLs) are attempted across multiple supported platforms (e.g., `ideamart`, `mspace`, `bdapps`).

## Configuration
The feature is configured via environment variables in the `.env` file:

- **`OTP_ROTATION_PLATFORMS`**: A JSON array (e.g., `'["ideamart", "mspace"]'`) or comma-separated string specifying platforms for which rotation is enabled. *(Falls back to `OTP_ROTATION_PLATFORM=ideamart` for single-platform setups).*
- **`OTP_URL_PRIORITY`**: A JSON object mapping platform names to lists of priority keywords (e.g., `'{"ideamart": ["success2", "success"], "mspace": ["app2"]}'`). *(Flat arrays automatically map to the primary rotation platform for backward compatibility).*
- **`OTP_ROTATION_EXCLUDED_PHONES`**: A JSON array of phone numbers (in local format, e.g., `077...`) that should **not** trigger rotation or increment the submission counter.
- **`URL_ROTATION_INTERVAL`**: *(Optional)* Integer specifying the submission frequency interval for rotation (e.g., `3` for every 3rd submission, `5` for every 5th submission). Defaults to `3` if omitted.

**Example `.env` configuration:**
```env
OTP_ROTATION_PLATFORMS='["ideamart", "mspace"]'
OTP_URL_PRIORITY='{"ideamart": ["success2", "success"], "mspace": ["mspace2"]}'
OTP_ROTATION_EXCLUDED_PHONES='["0771234566", "0771234568"]'
URL_ROTATION_INTERVAL=5
```

## Logic Flow

1.  **Submission Tracking**:
    -   A global submission counter per platform is maintained in a SQLite database (`logs/userlog.sqlite`).
    -   The counter (`submission_count_<platform>`) is incremented for each valid OTP request for enabled platforms.
    -   **Exclusion**: Requests from phone numbers listed in `OTP_ROTATION_EXCLUDED_PHONES` do **not** increment the counter.

2.  **Rotation Trigger**:
    -   Rotation is triggered when a platform's submission count is a multiple of **`URL_ROTATION_INTERVAL`** (e.g., every 5th submission when `URL_ROTATION_INTERVAL=5`).

3.  **URL Reordering**:
    -   When rotation is triggered, `UrlRotationService` reorders the API URLs for that specific platform.
    -   It iterates through the platform's priority list from `OTP_URL_PRIORITY`.
    -   For each priority keyword, it matches and moves the corresponding URL to the front of the list.
    -   **Constraint**: Only **one** URL is selected per priority keyword to prevent redundancy.

4.  **Execution**:
    -   The `FormController` passes the reordered list of URLs (`customUrls`) to the `OtpApiService`.
    -   The `OtpApiService` attempts to send the OTP using the URLs in the new order.

## Components

-   **`App\Service\UrlRotationService`**: Core logic for tracking per-platform submission counts and sorting URLs.
-   **`App\Controller\FormController`**: Evaluates platform eligibility, checks exclusion rules, and passes custom URL orderings (injected as an optional dependency).
-   **`App\Service\OtpApiService`**: Accepts and uses custom URL lists for platform-specific OTP dispatch.

## Testing
-   **Unit Tests**: `tests/Service/UrlRotationServiceTest.php`, `tests/Controller/FormControllerRotationTest.php`
-   **Acceptance Tests**: `tests/Acceptance/UrlRotationCest.php` (verifies rotation triggers and priority usage).
