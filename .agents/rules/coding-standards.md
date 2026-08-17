---
trigger: always_on
description: Coding standards and architecture guidelines for the OTP Chat Refactor PHP application.
---

# Coding Standards & Architecture Rules

## 1. Core Architecture & Design Patterns

- **Architecture Style**: Clean MVC architecture with a decoupled Service Layer and Dependency Injection.
- **Namespaces & PSR-4 Autoloading**:
  - `App\` maps to `src/`
  - `Tests\` maps to `tests/`
  - Follow PSR-12 coding standard and PSR-4 autoloading conventions strictly.
- **Dependency Injection (DI)**:
  - Use the Pimple DI container configured in `bootstrap/app.php`.
  - Do NOT instantiate service objects or database connections directly inside controllers or services. Always inject dependencies via constructors.
  - Interface abstraction: Use interfaces for pluggable services (e.g., `UserLoggerInterface`, `OtpApiInterface`) to ensure mockability and modularity.

## 2. PHP 8.4+ & Type Safety

- **Strict Typing**:
  - Always use explicit type declarations for all method arguments, property definitions, and return types.
  - Utilize modern PHP features: constructor property promotion, union types (`int|string`), nullable types (`?string`), and `match` expressions where appropriate.
- **Naming Conventions**:
  - **Classes & Interfaces**: `PascalCase` (e.g., `OtpApiService`, `UserLoggerInterface`).
  - **Methods & Variables**: `camelCase` (e.g., `normalizePhone`, `$visitorId`).
  - **Constants**: `SCREAMING_SNAKE_CASE` (e.g., `SESSION_KEY`).
  - **Config keys / Database columns**: `snake_case` (e.g., `capi_token`, `created_at`).

## 3. Controllers & Presentation Layer

- **Controllers (`src/Controller/`)**:
  - All controllers must extend `App\Controller\BaseController`.
  - Controllers must only orchestrate requests: validate input, call appropriate domain services, and return Symfony `Response` instances (`json()`, `render()`, `redirect()`).
  - Keep controllers thin; domain logic and external communication belong in the Service layer.
- **Templates (`templates/`)**:
  - Templates should contain minimal PHP logic (loops, conditionals, and output only).
  - Always escape user and dynamic output using `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` to prevent XSS.
  - Form templates must include CSRF tokens: `<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">`.

## 4. Service Layer & Business Logic (`src/Service/`)

- **Domain Isolation**:
  - Services must be decoupled from global superglobals (`$_GET`, `$_POST`, `$_SESSION`). Pass necessary values as arguments or use injected wrappers like `SessionService` and Symfony `Request`.
- **HTTP & External Integrations**:
  - Use the injected Guzzle HTTP client (`$container['GuzzleClient']`) with configured connection and request timeouts (e.g., 10s connect, 30s total).
  - External API calls (Ideamart, Mspace, bdapps, Facebook CAPI) must have robust fallback, retry, and error logging mechanisms.
- **Session Management**:
  - Access and manipulate session state exclusively via `SessionService` rather than accessing `$_SESSION` directly.

## 5. Security & Data Integrity

- **CSRF Protection**:
  - Validate CSRF tokens on every state-changing endpoint (POST/PUT/DELETE) using `CsrfService::validate()`.
- **Rate Limiting**:
  - Enforce rate limiting on sensitive actions (e.g., OTP requests and verifications) using `RateLimiterService`.
- **Database Access & SQL Injection Prevention**:
  - SQLite operations (in `logs/userlog.sqlite`) must ALWAYS use prepared statements (`$db->prepare(...)`) with bound parameters. Never concatenate user input into SQL queries.
- **Input Validation & Sanitization**:
  - Validate and normalize all phone numbers and OTP codes via `App\Utils\Validator`.
- **Secret & Credential Management**:
  - Never hardcode tokens, API keys, or passwords. All secrets must come from `.env` via `config/app.php`.

## 6. Logging & Observability

- **Monolog Integration**:
  - Use structured JSON logging via `OrderedJsonFormatter` with Monolog 3.x (`Logger` for app domain events, `CapiLogger` for Facebook CAPI events).
  - Always provide context arrays when logging: `$this->logger->info('OTP request sent', ['phone' => $phone, 'platform' => $platform]);`.
  - Session IDs are automatically injected via `SessionIdProcessor`.
  - Never log raw unmasked secrets, sensitive tokens, or complete credit/auth credentials in plain text.

## 7. Testing Standards

- **Unit Testing**:
  - Maintain comprehensive PHPUnit 11+ test coverage in `tests/`. Target line coverage must stay above **95%**.
  - Isolate external dependencies using PHPUnit mocks (`$this->createMock(...)`) or Mockery.
  - Every new service, controller method, or utility function must have corresponding unit test assertions covering both happy paths and edge/error conditions.
