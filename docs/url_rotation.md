# URL Rotation Feature

**Last Updated:** August 10, 2026

## Overview
The URL Rotation feature enhances the reliability of the OTP delivery system by dynamically reordering the API URLs used for sending OTPs. This ensures that if the primary URL is experiencing issues or rate limiting, alternative URLs (specifically "priority" URLs) are attempted across multiple supported platforms (e.g., `ideamart`, `mspace`, `bdapps`).

## Configuration
The feature is configured via environment variables in the `.env` file:

- **`URL_ROTATION_PLATFORMS`**: A JSON array (e.g., `'["ideamart", "mspace"]'`) or comma-separated string specifying platforms for which rotation is enabled. *(Falls back to `URL_ROTATION_PLATFORM=ideamart` for single-platform setups).*
- **`URL_ROTATION_PRIORITY`**: A JSON object mapping platform names to lists of priority keywords (e.g., `'{"ideamart": ["success2", "success"], "mspace": ["app2"]}'`). *(Flat arrays automatically map to the primary rotation platform for backward compatibility).*
- **`URL_ROTATION_EXCLUDED_PHONES`**: A JSON array of phone numbers (in local format, e.g., `077...`) that should **not** trigger rotation or increment the submission counter.
- **`URL_ROTATION_EXCLUDED_USERAGENTS`**: A JSON array of User-Agent keyword strings (e.g., `'["Bot", "Crawler", "HeadlessChrome"]'`). Case-insensitive substring matches bypass rotation and counter increments.
- **`URL_ROTATION_INTERVAL`**: *(Optional)* Integer specifying the submission frequency interval for rotation (e.g., `3` for every 3rd submission, `5` for every 5th submission). Defaults to `3` if omitted. Set `URL_ROTATION_INTERVAL=1` to enable rotation on **every request**.

**Example `.env` configuration:**
```env
URL_ROTATION_PLATFORMS='["ideamart", "mspace"]'
URL_ROTATION_PRIORITY='{"ideamart": ["success2", "success"], "mspace": ["mspace2"]}'
URL_ROTATION_EXCLUDED_PHONES='["0771234566", "0771234568"]'
URL_ROTATION_EXCLUDED_USERAGENTS='["Bot", "Crawler", "HeadlessChrome"]'
# Set URL_ROTATION_INTERVAL=1 to enable URL rotation on every request
URL_ROTATION_INTERVAL=5
```

## Logic Flow

1.  **Submission Tracking**:
    -   A global submission counter per platform is maintained in a SQLite database (`logs/userlog.sqlite`).
    -   The counter (`submission_count_<platform>`) is incremented for each valid OTP request for enabled platforms.
    -   **Exclusions**:
        -   Requests from phone numbers listed in `URL_ROTATION_EXCLUDED_PHONES` skip both counter increments and URL rotation (logs notice: `URL Rotation skipped: phone number excluded`).
        -   Requests with User-Agents matching any keyword in `URL_ROTATION_EXCLUDED_USERAGENTS` skip both counter increments and URL rotation (logs notice: `URL Rotation skipped: user-agent keyword matched`).

2.  **Rotation Trigger**:
    -   Rotation is triggered when a platform's submission count is a multiple of **`URL_ROTATION_INTERVAL`** (e.g., every 5th submission when `URL_ROTATION_INTERVAL=5`, or every submission when `URL_ROTATION_INTERVAL=1`).

3.  **URL Reordering**:
    -   When rotation is triggered, `UrlRotationService` reorders the API URLs for that specific platform.
    -   It iterates through the platform's priority list from `URL_ROTATION_PRIORITY`.
    -   For each priority keyword, it matches and moves the corresponding URL to the front of the list.
    -   **Constraint**: Only **one** URL is selected per priority keyword to prevent redundancy.

4.  **Execution**:
    -   The `FormController` passes the reordered list of URLs (`customUrls`) to the `OtpApiService`.
    -   The `OtpApiService` attempts to send the OTP using the URLs in the new order.

## Components

-   **`App\Service\UrlRotationService`**: Core logic for tracking per-platform submission counts and sorting URLs.
-   **`App\Controller\FormController`**: Evaluates platform eligibility, checks phone and User-Agent exclusion rules (logging notices when skipped), and passes custom URL orderings.
-   **`App\Service\OtpApiService`**: Accepts and uses custom URL lists for platform-specific OTP dispatch.

## Testing
-   **Unit Tests**: `tests/Service/UrlRotationServiceTest.php`, `tests/Controller/FormControllerRotationTest.php`
-   **Acceptance Tests**: `tests/Acceptance/UrlRotationCest.php` (verifies rotation triggers and priority usage).
