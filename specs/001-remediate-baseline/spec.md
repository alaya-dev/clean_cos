# Feature Specification: Phase 9.5 — Baseline Remediation

**Feature Branch**: `Not assigned — a new dedicated branch is required before implementation;
this branch MUST not contain another specification`

**Created**: 2026-07-18

**Status**: Draft

**Input**: Remediate the unresolved findings in the Phase 0–9 baseline audit before Phase 10
work proceeds. The Sentry setup finding is already fixed and is verification-only in this phase.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Receive an Accurate, Safe Order (Priority: P1)

As a customer, I receive a quote and submitted order with the same server-calculated delivery
fee and total, and I can complete checkout only with the currently required delivery details.

**Why this priority**: Incorrect totals or incomplete delivery data make completed orders
unreliable and block safe activation of configurable checkout and shipping features.

**Independent Test**: A customer can obtain a quote and submit the same basket at, below, and
above the free-delivery threshold; each accepted order has the quoted total and a complete,
validated delivery snapshot.

**Acceptance Scenarios**:

1. **Given** a basket at the free-delivery threshold, **When** a customer obtains a quote and
   submits the unchanged basket, **Then** the delivery fee and total are identical in both
   results.
2. **Given** active required checkout fields, **When** a customer omits a required value or
   submits an unknown field, **Then** the order is rejected with a clear French field error and
   no order or stock mutation is created.
3. **Given** a valid repeat checkout request, **When** it is retried within the retention
   period, **Then** it returns the original order without creating a duplicate order or stock
   mutation.

---

### User Story 2 - Operate Orders with Accountable Access (Priority: P1)

As a Super Admin, I can safely manage back-office users and staff can process eligible orders
without bypassing role restrictions, stock integrity, audit history, or historical order data.

**Why this priority**: Privileged access and order operations are security-critical and are
blocking findings from the Phase 2 and Phase 9 acceptance gates.

**Independent Test**: A Super Admin can manage an eligible staff account and review its safe
audit record, while an Admin is denied restricted actions; an order can complete every allowed
lifecycle path with stock restored at most once where required.

**Acceptance Scenarios**:

1. **Given** the final active Super Admin account, **When** a privileged user attempts to
   disable, remove, or demote it, **Then** the request is rejected and no access lockout occurs.
2. **Given** an Admin user, **When** that user directly requests a Super-Admin-only operation,
   **Then** the operation is denied and no protected record changes.
3. **Given** an eligible order, **When** staff performs a permitted transition or edit,
   **Then** the change is recorded with the actor, action, resource, and sanitized before/after
   values, and any required stock restoration occurs only once.

---

### User Story 3 - Keep the Operating Baseline Resilient (Priority: P2)

As the store owner, I can rely on the platform to preserve its data rules, resist upload and
request abuse, return consistent French API results, and detect failures before a release.

**Why this priority**: The remaining important baseline findings affect data integrity,
availability, release confidence, and documented contract fidelity.

**Independent Test**: The release candidate rejects invalid stored values and abusive uploads,
uses the required operational services, handles expected service unavailability safely, and
passes executable quality, browser, coverage, reference, and asset-budget checks.

**Acceptance Scenarios**:

1. **Given** an invalid catalogue, stock, or order state, **When** an attempt is made to store
   it outside normal application validation, **Then** it is rejected and existing historical
   orders remain unchanged.
2. **Given** an abusive or malformed image upload, **When** it exceeds an approved limit or
   processing fails, **Then** the upload is rejected or safely isolated, the customer-facing
   catalogue remains unchanged, and the failure is safely diagnosable.
3. **Given** a release candidate, **When** its required checks run, **Then** the documented
   quality, browser, reference, and performance-budget checks are executable and produce
   reviewable evidence.

---

### User Story 4 - Preserve a Trustworthy Documentation and Migration Baseline (Priority: P3)

As a project owner, I can trace live behavior to an unambiguous approved source set and apply
schema evolution without silently destroying recorded operational meaning.

**Why this priority**: Clear source ownership and safe migration behavior prevent future phases
from reintroducing already identified integrity and governance defects.

**Independent Test**: Documentation references resolve to the approved sources, and a populated
representative data set can pass the documented upgrade and recovery rehearsal without silent
loss of archived information.

**Acceptance Scenarios**:

