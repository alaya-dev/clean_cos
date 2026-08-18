# Roles and Authorization Matrix

## ToutDispo E-Commerce Platform

**Document version:** 1.0
**Application roles:** Super Admin, Admin
**Customer authentication:** None
**Interface language:** French
**Authorization enforcement:** Laravel backend

---

# 1. Authorization Principles

1. Every protected action must be authorized by Laravel.
2. Vue visibility rules are only a user-interface convenience and are not a security control.
3. Access is denied by default unless explicitly permitted.
4. Back-office accounts must belong to exactly one role:

   * Super Admin
   * Admin
5. Public customers do not have accounts or roles.
6. Sensitive actions must be recorded in the audit log.
7. Orders may be permanently deleted through the guarded bulk order action. The action must preserve its audit record, restore stock for pre-fulfilment orders, and refuse orders with queued, sending, or retrying Meta events.
8. Existing passwords, Meta tokens, and infrastructure secrets must never be displayed.
9. Admin users must not access Meta, user-management, security, or global-configuration endpoints.
10. Authorization should use Laravel policies, gates, middleware, and server-side service checks.

---

# 2. Permission Matrix

| Resource or Action                     |                     Super Admin |                               Admin |
| -------------------------------------- | ------------------------------: | ----------------------------------: |
| Access back office                     |                             Yes |                                 Yes |
| View dashboard                         |                             Yes |                                 Yes |
| View operational order metrics         |                             Yes |                                 Yes |
| View delivered revenue                 |                             Yes |                                 Yes |
| View best-selling products             |                             Yes |                                 Yes |
| View low-stock products                |                             Yes |                                 Yes |
| View recent complaints                 |                             Yes |                                 Yes |
| View Meta event summary                |                             Yes |              Read-only summary only |
| View detailed Meta diagnostics         |                             Yes |                                  No |
| View Meta credentials                  |                     Masked only |                                  No |
| Update Meta Pixel ID                   |                             Yes |                                  No |
| Update Meta CAPI token                 |                             Yes |                                  No |
| Test Meta connection                   |                             Yes |                                  No |
| Enable or disable Meta tracking        |                             Yes |                                  No |
| Change Meta Purchase trigger           |           Yes, protected action |                                  No |
| View products                          |                             Yes |                                 Yes |
| Create products                        |                             Yes |                                 Yes |
| Edit products                          |                             Yes |                                 Yes |
| Activate or deactivate products        |                             Yes |                                 Yes |
| Manage product images                  |                             Yes |                                 Yes |
| Manage variants and combinations       |                             Yes |                                 Yes |
| Manage product stock                   |                             Yes |                                 Yes |
| Manage low-stock thresholds            |                             Yes |                                 Yes |
| Manage product SEO fields              |                             Yes |                                 Yes |
| Delete unused products                 |                             Yes | Yes, subject to safe-deletion rules |
| View categories                        |                             Yes |                                 Yes |
| Create categories                      |                             Yes |                                 Yes |
| Edit categories                        |                             Yes |                                 Yes |
| Reorder categories                     |                             Yes |                                 Yes |
| Activate or deactivate categories      |                             Yes |                                 Yes |
| Remove categories and their catalog products |                        Yes |                                 Yes |
| View orders                            |                             Yes |                                 Yes |
| Search and filter orders               |                             Yes |                                 Yes |
| Export or print orders                 |                             Yes |                                 Yes |
| Edit `Nouvelle` orders                 |                             Yes |                                 Yes |
| Edit `Confirmée` orders                |                             Yes |                                 Yes |
| Edit terminal-status orders            |                              No |                                  No |
| Change order status                    |                             Yes |                                 Yes |
| Add internal order notes               |                             Yes |                                 Yes |
| Permanently delete orders              |                             Yes |                                 Yes |
| View complaints                        |                             Yes |                                 Yes |
| Edit complaint status                  |                             Yes |                                 Yes |
| Add complaint notes                    |                             Yes |                                 Yes |
| Link complaints to orders              |                             Yes |                                 Yes |
| Delete complaint history               |                              No |                                  No |
| Manage promo codes                     |                             Yes |                                  No |
| Show or hide promo-code checkout field |                             Yes |                                  No |
| Manage delivery fee                    |                             Yes |                                  No |
| Manage free-delivery threshold         |                             Yes |                                  No |
| Manage checkout fields                 |                             Yes |                                  No |
| Manage homepage sections               |                             Yes |                                  No |
| Manage hero banners                    |                             Yes |                                  No |
| Manage announcement bar                |                             Yes |                                  No |
| Manage footer content                  |                             Yes |                                  No |
| Manage contact information             |                             Yes |                                  No |
| Manage WhatsApp and social links       |                             Yes |                                  No |
| Manage static pages                    |                             Yes |                                  No |
| Manage policy pages                    |                             Yes |                                  No |
| Manage static-page SEO                 |                             Yes |                                  No |
| View back-office users                 |                             Yes |                                  No |
| Create Admin users                     |                             Yes |                                  No |
| Create Super Admin users               |                             Yes |                                  No |
| Edit users                             |                             Yes |                                  No |
| Activate or deactivate users           |                             Yes |                                  No |
| Reset another user’s password          |                             Yes |                                  No |
| Change another user’s role             |                             Yes |                                  No |
| Delete own active account              |                              No |                                  No |
| Remove the last active Super Admin     |                              No |                                  No |
| View audit logs                        |                             Yes |                                  No |
| Delete audit logs                      |                              No |                                  No |
| Manage security settings               |                             Yes |                                  No |
| View infrastructure secrets            |                              No |                                  No |
| Edit `.env` values from back office    |                              No |                                  No |
| Access server administration           | External deployment access only |                                  No |

