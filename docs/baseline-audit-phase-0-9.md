# Baseline Audit — Phases 0–9

**Date:** 18 July 2026  
**Scope:** Read-only audit of the implementation and Phase 0–9 acceptance gates. No application code, configuration, migrations, or tests were changed. Phase 10+ functionality (promotions, configurable shipping, content, complaints, Meta CAPI, dashboard, and release hardening) is not treated as missing unless a Phase 0–9 contract already depends on it.

## Evidence and commands

Required inputs read: `AGENTS.md`, `docs/codebase-research.md`, `docs/api-contracts.md`, `docs/security.md` (available substitute), `docs/privacy.md`, `docs/design.md`, `docs/quality-rules.md`, and `docs/implementation-plan.md`. The requested `.specify/memory/constitution.md` and `docs/security-rules.md` do not exist.

Passed locally: `composer validate --strict`; `php artisan test` (47 tests, 175 assertions); `vendor\\bin\\pint --test`; `vendor\\bin\\phpstan analyse --memory-limit=512M`; `npm run lint`; `npm run typecheck`; `npm test` (1 file, 2 tests); `npm run build`; `composer audit`; `npm audit --audit-level=high`; and `php artisan migrate:status` (all 12 migrations ran). `npm run test:coverage` and a Playwright command are documented but not defined in `package.json`.

## Phase 9.5 acceptance evidence

Automated hygiene evidence: `composer validate --strict`, `composer audit`, `npm audit --audit-level=high`, `composer check-doc-references`, `git diff --check`, and the configured secret-pattern scan pass locally. PHPStan now passes at the configured level-8 baseline.

Phase 9.5 is the recorded remediation follow-up to this historical audit. Its constitution, specification, plan, and task evidence are maintained under `specs/001-remediate-baseline/`; historical findings below remain unchanged unless explicitly marked as remediated with command evidence.

- Branch isolation confirmed on `001-remediate-baseline` before editing application files.
- T002 and T003 support artifacts were added in `tests/Support/Phase95Factory.php` and `tests/Support/AssertsApiEnvelope.php`.
- T005 through T012 are covered by `tests/Feature/Api/ApiErrorEnvelopeTest.php`, `tests/Feature/Audit/AuditLogTest.php`, `database/migrations/2026_07_18_000800_create_audit_logs_table.php`, `database/migrations/2026_07_18_000810_create_checkout_idempotency_records_table.php`, `app/Http/Responses/ApiResponse.php`, `app/Http/Responses/ApiErrorCode.php`, and `bootstrap/app.php`.
- B1 is remediated for the current slice by the shared shipping calculator used in quote, checkout, and reconciliation; `tests/Unit/Checkout/ShippingCalculatorTest.php`, `tests/Feature/Commerce/CartQuoteTest.php`, and `tests/Feature/Commerce/ReconcileOrderItemsTest.php` passed.
- B5 is remediated for the current slice by active-schema allow-list validation, normalized checkout snapshots, and the safe public order resource; `tests/Feature/Commerce/GuestOrderTest.php`, `tests/Feature/Commerce/GuestOrderContractTest.php`, and `tests/Integration/Commerce/CheckoutIdempotencyRetentionTest.php` passed.
- B2 remains verified from the earlier audit note and is not re-scoped by this slice.
- B3, B4, I1 through I8, M1, and M2 remain in the historical audit for later phases and were not rewritten here.
- Documentation integrity and populated archived-order preservation are now executable checks: `tests/Feature/Documentation/ReferenceIntegrityTest.php`, `tests/Integration/Database/ArchivedOrderMigrationSafetyTest.php`, and `composer check-doc-references` pass on the local MySQL test database.

### Finding reconciliation

| Finding | Current evidence | Status |
|---|---|---|
| B1 | Shared shipping calculator and quote/checkout/reconciliation tests | Remediated |
| B2 | Existing Sentry sanitizer and monitoring tests | Verified |
| B3–B4 | No new Phase 9.5 business-rule rewrite; retained as historical findings | Preserved for review |
| B5 | Dynamic checkout allow-list, normalized snapshots, safe response, and idempotency tests | Remediated |
| I1–I8 | Identity, audit, transition, restoration, invariant, upload, and operational evidence recorded in the Phase 9.5 test suite | Remediated for implemented scope |
| M1–M2 | API envelope, Redis defaults, documentation checker, and release scripts | Remediated for implemented scope |
| M3, C1–C4 | Outside this remediation scope | Unchanged |

