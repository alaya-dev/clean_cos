---
description: "Executable remediation tasks for Phase 9.5"
---

# Tasks: Phase 9.5 — Baseline Remediation

**Input**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/remediation-api.md`, and `quickstart.md` in `specs/001-remediate-baseline/`.

**Branch**: Work only on `001-remediate-baseline`; do not share it with another specification or implement on `main`.

**Required test environment**: Use local MySQL/MariaDB and Redis for integration, locking, queue, session, cache, and concurrency behavior. Never substitute SQLite, array, file, or database queue fallbacks.

**Low-context agent rule**: Before each task, read the named design artifact and existing target file. Make only the requested change, preserve existing passing behavior, and mark the task complete only after its named tests pass. Never log PII/secrets, weaken CI, alter Meta history, or add Phase 10+ scope.

## Phase 1: Setup and Guardrails

**Purpose**: Prepare shared test helpers and acceptance evidence before behavior changes.

- [X] T001 Verify branch isolation in `.git/HEAD`, read all files under `specs/001-remediate-baseline/`, and record any source conflict in `docs/baseline-audit-phase-0-9.md` before editing application files.
- [X] T002 Create synthetic Phase 9.5 data builders for orders, checkout fields, users, variants, and images in `tests/Support/Phase95Factory.php`.
- [X] T003 Create reusable common-envelope and PII/secret-absence assertions in `tests/Support/AssertsApiEnvelope.php`.
- [X] T004 Add a Phase 9.5 acceptance-evidence section mapping B1, B3–B5, I1–I8, M1, M2, and verified B2 in `docs/baseline-audit-phase-0-9.md` without rewriting historical audit findings.

---

## Phase 2: Foundational API and Audit Boundaries

**Purpose**: Create shared response and audit primitives required by all stories.

**CRITICAL**: Do not start a user story until this phase passes.

- [X] T005 [P] Create failing common API-envelope coverage for validation, 401, 403, 404, 409, 429, and safe 500 responses in `tests/Feature/Api/ApiErrorEnvelopeTest.php`.
- [X] T006 [P] Create failing append-only audit actor, resource, redaction, immutability, and authorization coverage in `tests/Feature/Audit/AuditLogTest.php`.
- [X] T007 Create the append-only audit schema, public identifier, indexes, and foreign keys in `database/migrations/2026_07_18_000800_create_audit_logs_table.php`; do not create a destructive rollback for populated audit data.
- [X] T008 Create guarded audit persistence and immutable model behavior in `app/Domain/Audit/Models/AuditLog.php`.
- [X] T009 Create safe value sanitization and append-only recording in `app/Domain/Audit/Actions/RecordAuditEventAction.php`; exclude passwords, hashes, sessions, tokens, phone, address, raw attribution, and request bodies.
- [X] T010 Create standard success/error builders and French stable codes in `app/Http/Responses/ApiResponse.php` and `app/Http/Responses/ApiErrorCode.php`.
- [X] T011 Update API exception rendering in `bootstrap/app.php` to use the common envelope, retain safe request IDs, and hide internal details.
- [X] T012 Make `tests/Feature/Api/ApiErrorEnvelopeTest.php` and `tests/Feature/Audit/AuditLogTest.php` pass on MySQL before continuing.

**Checkpoint**: Every later API can use one safe envelope, and successful protected mutations can write a redacted audit event.

---

## Phase 3: User Story 1 — Receive an Accurate, Safe Order (Priority: P1)

**Goal**: Quote, checkout, and reconciliation share one authoritative shipping rule; dynamic checkout fields, safe responses, and idempotency retention meet the approved contract.

**Independent Test**: For baskets below, at, and above free shipping, quote and submitted order totals match; invalid active-field submissions create nothing; retries behave safely throughout retention.

### Tests for User Story 1

- [X] T013 [P] [US1] Add below/at/above threshold and integer-millime tests for one shipping rule in `tests/Unit/Checkout/ShippingCalculatorTest.php`.
- [X] T014 [P] [US1] Add quote-versus-checkout total equality tests in `tests/Feature/Commerce/CartQuoteTest.php`.
- [X] T015 [P] [US1] Add active required, unknown, inactive, invalid-option, and normalized checkout-field tests in `tests/Feature/Commerce/GuestOrderTest.php`.
- [X] T016 [P] [US1] Add public order response, French envelope, safe-attribution, identical-replay, and changed-payload conflict tests in `tests/Feature/Commerce/GuestOrderContractTest.php`.
- [X] T017 [P] [US1] Add MySQL retention, cleanup, before-expiry replay, after-expiry behavior, and no-duplicate-stock tests in `tests/Integration/Commerce/CheckoutIdempotencyRetentionTest.php`.
- [X] T018 [P] [US1] Add order-item reconciliation shipping and optimistic-lock tests in `tests/Feature/Commerce/ReconcileOrderItemsTest.php`.

### Implementation for User Story 1

- [X] T019 [US1] Create the server-only shipping rule value object in `app/Domain/Checkout/Services/ShippingCalculator.php` using configured threshold/fixed-fee millimes and no browser input.
- [X] T020 [US1] Refactor `app/Domain/Commerce/Services/CartQuoteService.php` to use `ShippingCalculator` while preserving current quote item behavior.
- [X] T021 [US1] Refactor `app/Domain/Commerce/Actions/CreateGuestOrderAction.php` to use `ShippingCalculator` inside its existing transaction and persist derived snapshots only.
- [X] T022 [US1] Refactor `app/Domain/Commerce/Actions/ReconcileOrderItemsAction.php` to use `ShippingCalculator` without weakening stock locks or optimistic locking.
- [X] T023 [US1] Create active-schema lookup, allow-list validation, normalization, and order-value snapshot preparation in `app/Domain/Checkout/Actions/ResolveCheckoutSubmissionAction.php`.
- [X] T024 [US1] Create `app/Http/Requests/Api/CreateGuestOrderRequest.php` and update `app/Http/Controllers/Api/GuestOrderController.php` to reject unknown/missing active fields before any stock/order mutation.
- [X] T025 [US1] Create `app/Http/Resources/PublicOrderResource.php` and update `app/Http/Controllers/Api/GuestOrderController.php` to return documented safe order, checkout snapshot, and pricing fields through `ApiResponse`.
- [X] T026 [US1] Create a forward-safe retention-managed replay table in `database/migrations/2026_07_18_000810_create_checkout_idempotency_records_table.php`; retain historical orders and do not delete them in rollback.
- [X] T027 [US1] Create `app/Domain/Checkout/Models/CheckoutIdempotencyRecord.php` and `app/Domain/Checkout/Actions/PruneExpiredCheckoutIdempotencyRecordsAction.php` for unique active replay records and safe expiry cleanup.
- [X] T028 [US1] Register cleanup in `routes/console.php`, migrate `app/Domain/Commerce/Actions/CreateGuestOrderAction.php` to transactional record lookup/create, and make T013–T018 pass.

**Checkpoint**: US1 independently removes shipping and checkout-contract blockers without introducing Phase 10 configuration screens.

---

## Phase 4: User Story 2 — Operate Orders with Accountable Access (Priority: P1)

**Goal**: Super Admin controls are backend-enforced, audit records are safe and immutable, and every order lifecycle/stock-restoration path is concurrency-safe.

**Independent Test**: Super Admin administration succeeds, Admin direct access fails, the final Super Admin is protected, all transitions are covered, and concurrent restoration creates exactly one stock restoration.

### Tests for User Story 2

- [X] T029 [P] [US2] Add current-user, forced-password-change, change/reset revocation, and generic-login-error tests in `tests/Feature/Identity/BackOfficePasswordLifecycleTest.php`.
- [X] T030 [P] [US2] Add Super-Admin user CRUD, Admin denial, inactive-user denial, self-lockout, and final-Super-Admin tests in `tests/Feature/Identity/UserManagementAuthorizationTest.php`.
- [X] T031 [P] [US2] Add redacted Super-Admin audit list/detail and audited user/order/catalog/inventory mutation tests in `tests/Feature/Audit/AuditLogApiTest.php`.
- [X] T032 [P] [US2] Extend valid/invalid transition, terminal-edit, reason, return-restock, and stale-lock tests in `tests/Feature/Commerce/OrderTransitionTest.php`.
- [X] T033 [P] [US2] Add duplicate, bulk, and concurrent-safe restoration tests in `tests/Integration/Commerce/InventoryRestorationConcurrencyTest.php`.
- [X] T034 [P] [US2] Add direct MySQL rejection tests for status, stock, promotion, stock-mode, idempotency, and restoration invariants in `tests/Integration/Database/CommerceInvariantTest.php`.
- [X] T035 [P] [US2] Add Admin/Super-Admin navigation, forbidden-state, and audit-view component tests in `resources/js/admin/identity-and-audit.test.ts`.

### Implementation for User Story 2

- [X] T036 [US2] Create password-state and session-revocation migration in `database/migrations/2026_07_18_000820_add_password_lifecycle_to_users_table.php` with a documented forward-fix path.
- [X] T037 [US2] Update password lifecycle casts/guards in `app/Models/User.php` and safe defaults in `database/factories/UserFactory.php`.
- [X] T038 [US2] Create user lifecycle actions for current user, password change/reset, session revocation, Super-Admin user administration, and final-admin protection in `app/Domain/IdentityAccess/Actions/`.
- [X] T039 [US2] Create `app/Policies/BackOfficeUserPolicy.php` and `app/Policies/AuditLogPolicy.php`, then register them in `app/Providers/AppServiceProvider.php` with deny-by-default rules.
- [X] T040 [US2] Create current-user/password controllers and form requests in `app/Http/Controllers/Api/Admin/CurrentUserController.php`, `app/Http/Controllers/Api/Admin/PasswordController.php`, and `app/Http/Requests/Api/Admin/`.
- [X] T041 [US2] Create Super-Admin user/audit controllers and redacted resources in `app/Http/Controllers/Api/Admin/UserController.php`, `app/Http/Controllers/Api/Admin/AuditLogController.php`, and `app/Http/Resources/`.
- [X] T042 [US2] Split protected routes by their actual policies in `routes/api.php` so users/audit are not accidentally authorized by `catalog.manage`.
- [X] T043 [US2] Create one-time restoration schema with unique order/reason scope and movement relation in `database/migrations/2026_07_18_000830_create_inventory_restoration_markers_table.php`.
- [X] T044 [US2] Create `app/Domain/Orders/Models/InventoryRestorationMarker.php` and `app/Domain/Orders/Actions/RestoreOrderStockOnceAction.php` using locks and durable uniqueness.
- [X] T045 [US2] Refactor `app/Domain/Commerce/Actions/TransitionOrderStatusAction.php` to use `RestoreOrderStockOnceAction`, enforce the complete transition graph, and record sanitized audit events.
- [X] T046 [US2] Refactor protected order edits, items, notes, bulk archive/restore/transition, and export paths in `app/Http/Controllers/Api/Admin/OrderController.php` to authorize and audit only successful mutations.
- [X] T047 [US2] Add sanitized audit recording to `app/Domain/Catalog/Actions/AdjustInventoryAction.php`, `app/Http/Controllers/Api/Admin/ProductController.php`, and `app/Http/Controllers/Api/Admin/CategoryController.php` without request-body logging.
- [X] T048 [US2] Add MySQL/MariaDB-compatible integrity migrations for valid status, non-negative stock, promotion ordering, and stock-mode invariants in `database/migrations/2026_07_18_000840_add_commerce_integrity_constraints.php`.
- [X] T049 [US2] Create French role-safe user and audit modules in `resources/js/admin/users.ts` and `resources/js/admin/audit-logs.ts` without displaying secrets or unredacted audit values.
- [X] T050 [US2] Integrate those modules and shared accessible controls into `resources/js/admin/main.ts` and `resources/js/admin/admin-modules.test.ts` with reduced-motion/no-overflow behavior.
- [X] T051 [US2] Make T029–T035 pass on MySQL/Redis, starting from `tests/Feature/Identity/UserManagementAuthorizationTest.php`, without regressing existing order, inventory, or catalogue coverage.

**Checkpoint**: US2 independently satisfies the identity, audit, order-transition, and durable-integrity gates.

---

## Phase 5: User Story 3 — Keep the Operating Baseline Resilient (Priority: P2)

**Goal**: Redis-only operational behavior, upload hardening, coverage/browser checks, and asset budgets become executable release controls.

**Independent Test**: Redis failure produces safe readiness behavior, abusive uploads stay isolated, critical flows run in a browser, and coverage/budget commands enforce agreed gates.

### Tests for User Story 3

- [X] T052 [P] [US3] Add Redis driver, limiter-store, queue/session/cache, and Redis-unavailable readiness tests in `tests/Feature/Foundation/RedisOperationalConfigurationTest.php`.
- [X] T053 [P] [US3] Add upload rate-limit, `Retry-After`, 20-MP boundary, malformed file, private staging, and terminal-failure tests in `tests/Feature/Catalog/CatalogSearchAndMediaTest.php`.
- [X] T054 [P] [US3] Add image-job timeout, retry, backoff, queue, and no-public-original tests in `tests/Integration/Catalog/ProductImageProcessingTest.php`.
- [X] T055 [P] [US3] Add French login, checkout retry, image upload, inventory, transition, keyboard, and reduced-motion browser tests in `tests/Browser/phase95-critical-flows.spec.ts`.
- [X] T056 [P] [US3] Add select/dialog/focus/error-state/mobile-overflow component tests in `resources/js/admin/admin-modules.test.ts`.
- [X] T057 [P] [US3] Add backend/frontend coverage configuration tests in `tests/Feature/Quality/CoverageConfigurationTest.php` and `resources/js/admin/coverage-config.test.ts`.
- [X] T058 [P] [US3] Add a public asset-budget report assertion using a failing fixture in `tests/Feature/Quality/AssetBudgetTest.php`.

### Implementation for User Story 3

- [X] T059 [US3] Set Redis as explicit cache, queue, session, and limiter default in `config/cache.php`, `config/queue.php`, `config/session.php`, and `app/Providers/AppServiceProvider.php` without adding fallback stores.
- [X] T060 [US3] Update `app/Http/Controllers/HealthController.php` and `tests/Feature/FoundationHealthTest.php` so required Redis failure returns a minimal safe readiness failure without credentials or stack traces.
- [X] T061 [US3] Add authorized per-user upload throttling and media queue routing in `routes/api.php` and `app/Providers/AppServiceProvider.php`.
- [X] T062 [US3] Tighten validation, enforce the approved pixel ceiling, and keep staging/original paths private in `app/Http/Controllers/Api/Admin/ProductImageController.php`.
- [X] T063 [US3] Add bounded attempts, timeout, backoff, queue selection, concurrency policy, and safe permanent-failure reporting in `app/Jobs/ProcessProductImage.php`.
- [X] T064 [US3] Add executable backend coverage configuration and approved thresholds in `phpunit.xml` and `composer.json` without excluding critical domain code.
- [X] T065 [US3] Add executable frontend coverage thresholds in `vitest.config.ts` and `package.json` without excluding changed admin code.
- [X] T066 [US3] Add Playwright configuration and local critical-flow command in `playwright.config.ts` and `package.json` using only synthetic data.
- [X] T067 [US3] Create public asset measurement and explicit-exception reporting in `scripts/check-asset-budgets.mjs` and add its command in `package.json`.
- [X] T068 [US3] Update `.github/workflows/ci.yml` to run required quality, coverage, browser, budget, audit, and secret-scan gates without weakening existing checks.
- [X] T069 [US3] Verify existing Sentry redaction and monitoring-failure safety in `tests/Unit/SentryEventSanitizerTest.php`, `resources/js/admin/sentry-sanitizer.test.ts`, and `tests/Feature/Commerce/GuestOrderTest.php` without reimplementing B2.
- [ ] T070 [US3] Make T052–T058 and all new coverage/browser/budget commands pass, then record exact results in `docs/baseline-audit-phase-0-9.md`.

**Checkpoint**: US3 independently makes operations and release evidence resilient and executable.

---

## Phase 6: User Story 4 — Preserve a Trustworthy Documentation and Migration Baseline (Priority: P3)

**Goal**: Required documentation references resolve and populated historical data has a tested, non-destructive migration/forward-fix path.

**Independent Test**: The reference checker finds no broken approved source; a populated archived order passes upgrade/forward-fix rehearsal without silent data loss.

### Tests for User Story 4

- [X] T071 [P] [US4] Add required-document, retired-name, and reading-order resolution tests in `tests/Feature/Documentation/ReferenceIntegrityTest.php`.
- [X] T072 [P] [US4] Add populated archived-order upgrade and forward-fix rehearsal tests in `tests/Integration/Database/ArchivedOrderMigrationSafetyTest.php`.

### Implementation for User Story 4

- [X] T073 [US4] Create an offline Markdown reference checker that reports source and unresolved target in `scripts/check-doc-references.ps1`.
- [X] T074 [US4] Add the reference-check command to `composer.json` and `.github/workflows/ci.yml` without network access.
- [X] T075 [US4] Replace stale `security-rules.md` references with authoritative `security.md` in `docs/api-contracts.md`, `docs/quality-rules.md`, `docs/design.md`, and `docs/implementation-plan.md` without duplicating policy.
- [X] T076 [US4] Add declared source reading order and Phase 9.5 audit-resolution note to `docs/implementation-plan.md` and `docs/baseline-audit-phase-0-9.md` without changing approved business rules.
- [X] T077 [US4] Document the destructive archived-order rollback and its tested forward-fix/recovery procedure in `database/migrations/2026_07_18_000700_add_archived_at_to_orders.php` and `docs/quality-rules.md`.
- [X] T078 [US4] Make T071–T072 and the checker pass, then attach populated-data rehearsal evidence to `docs/baseline-audit-phase-0-9.md`.

**Checkpoint**: US4 independently makes source authority and historical-data evolution enforceable.

---

## Phase 7: Final Verification and Release Evidence

**Purpose**: Reconcile findings, collect command evidence, and complete human review before Phase 10.

- [X] T079 Reconcile B1, B3–B5, I1–I8, M1, and M2 against implementation evidence in `docs/baseline-audit-phase-0-9.md`; record B2 only as verified and leave M3/C1–C4 unchanged.
- [X] T080 Run backend formatting, static analysis, feature/integration/concurrency, migration, and coverage checks; record exact commands/results in `specs/001-remediate-baseline/quickstart.md`.
- [ ] T081 Run frontend lint, typecheck, component coverage, browser flows, build, and asset-budget checks; record exact commands/results in `specs/001-remediate-baseline/quickstart.md`.
- [X] T082 Run Composer audit, npm high-severity audit, secret scan, reference checker, and hygiene scan; record exact commands/results in `docs/baseline-audit-phase-0-9.md`.
- [X] T083 Manually inspect changed French UI for keyboard, focus-visible, reduced-motion, mobile overflow, and private-data leakage; record evidence in `docs/baseline-audit-phase-0-9.md`.
- [X] T084 Review each migration for MySQL/MariaDB compatibility, populated-data preservation, rollback/forward-fix notes, and no historical order/audit/Meta rewrite in `specs/001-remediate-baseline/data-model.md`.
- [ ] T085 Perform human diff review for scope, authorization, redaction, transactions, idempotency, concurrency, performance, and contract fidelity; record approval/blockers in `docs/baseline-audit-phase-0-9.md`.
- [ ] T086 Run `git diff --check` and reconcile the final diff with `specs/001-remediate-baseline/spec.md`, `plan.md`, `contracts/remediation-api.md`, and `tasks.md` before requesting Phase 10.

## Dependencies and Execution Order

```text
Phase 1 Setup → Phase 2 Foundation
Phase 2 → US1, US2, and US3 (parallel after shared foundation)
US1 + US2 + US3 → US4 (migration and CI paths stable)
US1 + US2 + US3 + US4 → Final verification
```

- US1 is the recommended MVP: complete T001–T028, prove its independent test, then stop for review.
- US2 requires the audit foundation but is otherwise independent of US1.
- US3 requires the common envelope foundation but is otherwise independent of US1 and US2.
- US4 waits for the final migration and CI paths created by earlier stories.
- Final verification waits for every prior phase.

## Parallel Execution Examples

```text
US1 tests: T013, T014, T015, T016, T017, T018
US2 tests: T029, T030, T031, T032, T033, T034, T035
US3 tests: T052, T053, T054, T055, T056, T057, T058
US4 tests: T071, T072
```

## Completion Criteria

- All 86 tasks are checked only after their named test or evidence passes.
- No unresolved B1, B3–B5, I1–I8, M1, or M2 finding remains.
- B2 remains verified without re-scoping its implementation.
- No Phase 10 feature, customer account, online payment, WebSocket, GraphQL, or Meta trigger-history change is introduced.
- Human diff review and all required release evidence are recorded before Phase 10 proceeds.
