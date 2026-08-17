---
trigger: always_on
description: Workflow, testing, lifecycle, and Antigravity pair programming rules for the OTP Chat Refactor project.
---

# Development & Agent Workflow Rules

## 1. Feature & Bugfix Development Lifecycle

When implementing features, fixing bugs, or refactoring in this repository, follow this systematic order:

1. **Understand & Research**:
   - Inspect existing implementation and relationships using Graphify (`query_graph` or `graphify query`) before making architectural assumptions.
   - Use Context7 (`find-docs` / `ctx7`) when interacting with external libraries (e.g., Symfony HttpFoundation, Monolog 3, Guzzle 7, Facebook Business SDK, PHPUnit 11, Codeception 5).
2. **Implement Core Logic in `src/`**:
   - For business logic, add or update classes in `src/Service/` or `src/Utils/`.
   - For request/response handling, add or update controllers in `src/Controller/` (extending `BaseController`).
3. **Register Services & Controllers in Container**:
   - Update `bootstrap/app.php` to register any new service or controller with its constructor dependencies in the Pimple DI container.
4. **Wire Routes & Views**:
   - Register new HTTP endpoints in `routes/web.php`.
   - Add/update view templates in `templates/` (ensuring layout wrapping and XSS escaping).
5. **Write Unit Tests**:
   - Add corresponding test cases in `tests/` for all new/modified services, controllers, and utility methods.
6. **Execute Verification**:
   - Run the unit test suite: `composer test:unit`.
   - Verify code coverage: `composer test:coverage` (ensure line coverage remains >= 95%).
7. **Synchronize Knowledge Graph**:
   - Run `graphify update .` after modifying any code files.

## 2. Testing Commands & Verification Protocols

- **Unit Testing**:
  - Run all unit tests: `composer test:unit`
  - Run specific test file: `vendor/bin/phpunit tests/ValidatorTest.php --testdox`
  - Generate code coverage report: `composer test:coverage` (HTML report generated in `coverage-report/`)
- **Automated Acceptance Script (Browser / E2E)**:
  - Run `composer test:ac` (runs `php scripts/run-acceptance.php` which orchestrates server and tests).

## 3. Local Development Servers

- **Frontend Dev Server**: `composer dev:frontend` (starts `php -S localhost:8080 -t public`)
- **Backend Mock API Server**: `composer dev:mockapi` (starts `php -S localhost:8081 ./public/backend-test-api/index.php`)

## 4. Knowledge Graph (Graphify) Maintenance

- **Querying**: Use `graphify query "<question>"` or MCP `query_graph` to understand connections, caller hierarchies, and data flows.
- **Post-Modification Sync**: Whenever any code file (`src/`, `config/`, `bootstrap/`, `routes/`, `tests/`) is created, modified, or deleted, ALWAYS execute `graphify update .` to update the AST graph in `graphify-out/`.

## 5. Documentation & Code Integrity

- Preserve all existing comments, docstrings, and architectural explanations unrelated to your code changes.
- Ensure all new public methods, classes, and interfaces have standard PHPDoc annotations specifying argument types, return types, and exceptions.
- Never commit environment files (`.env`), database logs (`logs/*.sqlite`), application logs (`logs/app/*.log`), or coverage reports (`coverage-report/`).

## 6. Conventional Commits & Git Standards

All commit messages must strictly adhere to the **Conventional Commits** specification with detailed context:

- **Format**:
  ```text
  <type>(<optional scope>): <concise description in imperative mood>

  [optional detailed body explaining why and what changed]

  [optional footer(s) e.g., BREAKING CHANGE, Closes #issue]
  ```

- **Commit Types**:
  - `feat`: New feature or user-facing capability (e.g., new carrier support, new endpoint).
  - `fix`: Bug fix in controllers, services, or utilities.
  - `refactor`: Code restructuring without modifying behavior (e.g., decoupling services, cleaning controllers).
  - `test`: Adding or updating PHPUnit or Codeception tests.
  - `security`: Security patches, CSRF hardening, rate-limit enhancements, SQL sanitization.
  - `perf`: Performance optimizations (e.g., query tuning, connection timeout adjustments).
  - `docs`: Documentation updates (e.g., README, API docs, inline architecture docs).
  - `chore`: Tooling, composer dependency updates, build script or config adjustments.

- **Scopes** (use relevant project modules):
  - `(controller)`, `(service)`, `(otp)`, `(capi)`, `(carrier)`, `(validator)`, `(logger)`, `(rate-limit)`, `(csrf)`, `(session)`, `(routes)`, `(tests)`, `(deps)`

- **Commit Message Quality Guidelines**:
  - **Header**: Max 72 characters, imperative present tense ("add", "fix", "refactor" — not "added", "fixes"), no trailing period.
  - **Body (Detailed)**: For non-trivial changes, provide a clear paragraph explaining:
    1. *Motivation*: Why this change was necessary.
    2. *Solution*: What was altered and key design decisions made.
    3. *Impact*: Any behavioral changes, fallback handling, or dependency implications.
  - **Atomic Commits**: Keep commits focused on a single logical change accompanied by its corresponding unit tests.
  - **Clean Staging**: Always verify `git status` before committing to avoid staging `.env`, `logs/*.sqlite`, `logs/app/*.log`, or `coverage-report/`.