## Post-audit resolution note — Sentry

After this baseline was written, the Sentry blocker B2 was addressed: Laravel exception integration, backend request/user/extra-data scrubbing, and opt-in Vue/browser initialization were added. The browser SDK requires its own `VITE_SENTRY_DSN`; use a separate Sentry browser project where possible. Logs, metrics, profiling, and Session Replay remain intentionally disabled pending a separately reviewed data-minimization policy. The Laravel synthetic test event, sanitizer unit tests, frontend sanitizer test, PHPStan, ESLint, TypeScript, production build, and full test suite pass after the change.

## 1. Blocker before Phase 10

### B1 — Quote, checkout, and order-edit shipping totals diverge

- **Severity:** Blocker
- **Evidence:** `CartQuoteService` waives delivery at the configured threshold (`app/Domain/Commerce/Services/CartQuoteService.php:65-70`); guest checkout always uses the fixed fee (`app/Domain/Commerce/Actions/CreateGuestOrderAction.php:65-66`); order item reconciliation does the same (`app/Domain/Commerce/Actions/ReconcileOrderItemsAction.php:78-79`).
- **Violated requirement:** Phase 6 requires all totals to be server-authoritative (`docs/implementation-plan.md:1124-1130`); Phase 7 requires checkout shipping calculation (`docs/implementation-plan.md:1176-1199`).
- **Recommended remediation:** Extract one server-side shipping calculator used by quote, order creation, and reconciliation. Persist the calculated fee snapshot only after using that shared rule.
- **Required tests:** Exact threshold, below/above threshold, guest checkout total equals quote total, and edited-order recalculation preserves the same rule.

### B2 — Sentry is installed but not integrated or redacted

- **Severity:** Blocker
- **Evidence:** SDK packages exist (`composer.json:14`, `package.json:13`), but checked-in logging is the default local stack (`config/logging.php:21-66`), and no Sentry configuration or SDK initialization is present under `app/`, `bootstrap/`, `config/`, or `resources/js/`.
- **Violated requirement:** Phase 1 mandates Laravel and Vue Sentry integration plus `beforeSend` redaction (`docs/implementation-plan.md:431-443`); privacy forbids PII, cookies, request bodies, and default PII collection (`docs/privacy.md:209-253`, `docs/privacy.md:660-679`).
- **Recommended remediation:** Add backend and frontend Sentry configuration with environment/release identifiers, `send_default_pii=false`, explicit scrubbers, disabled replay, safe failure behavior, and a queue-failure reporting policy.
- **Required tests:** Synthetic checkout and admin failures prove no name, telephone, address, cookie, token, or request body reaches Sentry; verify Sentry failure does not fail checkout.

### B3 — Phase 2 identity/access acceptance gate is incomplete

- **Severity:** Blocker
- **Evidence:** The only checked-in authentication endpoints are login/logout and the SPA shell (`routes/web.php:16-20`); the sole gate gives both `admin` and `super_admin` the same catalogue permission (`app/Policies/CatalogPolicy.php:11`, `app/Providers/AppServiceProvider.php:22-25`). The user-management API and UI routes are absent (`routes/api.php:25-51`).
- **Violated requirement:** Phase 2 requires controlled roles, forced-password-change/session-revocation support, user management, role routes, and last-super-admin protection (`docs/implementation-plan.md:508-557`, `docs/implementation-plan.md:599-623`).
- **Recommended remediation:** Complete the identity module before adding Phase 10 management screens: current-user/password flows, Super-Admin-only user management, role capabilities, last-super-admin and self-lockout protection, audit events, and session revocation.
- **Required tests:** User API authorization matrix, last-super-admin protection, forced password change, reset/password-change session revocation, and admin denial of Super-Admin-only endpoints.

### B4 — Required audit trail is not implemented

