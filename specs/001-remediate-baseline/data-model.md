# Data Model: Phase 9.5 — Baseline Remediation

## Existing entities affected

### Order and order item

- Remain immutable financial and product snapshots after creation.
- Continue to store money as unsigned integer millimes and retain the existing public reference.
- Gain a retention-safe relationship to checkout idempotency data rather than relying on a
  permanent replay fingerprint embedded in the historical record.
- Allowed statuses remain `nouvelle`, `confirmee`, `livree`, `annulee`, `echec_livraison`, and
  `retournee`; invalid values are rejected at the durable-data boundary.

### Checkout field and order checkout value

- `checkout_fields` remains the source for active configuration: key, label, type, options,
  required state, active state, system state, and display order.
- `order_checkout_values` retains a normalized snapshot: field-key, label, type, value, and an
  optional reference to the configured field.
- Submission accepts only the active schema plus approved fixed delivery fields. Unknown keys,
  inactive required-field omissions, invalid options, and invalid types are rejected.

### Back-office user

- Retains public identifier, role, active state, and disabled timestamp.
- Adds password-state and session-revocation information necessary for forced password change
  and invalidating affected sessions after reset or password change.
- Roles remain `admin` and `super_admin`; only Super Admin can manage users and read audit logs.
- The final active Super Admin cannot be disabled, removed, or demoted.

### Product, variant, and inventory movement

- Existing price, stock, variant, and movement records remain authoritative.
- Durable rules reject invalid promotional-price relationships, non-negative stock violations,
  and invalid simple-versus-variant stock ownership.
- Existing movement history remains append-only and is linked to any restoration decision.

### Product image

- Retains private staging state, public derivative state, processing status, dimensions, and
  ownership by product.
- New operational metadata may record bounded processing attempts and terminal failure state;
  originals and staging files remain non-public.

## New entities

### Checkout idempotency record

| Field | Rules | Purpose |
|---|---|---|
| id | Immutable primary identifier | Internal record identity |
| idempotency_key | Unique while active; UUID v4 | Identifies a replayable checkout request |
| canonical_payload_hash | Fixed-length fingerprint | Distinguishes same-key replay from conflict |
| order_id | Required immutable order relation | Returns the original successful outcome |
| expires_at | Required indexed timestamp | Enforces the approved replay window |
| created_at | Immutable timestamp | Supports retention processing |

The cleanup job removes or irreversibly detaches this record only after expiry. It never deletes
the related order, order items, checkout values, stock movement, or audit history.

### Audit log

| Field | Rules | Purpose |
|---|---|---|
| id / public identifier | Immutable unique identity | Safe audit lookup |
| actor_user_id | Nullable protected user relation | Identifies the actor when applicable |
| actor_role_snapshot | Required when actor exists | Preserves authorization context |
| action | Required controlled value | Names the business operation |
| auditable_type / auditable_id | Required resource identity | Identifies affected resource |
| request_id | Nullable safe correlation value | Supports investigation without request body |
| before / after | Sanitized structured values | Records safe business change summary |
| created_at | Immutable timestamp | Establishes event order |

Audit logs are append-only. Values must exclude passwords, hashes, session values, tokens,
addresses, telephone numbers, raw attribution, and raw request bodies.

### Inventory restoration marker

| Field | Rules | Purpose |
|---|---|---|
| id | Immutable primary identifier | Internal record identity |
| order_id | Required relation | Identifies the restored order |
| restoration_reason | Controlled cancellation, failed-delivery, or approved return value | Identifies the event |
| inventory_movement_id | Required relation | Links the resulting stock movement |
| created_at | Immutable timestamp | Supports audit and concurrency analysis |

A unique order-and-restoration scope prevents a duplicate command or concurrent request from
restoring stock twice.

## Relationships and lifecycle

```text
Order 1 ── 0..1 active CheckoutIdempotencyRecord
Order 1 ── * OrderCheckoutValue
Order 1 ── * OrderStatusHistory
Order 1 ── 0..* InventoryRestorationMarker ── 1 InventoryMovement
BackOfficeUser 1 ── * AuditLog
Order/Product/User/etc. 1 ── * AuditLog (polymorphic resource reference)
Product 1 ── * ProductImage
```

Order transitions remain:

```text
nouvelle → confirmee
nouvelle → annulee
confirmee → livree
confirmee → echec_livraison
livree → retournee
```

Only cancellation and failed delivery restore stock automatically. A return requires an explicit
restock decision. Every transition and eligible edit retains optimistic locking and produces a
sanitized audit event.

## Migration and preservation rules

### T084 migration review evidence (2026-07-19)

| Migration | Compatibility and preservation review | Rollback / forward-fix note |
|---|---|---|
| `2026_07_18_000800_create_audit_logs_table.php` | Uses standard InnoDB tables, nullable actor foreign key, JSON columns, and portable indexes. It adds audit history without rewriting existing orders or events. | Rollback drops only the new audit table; production rollback requires an approved forward fix because audit history is operational evidence. |
| `2026_07_18_000810_create_checkout_idempotency_records_table.php` | Uses UUID, fixed CHAR hash, indexed expiry, and restrictive order relation; verified by MySQL/MariaDB integration tests. Existing orders and snapshots are untouched. | Rollback drops only replay records. Expired records may be cleaned up; successful orders and stock history remain preserved. |
| `2026_07_18_000820_add_password_lifecycle_to_users_table.php` | Additive nullable/defaulted user columns with explicit placement; compatible with the local MariaDB 10.4 service and MySQL target. Existing identity records retain their meaning. | Rollback removes only lifecycle metadata. Before rollback, invalidate/reconcile affected sessions and use a forward fix if populated lifecycle state must be retained. |
| `2026_07_18_000830_create_inventory_restoration_markers_table.php` | New InnoDB table with restrictive order relation, nullable movement relation for deletion safety, and unique order/reason scope. Existing movements and orders are not rewritten. | Rollback drops only markers; do not use on populated production data without preserving restoration evidence and reconciling stock first. |
| `2026_07_18_000840_add_commerce_integrity_constraints.php` | Normalizes only the legacy ambiguous variant zero (`has_variants=1` and `stock_quantity=0`) to `NULL`, then adds promotion, stock, status, and variant-mode checks. Direct rejection tests pass on MariaDB 10.4. The constraint syntax must be revalidated in the approved MySQL 8.4 deployment before release. | Dropping checks is reversible structurally, but rollback after invalid-data rejection or normalization is not a restoration of historical meaning. Use a forward fix and retain the normalized variant-stock interpretation. |

Review conclusion: no reviewed migration destructively rewrites historical order totals, checkout snapshots, audit events, referenced variants, inventory movements, or Meta trigger snapshots. The only data normalization is the documented ambiguous variant-stock zero conversion. The MySQL 8.4 compatibility rehearsal for the final constraint migration remains a release follow-up if a MySQL service is not available locally.

- Use additive, backwards-compatible migrations before any removal or nullable-to-required
  contract step.
- Test direct constraint rejection and upgrade/forward-fix behavior against MySQL/MariaDB.
- Rehearse each changed migration with populated orders, archived orders, order snapshots, and
  related inventory data.
- Do not rewrite historical order totals, checkout snapshots, audit events, referenced variants,
  or Meta trigger snapshots.
- Treat rollback of populated `archived_at` data as forward-fix-only unless a safe restoration
  path is verified and documented.