1. **Given** a project document reference, **When** automated reference validation runs,
   **Then** every required source exists and points to the declared authoritative document.
2. **Given** populated archived-order data, **When** a migration recovery procedure is assessed,
   **Then** its destructive effect is prevented or explicitly documented with an approved
   forward-fix or rollback path.

### Edge Cases

- A basket changes between quote and submission, including crossing the free-delivery threshold.
- Two staff actions attempt to restore the same order stock concurrently.
- A retry arrives after idempotency replay data reaches its approved retention boundary.
- A checkout field is deactivated after a customer loaded the form but before submission.
- A media-processing failure, Redis outage, or monitoring outage occurs during an otherwise
  valid operation.
- A reference checker encounters a deliberately retired document name.
- A migration recovery path is considered after archived records have been populated.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST calculate delivery fees through one authoritative business rule
  for quote, order creation, and eligible order reconciliation.
- **FR-002**: The system MUST persist the delivery-fee and total snapshots derived by that
  authoritative rule and MUST NOT trust browser-supplied monetary values.
- **FR-003**: The system MUST validate checkout input against the active server-defined schema,
  require all active required fields, reject unknown fields, and retain a normalized order
  snapshot of accepted fields.
- **FR-004**: The system MUST restrict checkout attribution to approved fields and limits and
  MUST prevent it from appearing in public responses or monitoring data where it is not needed.
- **FR-005**: A successful checkout response MUST provide the documented, privacy-safe order and
  pricing snapshot; all checkout error responses MUST follow the documented French contract.
- **FR-006**: The system MUST preserve idempotent checkout behavior for matching retries during
  the approved retention period and MUST define, execute, and test retention cleanup without
  duplicating orders or stock mutations.
- **FR-007**: The system MUST provide current-user and password-management flows, controlled
  Super-Admin-only user administration, forced password-change behavior where applicable, and
  revocation of affected sessions after password reset or change.
- **FR-008**: The system MUST enforce distinct Admin and Super Admin capabilities on every
  protected server operation and MUST protect the final active Super Admin from self-lockout or
  removal.
- **FR-009**: The system MUST record append-only, sanitized audit events for authenticated
  operational mutations, including actor, action, timestamp, resource, and safe before/after
  values; audit data MUST NOT contain secrets or unnecessary customer personal data.
- **FR-010**: The system MUST preserve allowed order transitions, reject all invalid transitions,
  protect terminal orders from ineligible edits, and restore stock at most once for each eligible
  cancellation, failed-delivery, or return decision.
- **FR-011**: The system MUST use the required operational service configuration for sessions,
  queues, caching, locking, and rate limits, and MUST fail safely with a clear readiness result
  when a required service is unavailable.
- **FR-012**: The system MUST reject invalid catalogue, promotional-price, stock-mode, and order
  status states at the durable-data boundary as well as through normal business validation.
- **FR-013**: The system MUST apply approved upload limits and throttling, safely isolate failed
  image processing, and ensure temporary or original upload files cannot become public.
- **FR-014**: The system MUST return consistent documented success and error envelopes for
  validation, authentication, authorization, missing resources, conflicts, stale updates, and
  unexpected failures without exposing internal details.
- **FR-015**: The project MUST provide executable quality, coverage, browser-flow, reference,
  hygiene, and approved asset-budget checks, with results retained as release evidence.
- **FR-016**: The project MUST reconcile document references to the current constitution and
  authoritative security document, publish the declared reading order, and fail validation for
  unresolved required references.
- **FR-017**: Schema changes in this phase MUST preserve historical order and archive meaning,
  include a populated-data migration rehearsal, and document a rollback or forward-fix path;
  destructive rollback MUST NOT be implicit.
- **FR-018**: The phase MUST verify the already-remediated Sentry integration and scrubbing
  behavior without re-scoping it as new implementation work.
- **FR-019**: The phase MUST NOT implement Phase 10 or later business capabilities, alter Meta
  Purchase trigger history, introduce customer accounts or online payments, or weaken existing
  security, privacy, transaction, idempotency, or quality gates.

### Constitutional and Contract Impact *(mandatory)*

- **Governing documents**: `docs/implementation-plan.md`,
  `docs/baseline-audit-phase-0-9.md`, `.specify/memory/constitution.md`,
  `docs/api-contracts.md`, `docs/privacy.md`, `docs/design.md`, and
  `docs/quality-rules.md`.