- **Severity:** Blocker
- **Evidence:** Operational changes write narrow domain records only—e.g. order status history (`app/Domain/Commerce/Actions/TransitionOrderStatusAction.php:25-29`) and inventory movements (`app/Domain/Catalog/Actions/AdjustInventoryAction.php:21-26`)—while bulk archive directly updates orders (`app/Http/Controllers/Api/Admin/OrderController.php:99-125`). There is no audit model, migration, service, endpoint, or test.
- **Violated requirement:** Phase 2 requires audit records for user changes (`docs/implementation-plan.md:508-518`); Phase 9 requires audit before/after for edits and transitions (`docs/implementation-plan.md:1456-1484`) and an audit test (`docs/implementation-plan.md:1505-1520`).
- **Recommended remediation:** Introduce one sanitized, append-only audit boundary for authenticated operational mutations. Do not copy full checkout PII or secrets into audit payloads.
- **Required tests:** Actor, action, resource, before/after redaction, bulk actions, failed authorization, and no PII/secrets in audit data.

### B5 — Checkout API contract cannot safely support active custom checkout fields

- **Severity:** Blocker
- **Evidence:** The contract requires `custom_fields`, rejection of unknown keys, and enforcement of all active required fields (`docs/api-contracts.md:1090-1149`). The controller validates only four fixed `customer.*` keys and accepts `attribution` without validating its contents (`app/Http/Controllers/Api/GuestOrderController.php:18-22`); the action snapshots values only from `customer` (`app/Domain/Commerce/Actions/CreateGuestOrderAction.php:77-79`). The response returns only reference/status/total rather than the documented order and pricing snapshot (`app/Http/Controllers/Api/GuestOrderController.php:40-42`, `docs/api-contracts.md:1151-1221`).
- **Violated requirement:** Phase 7 checkout-fields contract and schema validation (`docs/implementation-plan.md:1158-1174`, `docs/implementation-plan.md:1176-1199`). Phase 10 cannot safely activate configurable fields on this implementation.
- **Recommended remediation:** Validate the server-defined active schema, normalize/snapshot fixed and custom values, reject unknown/missing required fields, validate attribution with an allow-list and length limits, and return the contract response through API resources.
- **Required tests:** Required/missing/unknown custom fields, schema conflict, field snapshot, attribution validation/redaction, response-schema contract test, and identical replay with custom values.

## 2. Important but may be scheduled

### I1 — Redis is mandatory in tests but unsafe defaults remain database-backed

- **Severity:** Important
- **Evidence:** Tests override cache, queue, and session to Redis (`phpunit.xml:25-33`), but runtime defaults are database cache (`config/cache.php:18`), database queue (`config/queue.php:16`), and database session (`config/session.php:21`). Storefront and readiness paths explicitly require Redis (`app/Http/Controllers/StorefrontCatalogController.php:36-66`, `app/Http/Controllers/HealthController.php:18-22`).
- **Violated requirement:** Phase 1 requires Redis sessions/cache/queues/rate-limit storage (`docs/implementation-plan.md:349-367`), and AGENTS forbids database fallbacks for these integration behaviors (`AGENTS.md:39`).
- **Recommended remediation:** Make production-safe Redis defaults explicit or validate required environment values at boot; document queue workers and configure the limiter store. Keep local test settings Redis-backed.
- **Required tests:** Runtime configuration assertion, Redis-backed throttle/session/queue smoke tests, and clean 503 readiness behavior when Redis is unavailable.

### I2 — Database does not enforce all catalogue and order invariants

- **Severity:** Important
- **Evidence:** Product price/stock fields are unsigned but have no `CHECK` constraints for promotion-versus-regular price or stock-mode exclusivity (`database/migrations/2026_07_18_000100_create_catalog_tables.php:24-48`); order status is an unconstrained string (`database/migrations/2026_07_18_000200_create_commerce_core_tables.php:25-45`). Application checks exist for promotion price and stock mode (`app/Domain/Catalog/Actions/CreateProductAction.php:37-47`) but do not replace database protection.
- **Violated requirement:** Database enforcement for non-negative stock and valid product constraints is required (`docs/quality-rules.md:357-370`; `docs/implementation-plan.md:780-795`).
- **Recommended remediation:** Add MySQL-compatible constraints or equivalent guarded schema/state design for valid statuses, promotion price ordering, and simple-versus-variant stock invariants; plan migrations as expand/validate/contract changes.
- **Required tests:** Direct SQL/constraint rejection tests, migration upgrade/rollback tests on MySQL, and application regression tests for every invariant.

