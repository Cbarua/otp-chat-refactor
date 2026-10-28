# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

PHP 8.4 (no framework) SMS-OTP subscription landing page for Sri Lankan / Bangladeshi carriers, with Facebook Pixel + CAPI and GA4 conversion tracking. `composer.json` is the source of truth for PHP version and script names; the README's `PHP 8.2`, `tests/Unit/`, `dev:edgedriver` and `test:stop` are stale.

## Commands

```bash
composer install
composer test:unit                                   # PHPUnit, all tests in tests/*Test.php
vendor/bin/phpunit tests/ValidatorTest.php --testdox # single file
vendor/bin/phpunit --filter testMethodName           # single test
composer test:coverage                               # needs Xdebug; HTML in coverage-report/, keep line coverage >= 95%
composer test:ac                                     # full acceptance run (Acceptance + NoJs suites)
composer test:ac -- NoJs                             # args are passed to `codecept run`
composer dev:frontend                                # app on localhost:8080
composer dev:mockapi                                 # mock OTP provider on localhost:8081
docker compose up -d --build                         # app + mock on the OTP server's stack (Amazon Linux 2023, httpd, PHP 8.4 php-fpm), same ports; run from the repo root
docker compose exec app php vendor/bin/phpunit       # unit tests on the server's PHP
```

