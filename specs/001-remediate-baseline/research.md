# Phase 0 Research: Phase 9.5 — Baseline Remediation

## Decision 1: One authoritative shipping calculation boundary

**Decision**: Introduce one server-side shipping calculation boundary used by the public quote,
guest order creation, and eligible order-item reconciliation. Persist the calculated snapshot
only after this boundary derives it from approved settings and server-side basket values.

**Rationale**: B1 shows three paths applying different free-shipping logic. A single boundary
preserves server authority and ensures quote, checkout, and order-edit totals agree.

**Alternatives considered**:

- Copy the threshold rule into each action: rejected because divergence already occurred.
- Trust the quote total on submission: rejected because browser values are untrusted.

## Decision 2: Separate short-lived idempotency replay data from historical orders

**Decision**: Move checkout replay fingerprint and expiry behavior to a retention-managed
idempotency record linked to the immutable order. Keep one active record per key during the
approved retention period, purge or irreversibly detach the replay record on schedule, and
preserve the order and its financial snapshots permanently.

**Rationale**: This satisfies the seven-day privacy default and cleanup requirement without
deleting or altering historical orders.

**Alternatives considered**:

- Keep replay keys forever on the order: rejected because it provides no cleanup policy.
- Delete or recreate historical orders when a key expires: rejected because it violates data
  preservation and can create duplicate stock effects.

## Decision 3: Use one sanitized append-only audit boundary

**Decision**: Record authenticated operational mutations through a dedicated audit boundary.
Each event stores actor, role snapshot, action, auditable resource identity, request context,
safe changed-field values, and timestamp; it stores neither secrets nor unnecessary checkout
personal data. Audit records are immutable and viewable only by Super Admin.

**Rationale**: B4 requires a consistent accountability record across identity, order, inventory,
catalogue, and bulk operations without duplicating ad-hoc logging.

**Alternatives considered**:

- Rely on order status history and inventory movements alone: rejected because they do not
  cover all protected mutations or safe before/after values.
- Persist full request bodies: rejected by privacy and secret-redaction requirements.

## Decision 4: Enforce invariants at both business and durable-data boundaries

**Decision**: Retain business validation and add MySQL/MariaDB-compatible constraints, unique
markers, and guarded schema rules for promotional-price ordering, non-negative stock, valid
order statuses, stock-mode ownership, idempotency retention, and one-time inventory
restoration. Verify every constraint with direct MySQL tests.

**Rationale**: B2 and I5 require concurrency protection that application checks alone cannot
guarantee; the quality rules require durable enforcement.

**Alternatives considered**:

- Application validation only: rejected because direct writes and race conditions can bypass it.
- Broad database triggers: rejected as harder to review and test than explicit constraints and
  narrowly scoped records.

## Decision 5: Validate configured checkout fields from the active server schema

**Decision**: Resolve the active checkout-field schema on submission; allow only its fixed and
configured keys, validate type/options/required state, normalize accepted values, snapshot each
accepted value, and apply explicit attribution allow-lists and length limits. Return API
Resources and standard French envelopes.

**Rationale**: B5 identifies that fixed controller validation cannot safely support activated
custom fields or the documented response contract.

**Alternatives considered**:

- Accept arbitrary custom JSON: rejected because unknown data creates privacy and integrity risk.
- Keep only fixed fields: rejected because it conflicts with the approved configurable contract.

## Decision 6: Make Redis mandatory by explicit configuration and safe readiness

**Decision**: Configure sessions, queues, cache, locks, and rate limiting to use Redis in the
approved runtime; validate this at startup/readiness and return a minimal, safe unavailable
result when Redis is required but unreachable.

**Rationale**: I1 identifies inconsistent database fallbacks despite Redis being a required
trust and concurrency boundary.

**Alternatives considered**:

- Silent database or file fallback: rejected by project rules and changes queue/lock behavior.
- Treat readiness as healthy when Redis is down: rejected because protected operations could
  partially fail later.

## Decision 7: Harden the existing private media pipeline

**Decision**: Keep signature validation, private staging, derivative-only public serving, and
after-commit processing. Add the approved pixel ceiling, Redis-backed per-user throttling,
bounded retry/timeout/backoff behavior, constrained media-worker concurrency, and safe
permanent-failure reporting.

**Rationale**: I4 is a hardening gap, not a reason to replace an already correct trust boundary.

**Alternatives considered**:

- Process uploads synchronously: rejected because it harms responsiveness and failure isolation.
- Publish originals during processing: rejected because unprocessed files must remain private.

## Decision 8: Turn release evidence into executable checks

**Decision**: Add reviewed coverage configuration and thresholds, component and critical-browser
flows, documentation-reference and hygiene validation, and an agreed baseline asset-budget
check. Preserve existing formatter, static-analysis, test, type, build, audit, and secret-scan
gates.

**Rationale**: I6 and M2 show that documented gates are not yet executable or measured.

**Alternatives considered**:

- Treat a successful build as sufficient: rejected because coverage, browser accessibility, and
  budget regressions would remain undetected.
- Set arbitrary budgets before measuring the public entrypoints: rejected because budgets need
  a reviewed baseline and documented exceptions.

## Decision 9: Correct references and use forward-safe migrations

**Decision**: Declare `docs/security.md` as the current authoritative security document, update
stale references, add a reference checker, and document a source reading order. Use
expand/validate/contract migration steps, populated-data rehearsal, backup expectations, and a
forward-fix path where rollback would destroy archived data.

**Rationale**: I8 and M1 are governance and data-preservation gaps. The constitution prohibits
silently resolving document conflicts and destructive historical rewrites.

**Alternatives considered**:

- Duplicate the security document under the old name: rejected because parallel policies drift.
- Drop populated archive columns on rollback: rejected because destructive behavior must be
  prevented or explicitly handled by a forward fix.