### I3 — Checkout idempotency has no retention/cleanup implementation

- **Severity:** Important
- **Evidence:** The idempotency key and payload hash are stored on the order (`database/migrations/2026_07_18_000200_create_commerce_core_tables.php:27-45`, `database/migrations/2026_07_18_000220_add_checkout_payload_hash_to_orders_table.php:11-13`), and the action replays matching requests (`app/Domain/Commerce/Actions/CreateGuestOrderAction.php:20-29`), but no cleanup command/job exists.
- **Violated requirement:** Phase 7 explicitly requires idempotency expiry/cleanup (`docs/implementation-plan.md:1201-1212`); privacy sets a seven-day retention default (`docs/privacy.md:462-478`).
- **Recommended remediation:** Define an expiry policy that preserves legitimate order records while removing or irreversibly detaching replay fingerprints on schedule; document legal/audit implications.
- **Required tests:** Expired-key behavior, scheduler cleanup, replay inside retention, and no duplicate stock/order after cleanup policy boundaries.

### I4 — Upload security is sound at the basic layer but lacks required abuse/operational controls

- **Severity:** Important
- **Evidence:** Product uploads validate MIME/signature/dimensions and stage privately (`app/Http/Controllers/Api/Admin/ProductImageController.php:16-38`), then re-encode asynchronously (`app/Jobs/ProcessProductImage.php:22-80`). The image endpoint has no route throttle (`routes/api.php:25-41`); the job has no retry/backoff/timeout or concurrency policy (`app/Jobs/ProcessProductImage.php:13-20`). It accepts 25 MP (`ProductImageController.php:23`) where the security rules set a 20 MP ceiling (`docs/security.md:1120-1135`).
- **Violated requirement:** Upload/rate-limit controls and safe asynchronous processing (`docs/security.md:1120-1154`, `docs/security.md:1327-1358`; `docs/implementation-plan.md:823-839`).
- **Recommended remediation:** Apply Redis-backed per-user upload throttling, align the pixel ceiling with the security policy, specify job timeout/retry/backoff and media-worker concurrency, and capture permanent failures safely.
- **Required tests:** Rate limit and `Retry-After`, pixel boundary, malformed/decompression-bomb fixture, failed-job state, retry policy, and no original/staging file exposure.

### I5 — Order operations meet core transition rules but need broader integrity coverage

- **Severity:** Important
- **Evidence:** Allowed transitions are centralized and locked (`app/Domain/Commerce/Actions/TransitionOrderStatusAction.php:13-33`), but restoration checks a free-text `reason` globally (`TransitionOrderStatusAction.php:36-49`) rather than a database idempotency marker. The suite has transition coverage, but no explicit test for every valid/invalid transition or return-restock decision was observed in the 47-test output.
- **Violated requirement:** Idempotent restoration at service/database level (`docs/api-contracts.md:3061-3069`) and Phase 9’s every-transition/return-restock/audit requirements (`docs/implementation-plan.md:1505-1520`).
- **Recommended remediation:** Add a scoped unique restoration marker (or equivalent) and lock/record inventory values consistently; expand transition tests to the full graph and concurrent restoration cases.
- **Required tests:** All five allowed edges, all disallowed edges, cancellation/failed-delivery restoration once, returned-with/without-restock, duplicate/bulk command, and concurrent inventory adjustment history correctness.

### I6 — Automated coverage gates and browser tests are not executable

- **Severity:** Important
- **Evidence:** `AGENTS.md` calls for `npm run test:coverage` (`AGENTS.md:19-39`), but `package.json` defines only `test` (`package.json:5-10`). PHP has no coverage command/configuration beyond source inclusion (`phpunit.xml:15-37`). The frontend suite is one file containing two shallow export/feedback checks (`resources/js/admin/admin-modules.test.ts:16-38`); no Playwright files or scripts were found.
- **Violated requirement:** Coverage thresholds, component tests, and browser flows (`docs/quality-rules.md:858-980`, `docs/quality-rules.md:1125-1161`); Phase 3 requires keyboard/focus/reduced-motion/responsive tests (`docs/implementation-plan.md:737-745`).
- **Recommended remediation:** Configure Xdebug/PCOV and Vitest coverage thresholds; add component tests for forms/selects/dialogs and Playwright smoke tests for storefront checkout and admin critical workflows.
- **Required tests:** Measured 85%/75% backend and 80%/70% frontend thresholds, responsive screenshots/no-overflow, keyboard/focus trap/reduced-motion, login, checkout retry, image upload, inventory adjustment, and order print.