`test:ac` runs `scripts/run-acceptance.php`, which hands over to `run-acceptance.sh` (macOS/Linux) or `run-acceptance.bat` (Windows, expects `C:\webdriver\msedgedriver.exe`); the shell script starts both PHP servers plus a WebDriver on port 10888 from `./webdriver/msedgedriver` or `./webdriver/chromedriver` (gitignored, download it yourself), then cleans up. When ports 8080/8081 are already served (the compose stack), the script reuses them instead of starting `php -S`, so prefer `docker compose up -d --build` first: it runs the app and the mock on the server's PHP, not the host's. While the stack runs, the script also runs Codeception inside the `app` container (the Cests reset `logs/userlog.sqlite`, and SQLite locks don't cross the bind mount, so host-side writes can SIGBUS php-fpm) and starts the WebDriver with `--allowed-ips=127.0.0.1 --allowed-origins=http://host.docker.internal:10888` so the container can reach it. To run a suite by hand against the stack, use `docker compose exec app vendor/bin/codecept run NoJs`, not the host's `vendor/bin/codecept`. CI runs only `vendor/bin/phpunit` on PHP 8.4.

**Deploys:** `.github/workflows/cd.yml` runs on every push to `main` **and `test`**. It SSHes to EC2 and does `git reset --hard` + `composer install --no-dev` on every app instance under `/var/www/html/*/*`, so pushing to `test` ships to production hosts. Those hosts are Amazon Linux 2023 with httpd 2.4 and PHP 8.4 under php-fpm (`display_errors` off); each instance has a vhost whose DocumentRoot is its `public/` with `AllowOverride All`, which is what `docker/Dockerfile` mirrors.

## Architecture

Request path: `public/index.php` (front controller, security headers, global 500 handler) → `bootstrap/app.php` (Pimple container) → FastRoute with `routes/web.php`. Routes are `'FormController@method'` strings, and the controller is fetched as `$container['FormController']`, so a controller's container key must equal its class short name. Every new service or controller gets registered by hand in `bootstrap/app.php`. `session_start()` runs after the autoloader on purpose: `OtpVerificationToken` DTOs are stored in the session.

**Registration flow** (full step-by-step and the AJAX/HTML response table in `docs/registration_flow.md`; domain terms in `GLOSSARY.md`, use its words):
`GET/POST /` `FormController` → `GET/POST /otp` `OtpController` → `GET /thanks` `ThankYouController`. Both steps of the OTP flow live in `OtpFlowService` (`requestOtp()` for the phone submission, `processVerification()` for the OTP submission); the controllers only check CSRF (and the session token on `/otp`), call the service and translate its `OtpFlowResult`. The service always writes the HTML flash message to the session and returns the result; on AJAX the controller clears that flash and returns `$result->toArray()`. The session is the state machine between steps; every key is in `App\Enum\SessionKey` except the thank-you PageView id, which `ThankYouController` stores under the literal `'page_view_id_thanks'` (the enum's `PAGE_VIEW_ID_THANK_YOU` spells it differently and is unused). `FormController` and `ThankYouController` still read their own `SESSION_*` constants; `OtpController` keeps its only for older tests. `otp_token` can be the literal `true` (SMS-fallback mode with no real token), so check for array/`ArrayAccess` before you read it.

**Dual-mode POSTs:** each POST handler serves both plain form posts (session flash message + redirect) and AJAX (`$request->isXmlHttpRequest()` → JSON `{status, message, redirect, showSmsLink}`, consumed by `public/assets/js/phone-form.js` / `otp-form.js`). A change to a handler has to keep both paths working; the NoJs Codeception suite covers the non-JS path.

**OTP providers:** `config/carriers.php` maps a number's prefix to a platform (`ideamart`, `mspace`, `bdapps`). Each platform has an ordered list of apps (base URLs) from JSON env vars (`IDEAMART_URLS`, …). `OtpApiService` posts to `{base}/getOtp.php` / `verifyOtp.php` and moves down the list until it gets `statusCode === 'S1000'` (app fallback). The token records `usedApiUrl` and `failedUrls`: the app fallback after a failed verification skips apps that already failed, while token renewal after an expired or unknown OTP walks the platform's full list again. Provider outcomes are matched by literal status strings (`App\Enum\ApiStatus` plus `OtpFlowService` constants, including the provider's own typo `'OTP request has being expired'`); keep them byte-exact.

**SMS fallback** (SMS link instead of the OTP input) turns on only when both `SMS_NUMBER` and `SMS_KEYWORD` are set. Triggers: temporary provider failure, max-requests block (also sets a 60-min `otp_max_requests:{phone}` block), 3 invalid OTPs, or OTP not found.

**Tracking:** `AnalyticsTrackerService` handles PageView/Lead/CompleteRegistration for CAPI plus the SQLite visit log. The container returns `null` for `FacebookCapiService` when `FBCAPI_TOKEN` is empty. Event IDs (`pgview-*`, `lead-*`, `reg-*`) are kept in the session so Pixel and CAPI deduplicate. The container key `UserLoggerService` holds a `SimpleUserLoggerService`.

**Storage:** one SQLite file (`DB_PATH`, WAL mode) holds both the rate limits and the visit log. Monolog writes JSON (`OrderedJsonFormatter`) to the `Logger` (app) and `CapiLogger` channels, and `SessionIdProcessor` adds the `visitor_id` to every record.

## Test hooks

`config/app.php` reads cookies, which is how acceptance tests reach the mock provider, but only when the PHP process has `TEST_HOOKS=1` in its real environment (`getenv()`; a `.env` file can't set it). The `test:ac`, `test:acceptance`, `dev:mockapi` and `dev:frontend` composer scripts set it with `@putenv`, and the compose stack sets it in `compose.yaml` (php-fpm gets it through the pool's `env[]` line in `docker/Dockerfile`); production never does, so there every test cookie is ignored. The Cests `require config/app.php` themselves to find their log folders, so a hand-run Codeception needs the switch too (`docker compose exec app …` has it). With the switch on, `APP_ENV=testing` loads `.env.test` instead of `.env`; after that, `TEST_IDEAMART_URLS` overrides the URL list, and `TEST_LOG_DIR` + `TEST_CLASS_NAME` redirect the logs and are forwarded to the provider (config key `api_test_cookies`, passed to `OtpApiService`). `DISABLE_SMS_FALLBACK=true` clears the SMS config. The mock API (`public/backend-test-api/`) answers 404 without the switch; with it, it chooses its behaviour from the gateway path segment (`/fail1/getOtp.php`, `/success/...`); see `MockApiController.php`. A `testapi` gateway proxies to `http://telco.api` (the telco-sample-api mock, a separate project), so the four tests that use it need that server answering on port 80 of the host; the compose stack reaches it through the host gateway.

Unit tests sit flat in `tests/` and use PHPUnit `createMock` (Mockery is also available). `SessionService` is usually mocked over an in-memory `$sessionData` array.

## Conventions

The full rules are in `.agents/rules/coding-standards.md` and `.agents/rules/workflow.md`. Key points:

- Controllers extend `BaseController` (`render()` / `json()` / `redirect()`) and stay thin; logic goes in `src/Service/`.
- Session access only through `SessionService`; services never touch superglobals.
- SQLite queries use prepared statements; template output goes through `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`; every POST validates its CSRF token.
- Secrets come from `.env` through `config/app.php` only.
- New public methods get PHPDoc; new or changed services, controllers and utils get unit tests (happy path and error path).
- Commits follow Conventional Commits with project scopes (`otp`, `capi`, `controller`, `service`, `rate-limit`, `assets`, …). The exact body format is in `.agents/skills/write-commit-message/SKILL.md`.
- For library or API questions (Symfony HttpFoundation, Monolog 3, Guzzle 7, Facebook Business SDK, PHPUnit 11, Codeception 5), fetch current docs with `npx ctx7@latest` (see `.agents/rules/context7.md`).
- If `graphify-out/graph.json` exists, use `graphify query` for architecture questions and run `graphify update .` after code changes.
