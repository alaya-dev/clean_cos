<!--
Sync Impact Report
- Version change: unversioned template → 1.0.0
- Modified principles: none; this is the project's first ratified constitution.
- Added sections: nine core principles; project constraints; compliance lifecycle;
  governance.
- Removed sections: placeholder-only template content.
- Templates requiring updates:
  ✅ .specify/templates/plan-template.md
  ✅ .specify/templates/spec-template.md
  ✅ .specify/templates/tasks-template.md
  ✅ .specify/templates/commands/ (directory not present; no command templates to update)
- Follow-up TODOs: none.
-->

# ToutDispo E-Commerce Platform Constitution

## Core Principles

### I. Security and Privacy by Design

The platform MUST implement applicable OWASP controls, including secure defaults, input
validation, output encoding, authentication, rate limiting, security headers, and safe
failure behavior. Authorization MUST be enforced by the backend, deny by default, and
apply to every protected action and resource. Secrets and personal information MUST NOT
appear in logs, Sentry, API responses, fixtures, screenshots, or source control. Meta
tracking MUST require the applicable consent. A security-control failure blocks release.

Rationale: customer data, privileged administration, and advertising credentials require
confidentiality, integrity, availability, and accountability by default.

### II. Server Authority and Transactional Integrity

The browser is untrusted: prices, totals, stock, discounts, shipping, statuses, and
permissions MUST be derived and validated by Laravel. Checkout, stock mutations, promo
usage, order edits, and state transitions MUST be transactional and concurrency-safe.
Checkout MUST be idempotent. Each order MUST create at most one logical Meta Purchase
event. Monetary values MUST be stored as integer millimes and displayed as Tunisian dinars
(TND).

Rationale: authoritative server calculations and durable atomic state changes prevent
fraud, overselling, duplicate orders, and incorrect reporting.

### III. Approved Architecture and Simplicity

The application MUST remain a Laravel modular monolith. Public pages MUST use
Blade-rendering with only minimal Vue islands; the private admin SPA MUST use Vue 3 and
TypeScript. MySQL is the durable store, and Redis MUST provide sessions, queues, cache,
locks, and rate limits. Sentry is the only external application-monitoring platform.

The platform MUST NOT introduce microservices, WebSockets, GraphQL, customer accounts,
online payments, product reviews, or unapproved infrastructure. Application behavior MUST
remain neutral between Docker and non-Docker deployments; deployment assumptions MUST NOT
enter domain behavior.

Rationale: this approved scope protects SEO, operational simplicity, reliability, and
maintainability.

### IV. Specification and Contract Fidelity

Approved documentation is the source of truth. Every feature MUST trace to an approved
specification and measurable acceptance criteria. API, database, security, and privacy
changes MUST update their corresponding approved documents in the same change. Conflicts
between approved sources MUST be reported for explicit resolution, never silently resolved.
Requirements MUST NOT be invented.

Rationale: traceability keeps the implementation aligned with approved business, security,
privacy, and operational decisions.

### V. Testable Vertical Slices

Work MUST be delivered as one reviewable vertical slice at a time, with tests in the same
change. Relevant integration and concurrency tests MUST use MySQL and Redis, not substitute
services. Tests MUST cover authorization, validation, failure behavior, concurrency,
idempotency, and relevant abuse cases. A phase MUST NOT advance until its documented
acceptance gate passes.

Rationale: production-representative tests are necessary for checkout, inventory, and Meta
event correctness.

### VI. Quality Gates and Human Review

Formatting, static analysis, automated tests, frontend type checking, production build,
dependency audits, and secret scanning MUST pass before release. Codex-generated changes
MUST receive human diff review. High or critical exploitable security findings MUST block
merge. CI MUST NOT be weakened merely to make a change pass. Completion claims MUST include
the commands run and their results.

Rationale: tooling verifies repeatable baselines; human review verifies scope, security,
and business intent.

### VII. Performance, SEO, Accessibility, and UX

Public pages MUST remain server-rendered and SEO-safe. Core Web Vitals and approved asset
budgets are release requirements. Implementations MUST avoid N+1 queries and unnecessary
JavaScript. Interfaces MUST meet WCAG AA expectations, remain French in both public and
back-office surfaces, use approved design tokens and restrained motion, and respect
reduced-motion preferences.

Rationale: fast, accessible French-language commerce is a core product requirement, not a
post-release enhancement.

### VIII. Data Preservation and Safe Evolution

Historical orders, snapshots, audit logs, and referenced variants MUST NOT be destructively
rewritten. Schema changes MUST include a migration plus rollback or forward-fix analysis and
preserve historical meaning. Meta trigger changes MUST apply only to future orders. Breaking
changes require explicit approval and a migration plan.

Rationale: commerce records, auditability, and attribution decisions must remain reliable
after operational and schema evolution.

### IX. Governance and Exceptions

This constitution uses semantic versioning. It MUST record ratification and amendment dates.
Every amendment MUST state its reason and affected documents. Constitution compliance MUST
be checked during specification, planning, task generation, analysis, implementation, and
final review. An exception MUST identify an owner, documented risk, compensating controls,
and expiry date.

Rationale: durable principles need explicit, reviewable change control without creating
permanent undocumented exceptions.

## Project Constraints

Cash on Delivery is the approved payment method. The platform serves Tunisia in French only.
There are no customer accounts, multiple currencies, online payment flows, reviews, or
real-time features in the approved scope. Meta Pixel and Conversions API events MUST pass
through the approved tracking boundary; browser and server copies MUST use stable event IDs
and deduplicate. Monitoring and diagnostics MUST be privacy-safe, and Sentry failures MUST
NOT interrupt checkout or administrative operations.

## Compliance Lifecycle

Specifications MUST identify the applicable principles, source documents, acceptance
criteria, privacy impact, security impact, data impact, API impact, and migration impact.
Plans MUST pass the Constitution Check before design work and again after design. Tasks MUST
include the required implementation, documentation, test, verification, and release-gate
work. Analysis and review MUST verify traceability, document conflicts, and reject
unapproved scope. Implementation and final review MUST retain command evidence and confirm
all applicable gates.

## Governance

This constitution governs specification, planning, implementation, review, and release. It
does not replace contributor operating instructions, commands, file paths, or workflow
guidance in `AGENTS.md`. Where approved documents conflict, the conflict MUST be surfaced to
the owner for a decision; no contributor MAY choose an interpretation silently. Security and
privacy controls remain release-blocking unless a time-bounded exception is approved with all
requirements in Principle IX.

Amendments require a documented rationale, affected-document list, and semantic version bump:
MAJOR for incompatible removal or redefinition of governance, MINOR for a new or materially
expanded principle, and PATCH for clarification without semantic change. Compliance MUST be
reviewed at every Spec Kit workflow stage and in final human review.

**Version**: 1.0.0 | **Ratified**: 2026-07-18 | **Last Amended**: 2026-07-18