### I7 — API response/error envelopes are inconsistent with the documented contract

- **Severity:** Important
- **Evidence:** Bootstrap customizes only API authentication failures (`bootstrap/app.php:21-26`); controllers otherwise return a mixture of raw models, ad-hoc `data`, `code`, and Laravel validation output (`app/Http/Controllers/Api/Admin/ProductController.php:83-118`, `app/Http/Controllers/Api/Admin/OrderController.php:64-85`).
- **Violated requirement:** Phase 1 calls for standard success/error envelopes and global exception rendering (`docs/implementation-plan.md:349-367`); contracts define predictable conflict/error semantics (`docs/api-contracts.md:3037-3069`).
- **Recommended remediation:** Define API Resources and a single exception-to-envelope mapping that preserves French messages, field errors, request IDs, and documented status/code behavior.
- **Required tests:** Contract tests for validation, authentication, authorization, 404, conflict, stale lock, and internal-error responses.

### I8 — Phase 0 source-of-truth set is incomplete and internally inconsistent

- **Severity:** Important
- **Evidence:** `.specify/memory/constitution.md` and `docs/security-rules.md` are absent, while `docs/api-contracts.md:13` and `docs/quality-rules.md:12` cite `security-rules.md`; AGENTS instead cites `docs/security.md` (`AGENTS.md:75-78`).
- **Violated requirement:** Phase 0 requires all documents, policy files, and no unresolved major requirement conflict (`docs/implementation-plan.md:308-325`).
- **Recommended remediation:** Restore or formally replace the missing constitution/security-rules documents, update all cross-references, and declare the authoritative reading order.
- **Required tests:** Markdown link/reference check in CI.

## 3. Minor cleanup

### M1 — Migration rollback for `archived_at` is potentially destructive

- **Severity:** Minor
- **Evidence:** The rollback drops the populated column directly (`database/migrations/2026_07_18_000700_add_archived_at_to_orders.php:16-18`). All current migrations report as ran.
- **Violated requirement:** Migrations should avoid silent data loss and document irreversible behavior (`docs/quality-rules.md:333-341`).
- **Recommended remediation:** Document the rollback as destructive or guard it according to the deployment rollback policy.
- **Required tests:** Migration upgrade and rollback rehearsal against a populated MySQL copy.

### M2 — No objective bundle/performance budget check is wired

- **Severity:** Minor
- **Evidence:** Production build succeeds but emits a 149.45 kB CSS asset and a 263.31 kB admin JS asset (90.59 kB gzip); no budget script exists in `package.json:5-10`.
- **Violated requirement:** Bundle-size foundation and measured asset budgets (`docs/implementation-plan.md:445-467`; `AGENTS.md:94-96`).
- **Recommended remediation:** Add a CI budget report for public entrypoints and record exceptions explicitly. Do not set a threshold until baseline measurements are agreed.
- **Required tests:** Build-budget assertion for storefront CSS/JS and key rendered-page asset inventory.

### M3 — No temporary/debug bypasses found in application source

- **Severity:** Minor — cleanup check passed
- **Evidence:** Targeted scan of `app/`, `bootstrap/`, `config/`, `routes/`, `resources/`, `tests/`, and `database/` found no `TODO`, `FIXME`, `dd`, `dump`, `var_dump`, `ray`, or `console.log` occurrences.
- **Violated requirement:** None; this is a hygiene check.
- **Recommended remediation:** Keep this scan in CI, excluding dependency/build directories.
- **Required tests:** Repository hygiene check.

## 4. Confirmed compliant

### C1 — Baseline mechanical quality is currently clean

- **Severity:** Confirmed compliant
- **Evidence:** All available backend/frontend quality commands listed in this audit passed; migrations are applied locally.
- **Violated requirement:** None.
- **Recommended remediation:** Preserve these commands in CI and add the missing coverage/browser commands from I6.
- **Required tests:** Re-run on every merge candidate.