---

# 3. Product and Category Deletion Rules

Products and categories must use safe deletion rules.

## 3.1 Products

A product referenced by an existing order must not be permanently deleted. An authorized administrator may still remove it directly from the active catalogue through the product delete control; this is a soft deletion that moves it out of sale while preserving the historical order and inventory records.

It may subsequently be:

* Restored from the archive
* Kept hidden from the storefront
* Permanently deleted only when it has no order-item or inventory history

Historical orders must retain:

* Product name snapshot
* Variant snapshot
* Unit price
* Quantity
* Product image reference where needed

## 3.2 Categories

A category cannot be deleted while products still reference it.

The user must first:

* Reassign the products, or
* Deactivate the category

---

# 4. Order Permissions

## 4.1 Editable Statuses

Super Admin and Admin may edit orders only when the status is:

* `Nouvelle`
* `Confirmée`

Editable values may include:

* Full name
* Phone number
* City
* Address
* Product quantities
* Selected variants
* Eligible custom checkout answers
* Internal notes

## 4.2 Read-Only Statuses

Orders become operationally read-only when their status is:

* `Livrée`
* `Annulée`
* `Échec de livraison`
* `Retournée`

Internal notes may still be appended, but historical order data must not be rewritten.

## 4.3 Allowed Status Transitions

| Current status | Allowed next status |
| -------------- | ------------------- |
| Nouvelle       | Confirmée           |
| Nouvelle       | Annulée             |
| Confirmée      | Livrée              |
| Confirmée      | Échec de livraison  |
| Livrée         | Retournée           |

All other transitions must be rejected by the backend.

---

# 5. User-Management Rules

Only a Super Admin may manage back-office users.

## 5.1 Password Reset

A Super Admin may reset another user’s password without knowing the original password.

The system must:

* Never reveal the old password
* Hash the new password securely
* Require confirmation from the acting Super Admin
* Record the reset in the audit log
* Never log the new password
* Optionally require the affected user to change it at the next login

## 5.2 Last Super Admin Protection

The system must prevent:

* Deactivating the last active Super Admin
* Deleting the last active Super Admin
* Downgrading the last active Super Admin to Admin

## 5.3 Self-Protection Rules

A Super Admin must not accidentally:

* Deactivate their own active session without an explicit protected flow
* Remove their own Super Admin role when they are the final Super Admin
* Delete their own account through normal user-management actions

---

# 6. Protected Meta Actions

The following actions are classified as critical:

* Changing the Meta Pixel ID
* Replacing the CAPI token
* Enabling or disabling Meta tracking
* Changing the Meta Purchase trigger
* Enabling Meta test mode

These actions require:

1. Super Admin authorization
2. Password re-entry
3. A clear warning
4. Final confirmation
5. Audit logging
6. Secret redaction
7. Safe validation before activation where applicable

Changing the Purchase trigger additionally requires a typed confirmation phrase.

---

# 7. Audit Requirements

The following actions must be audited:

* User creation
* User update
* User activation or deactivation
* Password reset
* Role change
* Product creation or update
* Product activation or deactivation
* Stock adjustment
* Category modification
* Order modification
* Order-status transition
* Complaint-status transition
* Promo-code modification
* Delivery-setting change
* Checkout-field modification
* Storefront-content modification
* Static-page modification
* Meta configuration change
* Meta Purchase-trigger change

Audit records should include:

* Acting user ID
* Acting role
* Action
* Resource type
* Resource ID
* Timestamp
* Relevant previous values
* Relevant new values
* Request IP where appropriate

Audit records must never contain:

* Passwords
* Password hashes
* CAPI tokens
* Database credentials
* Redis credentials
* Application keys
* Session cookies

---

# 8. Backend Enforcement Requirements

Codex must implement authorization through:

* Authentication middleware
* Role middleware where appropriate
* Laravel policies for resource-level access
* Gates for global capabilities
* Form Requests for validation
* Service-layer checks for critical workflows
* Database constraints where applicable
* Automated authorization tests

Every protected endpoint must have tests covering:

* Super Admin allowed
* Admin allowed where applicable
* Admin denied where prohibited
* Unauthenticated user denied
* Invalid resource state denied
* Direct API manipulation denied

---

# 9. Frontend Behaviour

The French back-office interface should hide actions the current role cannot perform.

Examples:

* Admin does not see the user-management menu.
* Admin does not see Meta configuration.
* Admin does not see delivery or checkout-field settings.
* Admin does not see audit logs.
* Super Admin sees all permitted sections.

However, hiding an action in Vue does not replace Laravel authorization.

---

# 10. Source of Truth

This document is the authorization source of truth.

When the PRD, design, frontend implementation, or API documentation differs from this matrix, the backend must follow this document until the conflict is explicitly resolved.
