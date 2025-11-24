# Version History

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