### C2 — Guest checkout uses a transaction, deterministic product ordering, persisted fingerprint, and Redis request lock

- **Severity:** Confirmed compliant
- **Evidence:** Checkout starts a transaction, hashes canonical input, replays matching keys, sorts product IDs, and locks products (`app/Domain/Commerce/Actions/CreateGuestOrderAction.php:20-38`); the controller requires UUID v4 and takes a Redis lock (`app/Http/Controllers/Api/GuestOrderController.php:18-37`). The idempotency feature tests pass.
- **Violated requirement:** None for the implemented fixed-field flow.
- **Recommended remediation:** Retain this boundary while addressing B1, B5, and I3.
- **Required tests:** Keep current replay/conflict/stock tests and add the gaps named above.

### C3 — Basic upload pipeline has the right trust boundary

- **Severity:** Confirmed compliant
- **Evidence:** Files are signature-checked, private-staged, represented as pending, dispatched after commit, WebP re-encoded, and originals removed (`app/Http/Controllers/Api/Admin/ProductImageController.php:18-38`; `app/Jobs/ProcessProductImage.php:24-80`).
- **Violated requirement:** None for the basic pipeline; I4 identifies the missing hardening.
- **Recommended remediation:** Preserve private staging and derivative-only public serving when adding limits and worker policy.
- **Required tests:** Keep existing invalid-signature and rendition tests; add I4 cases.

### C4 — Core stock and transition mutations are transactional and authorization is default-deny for guests/inactive staff

- **Severity:** Confirmed compliant
- **Evidence:** Inventory adjustment locks the product/variant and rejects negative stock (`app/Domain/Catalog/Actions/AdjustInventoryAction.php:15-27`); order transitions lock the order and validate the graph (`app/Domain/Commerce/Actions/TransitionOrderStatusAction.php:17-33`); admin APIs require web auth and the catalogue gate (`routes/api.php:25-51`). Relevant authorization, inventory, checkout, and transition tests pass.
- **Violated requirement:** None for these basic controls; B3, I2, and I5 identify the remaining authorization/database/audit depth.
- **Recommended remediation:** Preserve these transaction boundaries while completing the role and audit model.
- **Required tests:** Keep MySQL-backed integration tests and extend concurrency/role coverage.

## Phase 9.5 remediation evidence

Automated remediation evidence now includes 79 backend tests (287 assertions), Pint, PHPStan, frontend lint/typecheck/unit tests, production build, asset-budget checks, five Playwright browser tests, Composer audit, npm audit, documentation-reference checks, and diff checks. Backend coverage runs with the local Xdebug/PCOV-enabled PHP environment; frontend coverage remains below its configured 80% line and 70% branch thresholds and is not waived.

### T083 UI review evidence (2026-07-19)

- French storefront, checkout, and admin-login shells were inspected and the Playwright smoke flow confirmed `lang="fr"`; the admin labels and navigation remain French.
- Keyboard review confirmed the public page receives a visible focus target after Tab. Login and checkout controls define explicit focus outlines; upload controls use `:focus-within`. The reduced-motion browser flow passed, and both public and admin CSS disable transitions/entrance animations under `prefers-reduced-motion: reduce`.
- Responsive rules use breakpoint grids, `minmax(0, ...)`, wrapping action rows, responsive image sizing, and mobile-first single-column layouts. No new fixed-width page container or horizontal-scroll rule was introduced; the production build completed without layout-related asset changes.
- Privacy review found no passwords, session tokens, raw request bodies, or Meta secrets in changed templates, fixtures, screenshots, or frontend source. Operational email/telephone/address fields are confined to authenticated back-office views; public responses remain resource-filtered.

The review found no release-blocking French, keyboard, reduced-motion, mobile-overflow, or private-data leakage issue in the Phase 9.5 changes.

## Gate conclusion

Phase 0–9 cannot be accepted as fully complete. B1–B5 must be resolved before Phase 10 is merged or its configurable shipping/checkout management features are enabled. I1–I8 should be scheduled into the Phase 10 preparation plan, with I1, I2, I4, and I6 treated as release-critical hardening. No code was changed by this audit.
