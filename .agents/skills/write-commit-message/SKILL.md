---
name: write-commit-message
description: |
  Format and write standardized Conventional Commit messages following the project's exact multi-bullet standard:
  a concise type(scope): header in imperative mood, followed by double-spaced bullet points covering motivation,
  architectural capabilities, concrete classes/commands/tests with execution metrics, and documentation/guide updates.
  Trigger whenever drafting, writing, proposing, or generating git commit messages.
---

# Write Commit Message Skill

Standardized workflow and template for generating high-quality Conventional Commit messages across this repository.

## Commit Message Structure

Every commit message MUST follow this exact format:

```text
<type>(<scope>): <concise description in imperative mood>

- <Bullet 1: Core capability / bugfix overview explaining the primary motivation>

- <Bullet 2: Architectural support, drivers, queue jobs, storage, logging, options, or config details>

- <Bullet 3: Concrete components created/modified (commands, jobs, services, models) and test suite metrics with assertions>

- <Bullet 4: Documentation, guides, setup instructions, config examples, or production best practices updated>
```

> [!IMPORTANT]
> - **Header**: Max 72 characters, lowercase type and scope, imperative mood ("add", "fix", "refactor" — not "added", "fixes"), no trailing period.
> - **Spacing**: Always separate bullet points in the body with a blank line for optimal readability.
> - **Punctuation**: Every bullet point must be a full, well-structured sentence ending with a period.
> - **No Changelog Reference**: Only output the header and descriptive body bullets.

---

## Allowed Types & Common Scopes

### Commit Types (`<type>`)

| Type | When to Use | Example |
| :--- | :--- | :--- |
| `feat` | New feature, endpoint, CLI command, or domain capability | `feat(console): add automated tenant database backups` |
| `fix` | Bug fix in controllers, services, queries, or utilities | `fix(ussd): handle blacklisted user state transitions` |
| `refactor` | Code restructuring without altering external behavior | `refactor(service): extract database connection dumpers` |
| `test` | Adding or updating PHPUnit / Feature test suites | `test(console): add test suite for backup pruning command` |
| `security` | Security hardening, CSRF, rate-limiting, SQL sanitization | `security(csrf): validate tokens on ajax endpoints` |
| `perf` | Performance optimizations, WebP assets, font self-hosting | `perf(assets): self-host google fonts and convert webp images` |
| `docs` | Documentation, GUIDE.md, README.md, or inline docs | `docs(guide): add supervisor worker configuration instructions` |
| `chore` | Tooling, composer/npm dependencies, formatting (Pint) | `chore(style): apply psr-12 formatting across domain services` |

### Common Scopes (`<scope>`)

- `(console)` - Artisan console commands, scheduling, progress bars, CLI signals
- `(service)` - Domain services, business logic, backup services, status services
- `(controller)` - HTTP controllers, request handling, response formatting
- `(chat)` / `(ussd)` - Chat domain, USSD state machine, search/registration flows
- `(job)` / `(queue)` - Background jobs, queue workers, job handlers
- `(otp)` - OTP request, verification, rate limiting, and telco delivery
- `(capi)` - Facebook Conversions API events and tracking
- `(carrier)` / `(telco)` - Telco drivers (Ideamart, mSpace, bdapps) and SMS delivery
- `(models)` - Eloquent models, scopes, transitions, and casts
- `(assets)` - Stylesheets, fonts, WebP images, and client-side scripts
- `(tests)` - Test infrastructure, mocks, assertions, and test helpers

---

## Bullet Guidelines

When composing the body bullets:

1. **Primary Motivation & Feature Overview**:
   State what core feature, fix, or capability was implemented and why.
2. **Architecture & Technical Details**:
   Describe drivers, storage mechanisms, queue dispatching, logging channels, or CLI options supported.
3. **Components & Test Verification Metrics**:
   Enumerate concrete files/classes created or modified, alongside PHPUnit test suite execution metrics:
   `Create <Command>, <Job>, <Service>, and <Test> feature test suite (X passed, Y assertions).`
4. **Documentation & Best Practices**:
   Mention updates to guides, setup instructions, configuration keys, or environment samples where applicable.

---

## Reference Examples

### Example 1: New Feature / Command
```text
feat(console): add automated tenant database backups and update supervisor guide

- Add automated database backup system dumping central and tenant databases into compressed .sql.gz archives.

- Support background queue job execution, Cloudflare R2 / AWS S3 and local storage drivers, structured JSON logging, and daily 30-day retention pruning.

- Create tenant:backup and tenant:prune-backups artisan commands, BackupTenantDatabaseJob queue job, DatabaseBackupService domain service, and TenantBackupTest feature test suite (4 passed, 19 assertions).

- Update GUIDE.md with CLI backup commands, Cloudflare R2 setup instructions, storage location details, and Supervisor queue worker production best practices (user=www-data, explicit working directory, polling flags, and explicit PHP path).
```

### Example 2: Bug Fix & State Handling
```text
fix(ussd): handle blacklisted user state transitions and error feedback

- Intercept blacklisted and temporary blocked subscriber statuses during USSD registration and MO-init session handshakes.

- Record status transitions via UserStatusService with SOURCE_USSD and return localized user-friendly SMS error responses across 5 locales.

- Update UssdStateMachine, UssdRegisterFlowService, and add test cases in ChatUssdWebhookTest (4 tests added, 18 assertions).

- Add localized translation keys to lang/{locale}/ussd.php for Sinhala, Bangla, and English variants.
```

### Example 3: Performance Optimization
```text
perf(assets): self-host google fonts and convert images to webp

- Self-host Montserrat and Noto Sans Sinhala WOFF2 font binaries to eliminate third-party DNS lookups and render-blocking cascades.

- Convert all PNG and JPEG hero images to WebP format, achieving up to 96.3% payload reduction.

- Update _layout_header.php with high-priority image preloading, progressive picture tag, and font-size-adjust in blog.css for CLS prevention.

- Verify all 189 unit tests pass cleanly with 845 assertions.
```

---

## Pre-Commit Verification Checklist

Before finalizing any commit message:
- [ ] Run `git status` to ensure only intended files are staged (no `.env`, logs, or temporary database files).
- [ ] Ensure test suite passes: `php artisan test` (Laravel) or `composer test:unit` (PHPUnit).
- [ ] Check header length ($\le 72$ chars) and present-tense imperative verb ("add", not "added").
- [ ] Verify each bullet is separated by an empty line and ends with a period.
- [ ] Ensure relevant test assertion counts and concrete components are included.