- **Acceptance criteria traceability**: Phase 0 source-of-truth and gate requirements; Phase 1
  service, API-envelope, Sentry, and quality requirements; Phase 2 identity and audit gate;
  Phase 6–7 authoritative quote, checkout, idempotency, and order requirements; Phase 8 upload
  requirements; and Phase 9 audit, transition, stock, and export requirements.
- **Security and authorization impact**: Backend deny-by-default authorization, privileged-user
  protection, session safety, upload abuse resistance, safe failure, audit redaction, and
  release-quality evidence are required.
- **Privacy and Meta impact**: Checkout and audit data must be minimized and redacted; replay
  retention must follow the approved seven-day default unless an approved retention change is
  documented. The phase does not change Meta triggers or historical Meta records.
- **API and data impact**: Checkout and common response contracts, user and audit records,
  durable integrity rules, idempotency-retention handling, and migration documentation may
  change. Historical orders, snapshots, audit history, and referenced variants must be
  preserved.
- **Performance, SEO, accessibility, and French UI impact**: Public rendering remains
  server-safe; upload and API safeguards must not create unnecessary public-page cost. Any
  changed public or admin user-facing text remains French, accessible, responsive, and
  reduced-motion-safe.
- **Conflicts or approved exceptions**: The audit references missing `docs/security-rules.md`,
  while the current repository contains `docs/security.md`; this phase resolves references to
  the owner-approved authoritative security document. No exception is approved.

### Key Entities *(include if feature involves data)*

- **Shipping calculation snapshot**: The authoritative delivery fee and total captured for a
  quote or order at the time it is calculated.
- **Checkout field snapshot**: The accepted, normalized fixed and configured delivery values
  retained with an order.
- **Back-office user**: A staff identity with a protected role, active state, password state,
  and revocable sessions.
- **Audit event**: An append-only, sanitized record linking an operational mutation to its actor
  and affected resource.
- **Idempotency retention record**: The replay information governed by the approved checkout
  retention policy without compromising historical order records.
- **Inventory restoration marker**: The durable evidence that an eligible order-stock
  restoration decision has already been applied.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of tested baskets at, below, and above the free-delivery threshold, the
  unchanged quote and submitted order show identical delivery fees and totals.
- **SC-002**: 100% of tested unauthorized privileged operations are denied without changing a
  protected record, and every tested permitted operational mutation produces one sanitized
  audit event.
- **SC-003**: 100% of tested valid order lifecycle paths succeed; 100% of tested invalid paths
  fail; and concurrent eligible restoration attempts result in exactly one stock restoration.
- **SC-004**: 100% of tested active required checkout-field omissions and unknown fields are
  rejected before order creation, while valid repeated submissions create no duplicate order or
  stock mutation.
- **SC-005**: All required quality, coverage, browser-flow, reference, hygiene, and approved
  asset-budget checks are executable and pass for the release candidate.
- **SC-006**: Every required documentation reference resolves successfully, and each changed
  migration has recorded populated-data rehearsal evidence plus a rollback or forward-fix
  decision.
- **SC-007**: The verified Sentry integration continues to exclude tested names, telephone
  numbers, addresses, cookies, tokens, and request bodies, and monitoring unavailability does
  not prevent a valid checkout from completing.

## Assumptions

- Before planning or implementation, Phase 9.5 will be assigned its own new dedicated branch.
  Every future specification must likewise use a new branch that is not shared with another
  specification.
- Phase 9.5 remediates all unresolved blocker, important, and open minor findings in the
  baseline audit: B1, B3–B5, I1–I8, M1, and M2. B2 is verification-only because the user has
  confirmed it was fixed after the audit; M3 and C1–C4 require no remediation.
- This is a temporary readiness phase between Phase 9 and Phase 10; it does not deliver Phase
  10 business features or change the documented implementation sequence beyond recording this
  remediation slice.
- The existing `docs/security.md` is the authoritative replacement for the missing
  `docs/security-rules.md`; this phase updates stale references rather than duplicating security
  policy content.
- Existing valid production-like data must be preserved. Any retention cleanup removes or
  irreversibly detaches replay fingerprints only as permitted by the approved privacy policy and
  must not delete historical orders.
- All relevant integration, concurrency, and migration-rehearsal tests use the approved
  MySQL-compatible and Redis-compatible services.
