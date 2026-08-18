# Implementation Plan: Phase 9.5 — Baseline Remediation

**Branch**: `001-remediate-baseline` | **Date**: 2026-07-18 | **Spec**:
[spec.md](spec.md)

**Input**: Feature specification from `specs/001-remediate-baseline/spec.md`

## Summary

Remediate the unresolved Phase 0–9 baseline findings before Phase 10: make quote, checkout,
and order reconciliation use one authoritative shipping calculation; complete privileged user
and audit controls; enforce the checkout and API contracts; harden durable invariants, upload
processing, operational dependencies, and quality checks; and correct stale documentation and
migration safety gaps. B2 is verification-only because its Sentry setup was remediated after the
audit. The implementation remains a focused vertical remediation slice and does not deliver
Phase 10 business capabilities.

## Technical Context

**Language/Version**: PHP 8.2+; TypeScript 5.7

**Primary Dependencies**: Laravel 12, Sanctum session authentication, Vue 3, Pinia, Vite,
Vitest, Sentry Laravel and Vue SDKs

**Storage**: MySQL/MariaDB for durable records; Redis for sessions, queues, cache, locks, and
rate limits; private and public filesystem disks for media

**Testing**: PHPUnit/Laravel feature and integration tests; Vitest/Vue Test Utils; Playwright
critical-flow browser tests; MySQL and Redis are mandatory for relevant integration and
concurrency tests

**Target Platform**: Deployment-neutral single-VPS web application with server-rendered public
pages and a private admin SPA

**Project Type**: Laravel modular monolith with Blade storefront islands and Vue 3 admin SPA

**Performance Goals**: Preserve approved public asset and Core Web Vitals budgets; prevent N+1
queries; keep checkout independent of monitoring and image-processing availability

**Constraints**: French user-facing text; integer millimes; backend authority; deny-by-default
authorization; Sentry-only monitoring; no Phase 10+ business scope; no Docker-specific domain
behavior; historical records and Meta trigger snapshots preserved

**Scale/Scope**: Remediate B1, B3–B5, I1–I8, M1, and M2 from the baseline audit; verify B2;
leave M3 and C1–C4 unchanged

## Constitution Check

### Pre-design gate — PASS

- **Source and acceptance traceability**: The spec traces requirements to the Phase 0–9 audit,
  implementation plan, API contracts, privacy, design, quality rules, and constitution.
- **Security and privacy**: Server authority, backend authorization, safe failure, audit and
  Sentry redaction, upload abuse controls, and restricted personal-data handling are explicit.
- **Transactional integrity**: Shared shipping calculation, checkout idempotency, stock
  restoration uniqueness, order locks, and MySQL/Redis integration tests are planned.
- **Architecture**: The plan retains the approved Laravel modular monolith, Blade storefront,
  Vue TypeScript admin, MySQL, Redis, Sentry-only monitoring, and deployment neutrality.
- **Documentation and safe evolution**: API, database, security, privacy, migration, and source
  reference updates are included; historical order and Meta data are protected.
- **Quality and UX**: Required quality, coverage, browser, reference, hygiene, budget, French
  UI, accessibility, and reduced-motion evidence are included.
- **Conflict**: Existing documents refer to missing `security-rules.md`; the phase updates
  references to the declared authoritative `docs/security.md`. No exception is needed.

### Post-design gate — PASS

The research decisions, data model, contracts, and quickstart retain every pre-design control.
All identified design uncertainties have a decision; no clarification or constitution exception
remains.

## Project Structure

### Documentation (this feature)

```text
specs/001-remediate-baseline/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── remediation-api.md
└── checklists/
    └── requirements.md
```

### Source Code (repository root)

```text
app/
├── Domain/
│   ├── Catalog/                 # products, inventory, images, upload rules
│   ├── Checkout/                # quote, shipping, checkout field and idempotency rules
│   ├── IdentityAccess/          # user, role, password, session controls
│   ├── Orders/                  # edits, transitions, restoration, order snapshots
│   └── Audit/                   # sanitized append-only audit boundary
├── Http/
│   ├── Controllers/Api/         # public and admin contract endpoints
│   ├── Middleware/              # request identity and protected API behavior
│   └── Requests/                # validation and allow-lists
├── Jobs/                        # bounded image-processing behavior
├── Models/
└── Providers/
config/                           # Redis, queues, sessions, cache, monitoring defaults
database/
├── migrations/                  # forward-safe, MySQL-tested changes
└── factories/
docs/                             # contract, security-reference, migration updates
resources/js/admin/               # user-management and audit views/tests where required
tests/
├── Feature/
├── Integration/
├── Unit/
└── Browser/
```

**Structure Decision**: Extend the existing modular monolith at its domain boundaries. Reuse
existing checkout, catalogue, and order actions; introduce only the dedicated audit and identity
boundaries required by the audit. Do not add services, realtime infrastructure, or an alternate
application architecture.

## Complexity Tracking

No constitution violation requires justification.
