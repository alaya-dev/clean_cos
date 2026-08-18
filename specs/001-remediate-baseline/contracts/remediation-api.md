# API Contract Delta: Phase 9.5 — Baseline Remediation

This document narrows and normalizes the existing REST contract in `docs/api-contracts.md`.
All endpoint behavior remains under `/api/v1`; all public messages are French; protected routes
authorize on the backend; and success/error payloads use the common envelope.

## Common envelope

Successful responses return `data` and, where useful, safe `meta`. Failures return a stable
machine-readable `code`, a French `message`, field-level `errors` for validation failures, and
a safe `request_id`. Responses never include passwords, password hashes, session or CSRF values,
secrets, raw attribution, raw checkout bodies, or unredacted audit values.

| Situation | Status | Stable code |
|---|---:|---|
| Validation failure | 422 | `VALIDATION_ERROR` |
| Unauthenticated | 401 | `UNAUTHENTICATED` |
| Unauthorized | 403 | `FORBIDDEN` |
| Missing resource | 404 | `NOT_FOUND` |
| Stale edit or business conflict | 409 | Contract-specific conflict code |
| Rate limited | 429 | `RATE_LIMITED` with `Retry-After` |
| Unexpected failure | 500 | `INTERNAL_ERROR` |

## Public quote and checkout

### `POST /api/v1/public/cart/quote`

- Calculates product pricing, discounts, shipping, and total from server state only.
- Returns a pricing breakdown including integer millimes and French-formatted values.
- Uses the same shipping rule as order creation and order-item reconciliation.

### `POST /api/v1/public/orders`

- Requires `Idempotency-Key` UUID v4.
- Accepts only known basket, fixed delivery, active configured checkout-field, and approved
  attribution values.
- Rejects unknown fields, inactive/invalid values, missing required values, unavailable stock,
  and changed data with documented French errors.
- Same key and same canonical payload replays the original safe order response during retention;
  the same key with a different payload returns `409 CHECKOUT_IDEMPOTENCY_CONFLICT`.
- Returns the documented safe order reference, status, items, checkout snapshot, and pricing
  breakdown. It never exposes raw attribution or internal identifiers.

## Identity and privileged administration

### Current user and password lifecycle

The authenticated current-user response exposes only safe staff identity, role, active state,
and forced-password-change state. Password change/reset endpoints invalidate affected sessions,
record a safe audit event, and never return or log a password.

### `/api/v1/admin/users/*`

- Super Admin only.
- Supports list, create, safe detail, update, disable/archive, and password reset as documented
  in `docs/api-contracts.md`.
- Enforces the final-active-Super-Admin and self-lockout protections.
- Each mutation writes a sanitized audit event.

### `/api/v1/admin/audit-logs/*`

- Super Admin only.
- Supports bounded, paginated, redacted list and safe detail retrieval.
- Audit records are immutable and cannot be created, updated, or deleted through this API.

## Order operations and media

### Order edit and transition routes

- Retain optimistic lock version checks, server recalculation, stock locking, and valid
  transition graph enforcement.
- Return `409` for stale edits and documented conflicts.
- Record a sanitized audit event after a successful mutation.
- Use the inventory restoration marker so retries, bulk actions, and concurrent requests cannot
  restore eligible stock twice.

### Product image upload route

- Requires an authorized staff member and per-user rate limit.
- Rejects unsupported, malformed, oversized, or over-pixel-limit content with `422`; rate limits
  return `429` with `Retry-After`.
- Returns a pending, safe image representation. Original and staging paths are never returned.

## Contract verification

Feature tests verify each success, validation, authentication, authorization, missing-resource,
conflict, rate-limit, and unexpected-error envelope. Browser flows exercise login, checkout
retry, upload, and order processing against the same public contract.
