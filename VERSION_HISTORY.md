# Version History

## 8. Google Analytics 4 Implementation & Minor refactoring of browser pixel
**Author:** Chinmoy Barua
**Date:** 2025-12-03
**Summary:**
Implemented Google Analytics 4 (GA4) to track user traffic, engagement, and conversion funnels. This includes configuration updates, controller modifications to pass the Measurement ID, and template updates to embed the tracking script.

**Key Changes:**
- **Configuration:**
  - Added `GA_MEASUREMENT_ID` to `.env`.
  - Updated `config/app.php` to expose `ga_measurement_id`.
- **Controllers:**
  - Updated `FormController`, `OtpController`, and `ThankYouController` to pass `gaMeasurementId` to views.
  - Updated `FormController` to only pass country code to the view if a valid phone number is submitted.
- **Templates:**
  - Added standard GA4 tracking script to `phone_form.php`, `otp_form.php`, and `thanks.php`.
  - Moved browser pixel advanced matching script from `phone_form.php`, `otp_form.php` and `thanks.php` to `_layout_header.php`.
- **Testing:**
  - Added `testGoogleAnalyticsOnPhoneForm`, `testGoogleAnalyticsOnOtpForm`, and `testGoogleAnalyticsOnThanksPage` to `RegistrationFlowCest.php`.

---

## 7. Facebook CAPI & Browser Pixel Advanced Matching
**Author:** Chinmoy Barua
**Date:** 2025-12-03
**Summary:**
Implemented Advanced Matching for both Facebook CAPI and Browser Pixel to improve event match quality. Optimized frontend forms for better user experience and conversion. Refactored `FacebookCapiService` for robustness and flexibility.

**Key Changes:**
- **Facebook CAPI & Pixel:**
  - Updated `FacebookCapiService` to support `external_id` and `country`.
  - Updated Controllers (`FormController`, `OtpController`, `ThankYouController`) to pass user data to CAPI and views.
  - Implemented Advanced Matching in Browser Pixel (`fbq('init')`) using `external_id` and `country`.
- **Frontend Optimization:**
  - Added `autocomplete="tel"` to phone input.
  - Added `autocomplete="one-time-code"`, `inputmode="numeric"`, and `pattern="\d*"` to OTP input (WebOTP support).
- **Testing:**
  - Updated Unit Tests to verify data passing and CAPI service logic.
  - Updated Acceptance Tests to verify Pixel initialization and event firing.

---

## 6. Mock API Enhancements & OtpController Rate Limit Fallback And Refactoring
**Author:** Chinmoy Barua
**Date:** 2025-12-02
**Summary:**
Enhanced the Mock API to support dynamic failure simulation and added comprehensive acceptance tests for fallback scenarios. Refactored `OtpController` to improve code quality and maintainability.

**Key Changes:**
- **Mock API:**
  - Updated `MockApiController.php` to dynamically simulate success/failure based on URL keywords.
  - Added support for simulating system errors during verification (OTP '000000').
- **Acceptance Tests:**
  - Added `testOtpFallback` to verify fallback during verification failure.
  - Added `testRateLimitFallback` to verify fallback during rate limit exhaustion.
  - Updated `.env.test` with a chain of fallback URLs.
- **Refactoring:**
  - Added Rate limit fallback logic with clear rate limit reset time when new otp is sent.
  - Refactored `OtpController.php` by extracting complex logic into private methods (`validateOtpRequest`, `processOtp`, `attemptFallback`, `handleFallbackSuccess`, `handleFallbackFailure`).
  - Improved readability and testability of the controller.

---

## 5. UI/UX Improvements & Test Updates
**Author:** Chinmoy Barua
**Date:** 2025-11-29
**Summary:**
Refined the phone number input form with better styling and validation feedback. Updated controller tests to align with recent changes.

**Key Changes:**
- **Frontend:**
  - Updated `templates/phone_form.php` (likely improved input structure or classes).
  - Updated `public/assets/css/blog.css` with new styles, layout improvements, and refactored to follow standards.
- **Controllers:**
  - `FormController` , `OtpController` & `ThankYouController`: Updated redirects to use relative paths (`./` and `otp`) for better compatibility.
- **Testing:**
  - Updated `ThankYouControllerTest.php`, `OtpControllerTest.php`, and `FormControllerTest.php` to reflect recent logic changes or fix regressions.

---

## 4. Log Organization & Database Optimization
**Author:** Chinmoy Barua
**Date:** 2025-11-25
**Summary:**
Implemented a log organization script, optimized SQLite database performance with WAL mode, and refined Facebook Pixel integration.

**Key Changes:**
- **Log Management:**
  - Created `log_organizer.py` to parse and group application logs by `visitor_id`.
- **Database Optimization:**
  - Enabled **WAL (Write-Ahead Logging) Mode** and **Busy Timeout (5s)** for `SimpleUserLoggerService` and `RateLimiterService` to resolve "database is locked" errors.
  - Updated `RateLimiterService` to store `reset_at` as human-readable `DATETIME` text.
- **Frontend & Analytics:**
  - Updated templates to conditionally render Facebook Pixel scripts based on configuration.
- **Testing:**
  - Added unit tests for database concurrency settings and date formats.
  - Updated acceptance tests to conditionally check for Pixel scripts.
- **Configuration:**
  - Updated `.gitignore` to exclude SQLite WAL/SHM files and log text exports.

---

## 3. New Architecture
**Author:** Chinmoy Barua
**Date:** 2025-11-24
**Summary:**
Introduced a new OTP chat application architecture with dedicated services, controllers, validation, fallback app URLs, and comprehensive tests.

**Key Changes:**
- **Architecture:**
  - Implemented dedicated Service and Controller layers.
  - Added Request Validation layer.
  - Implemented Fallback App URLs.
- **Testing:**
  - Added comprehensive tests covering the new architecture.
- **Refactoring:**
  - Major refactor of the OTP chat application structure to improve maintainability and scalability.

---

## 1. `69cccce` - Added missing page view events
**Author:** Chinmoy Barua
**Date:** 3 weeks ago
**Summary:**
This commit introduces changes primarily focused on adding page view events, likely for analytics or tracking purposes. It also includes significant updates to the testing suite.

**Key Changes:**
- **Configuration & Bootstrap:**
  - Modified `bootstrap/app.php` (likely registering new middleware or event listeners).
  - Modified `config/app.php` (configuration updates).
- **Tests:**
  - Added `tests/Acceptance/RegistrationFlowCest.php` (125 lines), indicating new acceptance tests for the registration flow.
  - Updates to other test files.
- **Files Changed:** 11 files changed, 442 insertions(+), 77 deletions(-).

---

## 2. `a225606` - Init commit
**Author:** Chinmoy Barua
**Date:** 3 weeks ago
**Summary:**
This is the initial commit of the project. It established the codebase structure.

**Key Changes:**
- **Massive Addition:** 43 files changed, 8173 insertions(+).
- **Project Structure:**
  - Set up `src/`, `public/`, `config/`, `bootstrap/`, `tests/`, and `vendor/` directories.
  - Added core configuration files like `.env`, `composer.json`, `phpunit.xml`.
