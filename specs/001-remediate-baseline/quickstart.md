# Quickstart Validation: Phase 9.5 — Baseline Remediation

## Prerequisites

- Work on the dedicated `001-remediate-baseline` branch.
- Configure the approved local MySQL/MariaDB-compatible and Redis-compatible services.
- Use synthetic customer, order, and upload data only.
- Ensure the configured Sentry test environment uses the privacy-safe setup already introduced.

## Setup

```powershell
composer install
npm ci
php artisan migrate
```

Review [data-model.md](data-model.md) before migration work and
[remediation-api.md](contracts/remediation-api.md) before API work.

## Required validation scenarios

1. Quote and submit the same basket below, at, and above the free-delivery threshold. Confirm
   matching shipping and total snapshots.
2. Submit checkout with valid configured values; then test omitted required, unknown, invalid,
   and stale field states. Confirm no rejected request creates an order or stock movement.
3. Retry a successful checkout with the same key and payload, then with changed payload. Confirm
   one order and correct replay/conflict behavior. Exercise expiry cleanup without deleting the
   order.
4. Exercise Super Admin user management, Admin denial, final Super Admin protection, password
   reset/change session revocation, and audit redaction.
5. Exercise every allowed and disallowed order transition, including concurrent restoration and
   return restock choices. Confirm one restoration maximum and immutable audit events.
6. Test direct durable-data invariant rejection, populated-data migration rehearsal, and
   forward-fix behavior for archived-order data.
7. Test upload rate limits, pixel boundary, malformed content, processing retry/failure, and
   absence of original or staging-file exposure.
8. Test Redis-unavailable readiness behavior and confirm monitoring unavailability does not
   prevent a valid checkout.
9. Run the documentation-reference, hygiene, coverage, browser, and asset-budget checks.

## Commands and expected results

```powershell
php artisan test
.\vendor\bin\pint --test
.\vendor\bin\phpstan analyse
npm run lint
npm run typecheck
npm run test:coverage
npm test -- --run
npm run test:browser
npm run build
node scripts/check-asset-budgets.mjs
composer audit
npm audit --audit-level=high
composer check-doc-references
git diff --check
```

Run the configured browser smoke suite and reference/hygiene/budget commands added by this
phase. All commands must pass. Integration and concurrency tests must use the local
MySQL/MariaDB-compatible and Redis-compatible services; no SQLite, array, file, or database
queue fallback is acceptable.

## Acceptance evidence

Capture command output, migration rehearsal result, contract-test result, browser-flow result,
coverage report, asset-budget result, Sentry redaction verification, and human diff review in
the change record. Resolve each B1, B3–B5, I1–I8, M1, and M2 audit finding before advancing;
record B2 verification separately.

Current local evidence: backend tests (79 tests, 287 assertions), Pint, PHPStan, `composer test:coverage`
with the local Xdebug coverage driver, frontend lint/typecheck/tests, Playwright smoke tests, asset
budgets, Composer audit, npm audit, documentation references, and diff checks pass. Frontend
coverage remains below its configured 80% line / 70% branch thresholds and is intentionally not
waived or lowered.
