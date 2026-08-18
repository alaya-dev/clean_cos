# ToutDispo — Phase-by-Phase Implementation Plan

**Document version:** 1.0  
**Status:** Execution roadmap  
**Documentation language:** English  
**Application interface language:** French only  
**Architecture:** Laravel modular monolith, Blade storefront with Vue islands, Vue 3 admin SPA  
**Data and infrastructure:** MySQL, Redis, Sentry  
**Deployment:** Deployment-neutral; deployment plans are produced separately by Codex  
**Related documents:**  
- `prd.md`
- `roles-authorization-matrix.md`
- `system-design.md`
- `api-contracts.md`
- `security.md`
- `privacy.md`
- `design.md`
- `quality-rules.md`

## Declared source reading order

Contributors MUST read `AGENTS.md`, then `docs/prd.md`, `docs/roles-authorization-matrix.md`, `docs/system-design.md`, `docs/api-contracts.md`, `docs/security.md`, `docs/privacy.md`, `docs/design.md`, `docs/quality-rules.md`, and finally this implementation plan before creating or implementing a specification.

---

# 1. Purpose

This document defines the exact implementation sequence required to deliver the complete ToutDispo platform.

It is written for Codex and human developers.

The plan is intentionally phased so that:

- Every phase has a clear business outcome.
- Every phase has a testable acceptance gate.
- Security and performance are implemented continuously.
- Storefront and back-office work remain synchronized.
- Database, API, UI, and tests evolve together.
- No phase depends on undocumented assumptions.
- Deployment remains simple and neutral between Docker and non-Docker VPS plans.
- The final product can be released without a large last-minute integration phase.

This is not a calendar estimate. It is an execution order.

---

# 2. Delivery Strategy

## 2.1 Vertical slices

The project must be implemented as vertical slices.

A vertical slice includes, where applicable:

- Database migration
- Model and domain logic
- Policy
- Validation
- Service/action
- API endpoint
- Blade or Vue interface
- Automated tests
- Sentry-safe error handling
- Documentation update

Do not build all database tables first, then all APIs, then all UI.

## 2.2 Phase gates

A phase is complete only when:

- Its acceptance criteria pass.
- Required automated tests pass.
- Security checks pass.
- Static analysis passes.
- No new high or critical dependency issue exists.
- Documentation is updated.
- The feature is usable through the intended interface.
- No unresolved blocker is hidden for a later phase.

## 2.3 Merge strategy

Recommended:

- One feature branch per small deliverable
- One focused pull request per coherent vertical slice
- Human review required
- Main branch always releasable
- No direct production feature work on `main`

## 2.4 No deployment coupling

The application must work identically under:

- Docker deployment
- Non-Docker VPS deployment

Do not put container names, paths, or Docker-specific assumptions inside domain code.

---

# 3. Global Implementation Rules

Every phase must follow these rules.

## 3.1 Read first

Before coding, Codex must read:

1. This implementation plan
2. The relevant source-of-truth documents
3. Existing code in the affected module
4. Existing tests and conventions

## 3.2 Security first

For each change, identify:

- Authentication requirement
- Authorization requirement
- Input validation
- Personal data
- Secret data
- Rate limiting
- Audit requirement
- Idempotency
- Concurrency
- Logging and Sentry redaction

## 3.3 Performance first

For each public feature, identify:

- Server-rendering behavior
- JavaScript cost
- Query count
- Cache behavior
- Image behavior
- Core Web Vitals impact

## 3.4 No invented scope

Codex must not add:

- Customer accounts
- Online payment
- Reviews
- Newsletter collection
- WebSockets
- Live chat
- Multi-vendor behavior
- Multiple languages
- Nested categories
- Per-variant price
- Arbitrary analytics
- Additional roles

## 3.5 Tests in the same change

A feature is not complete if its tests are postponed.

## 3.6 French UI

All visible interface text must be French.

Source code, API fields, tests, and documentation remain English.

---

# 4. Project Phase Overview

| Phase | Name | Main outcome |
|---|---|---|
| 0 | Documentation and Repository Baseline | Stable project rules and repository skeleton |
| 1 | Application Foundation | Laravel, Blade, Vue admin, MySQL, Redis, Sentry, CI-ready application |
| 2 | Identity, Sessions, and Authorization | Secure Admin/Super Admin authentication and access control |
| 3 | Design System and Application Shells | Shared tokens, storefront shell, admin shell, accessible components |
| 4 | Catalog Domain Foundation | Categories, products, prices, stock, variants, images, SEO data |
| 5 | Public Catalog Storefront | Homepage, category, product, search, Ritual Finder |
| 6 | Guest Cart and Authoritative Quote | Persistent cart with server-authoritative totals and stock |
| 7 | Checkout, Orders, and Inventory | Complete COD checkout, order snapshots, stock transactions |
| 8 | Back-Office Catalog and Inventory | Product/category/variant/image/stock management |
| 9 | Back-Office Order Operations | Order lists, detail, edits, transitions, notes, exports |
| 10 | Promotions, Shipping, Checkout Fields, and Content | Owner-managed business and storefront configuration |
| 11 | Complaints | Public complaint submission and back-office workflow |
| 12 | Meta Pixel and Conversions API | Consent-aware, deduplicated, queued Meta Purchase tracking |
| 13 | Dashboard, Audit, and Operational Diagnostics | Metrics, audit logs, Meta diagnostics, low-stock visibility |
| 14 | SEO, Accessibility, and Content Completion | Search-engine readiness and complete public information |
| 15 | Security, Privacy, and Performance Hardening | Production-grade controls and regression validation |
| 16 | End-to-End QA and User Acceptance | Complete business journey validation |
| 17 | Release Readiness and Handover | Deployment-ready product and operational documentation |

---

# Phase 0 — Documentation and Repository Baseline

## 0.1 Objective

Create a stable implementation environment before application code begins.

## 0.2 Inputs

Required documents:

- PRD
- Roles and authorization matrix
- System design
- API contracts
- Security rules
- Privacy rules
- Design system
- Quality rules
- This implementation plan

## 0.3 Deliverables

### Repository structure

Recommended:

```text
/
├── app/
│   ├── Domain/
│   ├── Http/
│   ├── Jobs/
│   ├── Policies/
│   ├── Providers/
│   └── Support/
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── docs/
│   ├── prd.md
│   ├── roles-authorization-matrix.md
│   ├── system-design.md
│   ├── api-contracts.md
│   ├── security.md
│   ├── privacy.md
│   ├── design.md
│   ├── quality-rules.md
│   └── implementation-plan.md
├── resources/
│   ├── css/
│   ├── js/
│   │   ├── admin/
│   │   ├── storefront/
│   │   └── shared/
│   └── views/
├── routes/
├── tests/
│   ├── Feature/
│   ├── Integration/
│   └── Unit/
└── .github/
    └── workflows/
```

Exact structure may follow Laravel conventions while preserving module boundaries.

### Repository controls

- `.gitignore`
- `.editorconfig`
- `SECURITY.md`
- `CONTRIBUTING.md`
- Pull request template
- Issue templates where useful
- Branch protection instructions
- Dependabot configuration
- Secret scanning configuration
- License decision

### Documentation index

Create:

```text
docs/README.md
```

It must explain:

- Document purpose
- Source-of-truth priority
- Which files Codex must read
- How changes to requirements are approved

## 0.4 Tasks

1. Place all approved documents under `docs/`.
2. Ensure filenames and internal references are consistent.
3. Remove unresolved contradictions.
4. Mark unresolved legal owner details as launch blockers.
5. Create ADR directory:
   ```text
   docs/adr/
   ```
6. Add ADR template.
7. Create code ownership or review responsibilities.
8. Define commit and pull request conventions.
9. Define environment names:
   - local
   - test
   - staging
   - production
10. Define synthetic test-data rules.

## 0.5 Tests and checks

- Documentation links valid
- No production secret in repository
- No customer data in repository
- Secret scan clean
- Markdown lint optional but recommended

## 0.6 Acceptance gate

Phase 0 is complete when:

- All documents are committed.
- No major requirement conflict remains.
- Repository policy files exist.
- Main branch protection can be configured.
- Codex has an explicit reading order.
- No application feature coding has begun against incomplete rules.

---

# Phase 1 — Application Foundation

## 1.1 Objective

Create the minimum production-shaped application foundation.

## 1.2 Business outcome

No business feature is delivered yet, but the project can:

- Boot
- Connect to MySQL
- Connect to Redis
- Render a Blade page
- Load a Vue admin shell
- Run queue jobs
- Run scheduled commands
- Report sanitized errors to Sentry
- Execute quality checks

## 1.3 Backend foundation

Implement:

- Laravel application
- Production-compatible configuration
- MySQL connection
- Redis connection
- Redis sessions
- Redis cache
- Redis queues
- Redis rate-limit store
- Queue worker configuration
- Scheduler configuration
- Sentry Laravel integration
- Request ID middleware
- Standard API success/error envelopes
- Global exception rendering
- Health endpoints
- Application timezone handling
- French translation files
- Trusted proxy configuration structure

## 1.4 Frontend foundation

Implement:

### Storefront

- Blade layout
- Vite asset pipeline
- Storefront CSS entry
- Small Vue island mounting system
- French HTML language
- Base SEO tags
- Flash/toast area
- Consent manager placeholder without Meta activation

### Admin

- Vue 3
- TypeScript strict mode
- Vue Router
- Pinia
- Typed API client
- Admin layout placeholder
- Error boundary behavior
- Sentry Vue integration
- French translation structure

## 1.5 Shared foundation

- Design tokens file
- Fonts configured
- Base reset
- Accessibility utilities
- Focus styles
- Icon strategy
- Money formatting helper
- Date formatting helper using `Africa/Tunis`
- Public ID generation strategy
- Clock/time abstraction for tests
- Audit service interface
- Settings service interface

## 1.6 Health endpoints

Recommended:

```text
GET /health/live
GET /health/ready
```

Rules:

- Minimal output
- No versions
- No credentials
- Readiness verifies required services safely
- Rate limited

## 1.7 Sentry foundation

Configure:

- Separate backend/frontend projects or environment separation
- Release ID
- Environment
- `send_default_pii=false`
- `beforeSend` redaction
- No checkout/complaint body capture
- No cookies
- No authorization header
- Test exception in staging only

## 1.8 Quality tooling

Backend:

- Pest/PHPUnit
- Pint
- Larastan/PHPStan
- Composer audit

Frontend:

- ESLint
- TypeScript
- Vitest
- Vue Test Utils
- Playwright
- npm audit

Cross-cutting:

- Secret scanner
- Bundle-size check foundation
- GitHub Actions CI skeleton

## 1.9 Required tests

- Application boot
- MySQL connection
- Redis cache
- Redis queue
- Redis session
- Health liveness
- Health readiness
- Request ID response header
- Standard API error response
- Sentry scrubber unit test
- Storefront Blade smoke
- Admin Vue build
- Strict TypeScript check

## 1.10 Acceptance gate

- Application boots without Docker-specific assumptions.
- A MySQL-compatible database and Redis-compatible service are mandatory in test integration. The approved local baseline is XAMPP MariaDB and Memurai.
- Storefront Blade renders.
- Admin SPA mounts.
- Queue job runs.
- Sentry receives a sanitized synthetic event.
- All quality tools pass.
- CI skeleton passes.

---

# Phase 2 — Identity, Sessions, and Authorization

## 2.1 Objective

Deliver secure back-office authentication and role enforcement.

## 2.2 Business outcome

Admin and Super Admin users can securely access only permitted sections.

## 2.3 Database

Implement:

- `users`
- Role enum or controlled role field
- Active state
- Force-password-change state
- Last-login timestamp
- Authentication version/session revocation support
- Audit records required for user changes

Do not implement public customer users.

## 2.4 Authentication endpoints

Implement according to API contract:

- CSRF cookie
- Login
- Logout
- Current user
- Change own password
- Confirm current password

## 2.5 Authorization

Implement:

- `super_admin`
- `admin`
- Gates/capabilities
- Policies
- Role route middleware
- Last active Super Admin protection
- Self-lockout protection
- Disabled account rejection
- Session revocation after password/role/status changes

## 2.6 User-management endpoints

Super Admin only:

- List users
- Create user
- Update user
- Activate/deactivate
- Change role
- Reset password
- Safe archive/disable

## 2.7 Admin UI

### Login

- French login form
- Generic invalid-credential error
- Password visibility control
- Loading state
- Disabled-account safe message
- Forced password-change route

### User management

- User list
- Create/edit form
- Role/status controls
- Password reset critical dialog
- Last Super Admin error
- Session-expiration behavior

### Role-based navigation

- Admin sees only operational modules
- Super Admin sees all approved modules
- Backend remains authoritative

## 2.8 Security controls

- Redis login throttling
- Secure cookie
- CSRF
- Session rotation
- Idle timeout
- Absolute timeout
- No remember-me persistence initially
- Password hashing
- Minimum password rules
- Audit
- Sentry redaction

## 2.9 Required tests

- Login success
- Generic failure
- Unknown email does not leak
- Disabled account denied
- Rate limit
- Session regenerated
- CSRF enforced
- Admin route access
- Super Admin route access
- Admin denied user management
- Last Super Admin cannot be disabled
- User password reset revokes sessions
- Forced password change
- Audit entry
- No password in logs/Sentry/API

## 2.10 Acceptance gate

- Both roles can authenticate.
- Direct API manipulation cannot bypass permissions.
- User management works for Super Admin.
- Admin cannot access restricted API or UI.
- Security tests pass.

---

# Phase 3 — Design System and Application Shells

## 3.1 Objective

Implement reusable visual foundations before feature pages multiply.

## 3.2 Storefront shell

Implement:

- Announcement bar
- Header
- Desktop navigation
- Mobile navigation drawer
- Search trigger shell
- Cart badge shell
- Main content container
- Footer without newsletter collection
- Privacy preferences entry
- Responsive layout
- SSR navigation

## 3.3 Admin shell

Implement:

- Login shell
- Sidebar
- Top bar
- Page header
- Breadcrumb
- Mobile navigation drawer
- Role-aware navigation
- Main content area
- Session-expiration dialog
- Global toast area

## 3.4 Shared components

Implement:

- Button variants
- Link variants
- Icon button
- Badge
- Status badge
- Chip
- Form field
- Text input
- Textarea
- Checkbox
- Radio
- Toggle
- Select
- Search input
- Money input
- Quantity stepper
- File upload
- Skeleton
- Empty state
- Error state
- Alert
- Toast
- Drawer
- Dialog
- Critical confirmation dialog
- Pagination
- Data table foundation

## 3.5 Motion

Implement design tokens:

- Durations
- Easings
- Reduced motion
- Drawer transition
- Dialog transition
- Toast transition
- Product-card hover
- Button hover

Do not add a heavy motion library.

## 3.6 Accessibility

Test:

- Focus
- Keyboard
- Drawer focus trap
- Dialog focus trap
- Escape
- Focus restoration
- Screen-reader labels
- Color contrast
- Reduced motion

## 3.7 Visual testing

Create component demonstration routes or Storybook-like internal page only if it does not add excessive complexity.

Recommended simple internal development page:

```text
/dev/design-system
```

It must never be accessible in production.

## 3.8 Required tests

- Component rendering
- Keyboard behavior
- Focus trap
- Reduced motion class behavior
- Role navigation visibility
- Responsive screenshot tests for shells
- No horizontal overflow at target widths

## 3.9 Acceptance gate

- Storefront and admin shells match `design.md`.
- Components are reusable.
- No feature page invents local colors or control styles.
- Accessibility basics pass.
- Public shell stays within asset budget.

---

# Phase 4 — Catalog Domain Foundation

## 4.1 Objective

Build the complete catalog model and APIs before public product pages.

## 4.2 Database entities

Implement:

- Categories
- Products
- Product images
- Product option groups
- Product option values
- Product variants
- Variant-option-value pivot
- Slug redirects
- Inventory movement or adjustment records as defined in system design
- SEO fields
- Timestamps
- Soft archive fields where required

## 4.3 Product invariants

- Exactly one subcategory per product
- Two-level category hierarchy: top-level categories contain subcategories; only subcategories contain products
- Product-level regular price
- Product-level promotional price
- Promotional price lower than regular
- Product stock only when no variants
- Variant stock when variants exist
- Required combination uniqueness
- Variant prices inherit product price
- Inactive products hidden publicly
- Historical references preserved
- Slug unique
- Slug change creates redirect
- Non-negative stock

## 4.4 Category APIs

Implement:

- List
- Create
- Read
- Update
- Reorder
- Activate/deactivate
- Safe delete/archive

## 4.5 Product APIs

Implement:

- List
- Create
- Read
- Update
- Activate/deactivate
- Safe archive
- Protected variant-mode switch
- Full variant replacement
- Filters and sorting

## 4.6 Image pipeline

Implement:

- Upload validation
- Private staging
- Re-encoding
- Responsive renditions
- WebP
- Metadata stripping
- Dimensions
- Alt text
- Primary image
- Variant assignment
- Reorder
- Delete after commit
- Queue-based processing

## 4.7 Search foundation

Implement indexed search for:

- Active product names
- Active category names

Do not add external search infrastructure.

## 4.8 Required tests

- Category constraints
- Category in-use deletion conflict
- Product create without variants
- Product create with variants
- Duplicate combination rejected
- Invalid option relationship rejected
- Promotional price validation
- Stock location validation
- Slug redirect
- Archive referenced product
- Image type/size/signature
- Image processing
- Private original behavior
- N+1 prevention
- List filters/sorts
- Authorization for Admin/Super Admin

## 4.9 Acceptance gate

- Catalog APIs match contracts.
- Product invariants are enforced in application and database.
- Image processing is secure and asynchronous.
- Search foundation works.
- No public page yet depends on unfinished admin UI.

---

# Phase 5 — Public Catalog Storefront

## 5.1 Objective

Deliver the complete browsable storefront catalog.

## 5.2 Homepage

Implement server-rendered:

- Announcement bar
- Hero
- Category explorer
- New products
- Custom product sections
- All-products section or catalogue CTA
- Editorial feature
- Ritual Finder
- Footer

Homepage content comes from settings/content records.

## 5.3 Category page

Implement:

- Breadcrumb
- Category title
- Description
- Optional image
- Product count
- Price filter
- Promotion-only filter
- Sort
- Server-rendered product grid
- Crawlable pagination
- Empty state

## 5.4 Shop page

Implement:

- All active products
- Category filter
- Price filter
- Promotion-only filter
- Sort
- Pagination

## 5.5 Product page

Implement:

- Breadcrumb
- Gallery
- Variant selector
- Variant image update
- Name
- Category
- Size metadata
- Prices
- Promotion badge
- Description
- Stock state
- Quantity
- Add-to-cart entry
- Reassurance row
- Related products
- Product structured data
- No star rating

## 5.6 Search

Implement:

- Header search
- Suggestions API
- Product/category suggestions
- Search results page
- Keyboard behavior
- Debounce and cancellation
- Empty state

## 5.7 Ritual Finder

Implement as a small Vue island.

Rules:

- No medical claims
- Choice updates recommendation in place
- Links to approved category/product/section
- Keyboard and live-region support
- Static or managed content model according to system design

## 5.8 Caching

Cache:

- Homepage sections
- Categories
- Product cards
- Product pages where safe
- Search suggestions short-term

Invalidate precisely after relevant admin changes.

## 5.9 Required tests

- Inactive product hidden
- Inactive category hidden
- Product page SSR content
- Variant selection data
- Variant image mapping
- Filters
- Sorting
- Pagination
- Search products/categories
- Redirect from old slug
- Structured data
- Canonical
- Sitemap inclusion later hook
- Cache invalidation
- Accessibility smoke
- Responsive screenshot tests
- Query-count budgets
- Asset budget

## 5.10 Acceptance gate

A visitor can:

- Open the homepage
- Browse categories
- Search
- Open product
- Select variants
- See accurate server-provided prices and availability

No checkout exists yet.

---

# Phase 6 — Guest Cart and Authoritative Quote

## 6.1 Objective

Deliver a production cart that persists locally but is validated by Laravel.

## 6.2 Cart client model

Store only:

- Product public ID
- Variant public ID
- Quantity
- Optional non-authoritative display cache

Do not store customer data.

Expiration:

```text
7 days
```

## 6.3 Cart interactions

Implement:

- Add item
- Increment existing item
- Remove
- Quantity update
- Cart badge
- Cart drawer optional
- Cart page
- Product with variants cannot add without selection
- Multi-variant product card routes to detail

## 6.4 Quote API integration

Use:

```text
POST /api/v1/public/cart/quote
```

Server returns:

- Current item validity
- Current stock
- Current prices
- Product discount
- Promo result
- Shipping
- Total
- Messages
- Checkout eligibility

## 6.5 Cart correction

Handle:

- Product inactive
- Variant missing
- Variant inactive
- Stock reduced
- Product removed
- Price changed
- Promotion ended
- Shipping changed

Use persistent French messages.

## 6.6 Cart page design

Implement:

- Item list
- Quantity stepper
- Remove
- Line totals
- Summary
- COD note
- Free-delivery progress when enabled
- Checkout CTA
- Loading/error state

## 6.7 Required tests

- Local persistence
- Seven-day expiry
- Increment/decrement
- Variant identity
- Quote ignores client prices
- Stock correction
- Product removal
- Shipping threshold exact boundary
- Promo field hidden behavior
- Network retry
- No customer data in local storage
- Accessibility
- Mobile layout

## 6.8 Acceptance gate

- Cart survives refresh.
- All totals are server-authoritative.
- Invalid cart state is corrected clearly.
- No stock is reserved.
- Checkout CTA is enabled only when server quote permits.

---

# Phase 7 — Checkout, Orders, and Inventory

## 7.1 Objective

Deliver the core COD commerce transaction.

## 7.2 Database entities

Implement:

- Orders
- Order items
- Order item option snapshots
- Checkout field snapshots
- Order custom values
- Order status history
- Order internal notes
- Idempotency records
- Promo usage reference hooks
- Inventory movements/restorations
- Attribution snapshot
- Meta trigger snapshot
- Confirmation token or signed URL support

## 7.3 Checkout fields

Default:

- Full name
- Phone
- City free text
- Address

Public checkout fields API returns:

- Active order
- Required state
- Type
- Options
- Schema version
- Promo field visibility

## 7.4 Create order transaction

Implement exact sequence:

1. Validate request
2. Validate schema version
3. Validate idempotency key
4. Normalize customer input
5. Resolve product/variant rows
6. Lock stock rows in deterministic order
7. Recalculate price
8. Validate promo if present
9. Lock promo usage where needed
10. Calculate shipping
11. Create order
12. Create item snapshots
13. Create field snapshots
14. Deduct stock
15. Increment promo usage
16. Create status history
17. Create attribution and Meta eligibility records
18. Commit
19. Dispatch post-commit jobs
20. Return confirmation data

## 7.5 Idempotency

Implement:

- Canonical request fingerprint
- Same key/same payload replay
- Same key/different payload conflict
- Expiry/cleanup
- No double stock
- No double promo usage
- No double order
- No double logical Meta event

## 7.6 Checkout UI

Implement:

- Fields
- Custom fields
- Promo field if visible
- Server quote summary
- Privacy notice
- Consent integration placeholder
- Submit loading
- Error summary
- Price/stock change handling
- Same idempotency key on uncertain retry

## 7.7 Confirmation page

Implement:

- Signed expiring route
- Order reference
- Customer delivery info
- Items and variants
- Pricing
- COD
- Contact expectation
- Home CTA
- Continue shopping CTA
- `noindex`
- Cart cleared only after success

## 7.8 Initial order state

Default:

```text
nouvelle
```

## 7.9 Required tests

- Successful order
- Product without variant
- Product with variant
- Variant required
- Insufficient stock
- Concurrent last-unit purchase
- Price tampering ignored
- Shipping tampering ignored
- Idempotent replay
- Idempotency conflict
- Promo usage once
- Schema version conflict
- Field snapshot
- Product snapshot
- Stock deduction
- No external call inside transaction
- Confirmation signature
- Confirmation expiry
- Cart cleared after success only
- Sentry no PII
- Rate limits
- Abuse controls

## 7.10 Acceptance gate

A customer can complete a real COD order safely.

This is the first complete commerce milestone.

---

# Phase 8 — Back-Office Catalog and Inventory

## 8.1 Objective

Allow Admin and Super Admin to manage the complete catalog without code.

## 8.2 Category UI

Implement:

- List
- Search
- Active filter
- Reorder
- Create
- Edit
- SEO
- Safe delete
- In-use conflict with link to products

## 8.3 Product list UI

Implement:

- Image
- Name
- Category
- Price
- Stock
- Status
- Updated date
- Search
- Filters
- Sort
- Pagination
- Responsive card/table behavior

## 8.4 Product form

Sections:

1. General information
2. Price
3. Images
4. Variants
5. Stock
6. SEO
7. Publication

## 8.5 Image manager

Implement:

- Upload
- Progress
- Processing
- Preview
- Reorder
- Primary
- Alt text
- Variant assignment
- Remove
- Error state

## 8.6 Variant editor

Implement:

- Enable/disable protected flow
- Option groups
- Values
- Combination selection
- SKU optional
- Stock
- Low-stock threshold
- Active
- Image
- Duplicate prevention
- Clear destructive warnings

## 8.7 Inventory UI

Implement:

- Product stock
- Variant stock
- Low stock
- Out of stock
- Audit-visible adjustment path
- No direct unlogged database-like edits

## 8.8 Required tests

- Role access
- Product create/edit
- Variant mode switch
- Variant replacement conflict
- Image assignment
- Low-stock threshold
- Safe archive
- Cache invalidation
- SEO fallback
- Form error behavior
- Unsaved-change warning
- Mobile usability
- Upload security regression

## 8.9 Acceptance gate

The owner can create and manage every catalog scenario in the PRD through the back office.

---

# Phase 9 — Back-Office Order Operations

## 9.1 Objective

Deliver the full operational order workflow.

## 9.2 Order list

Implement:

- Search by reference, name, phone
- Status filter
- Date filter
- Total range
- Promo filter
- Complaint association
- Meta summary filter later hook
- Pagination
- Sorting
- Status badges
- Responsive display

## 9.3 Order detail

Implement:

- Customer
- Delivery
- Items
- Variants
- Pricing
- Checkout values
- Status
- Status history
- Notes
- Complaints hook
- Meta summary hook
- Edit eligibility
- Allowed transitions

## 9.4 Order editing

Only:

- `nouvelle`
- `confirmee`

Editable:

- Name
- Phone
- City
- Address
- Quantity
- Variant
- Eligible custom fields

Rules:

- Lock version
- Re-lock stock
- Recalculate totals
- Reconcile stock
- Audit before/after
- Existing Meta Purchase not rewritten

## 9.5 Status transitions

Implement:

```text
nouvelle -> confirmee
nouvelle -> annulee
confirmee -> livree
confirmee -> echec_livraison
livree -> retournee
```

Rules:

- Reason for exception states
- Restore stock for cancelled/failed
- Return requires restock decision
- Restoration once
- Audit
- Trigger Meta hook later

## 9.6 Internal notes

- Append-only
- Actor/timestamp
- Escaped output
- No edit/delete initially

## 9.7 Print/export

Implement:

- Authenticated printable order
- CSV export
- Bounded filters
- Private file
- Short expiry
- Audit
- Async export if large

## 9.8 Required tests

- Every transition
- Every invalid transition
- Terminal edit denied
- Optimistic conflict
- Stock reconciliation
- Restore once
- Return restock yes/no
- Notes append
- Export authorization
- Export privacy
- Order not hard deleted
- Audit
- Sentry redaction
- Responsive behavior

## 9.9 Acceptance gate

Admin can process the complete order lifecycle without bypassing stock or audit rules.

---

# Phase 10 — Promotions, Shipping, Checkout Fields, and Content

## 10.1 Objective

Give the Super Admin control over store behavior and public content.

## 10.2 Promo codes

Implement:

- Percentage only
- Usage limit
- Usage count
- Active/inactive
- Optional dates
- Optional minimum subtotal
- Checkout-only
- No per-customer limit
- Safe concurrent usage
- Archive when referenced

UI:

- List
- Create/edit
- Usage display
- Exhausted state
- Activate/deactivate

## 10.3 Shipping settings

Implement:

- Fixed delivery fee
- Enable free threshold
- Threshold amount
- Dynamic preview
- Cache invalidation
- Audit

Use settings everywhere:

- Announcement
- Cart
- Checkout
- Confirmation
- Policy content where dynamic token support exists

## 10.4 Checkout fields

Implement:

- List
- Reorder
- Add custom
- Edit
- Activate/deactivate
- Required/optional
- Types
- Options
- System-field protection
- Live preview
- Schema version change

## 10.5 Store settings

Implement:

- Phone
- Email
- Address
- WhatsApp
- Social links
- Announcement
- Footer content
- Reassurance messages if approved

## 10.6 Homepage sections

Implement:

- Default sections
- Custom sections
- Product selection
- Reorder
- Active/inactive
- Filter enabled
- Preview

Avoid a free-form builder.

## 10.7 Banners

Implement:

- Desktop image
- Mobile image
- Link
- Status
- Reorder
- Secure upload
- Preview

## 10.8 Static pages

Implement:

- About
- Contact
- Terms
- Privacy
- Delivery
- Returns/complaints
- FAQ

Include:

- Sanitized content
- Slug
- SEO
- Active
- Redirect on slug change

## 10.9 Required tests

- Promo race
- Promo exhausted
- Hidden promo field
- Shipping exact threshold
- Setting cache invalidation
- Checkout schema version update
- Historical field snapshots unchanged
- System fields protected
- HTML sanitization
- Banner upload
- Redirect
- Role denial for Admin
- Audit

## 10.10 Acceptance gate

Super Admin can manage approved business settings and public content without code deployment.

---

# Phase 11 — Complaints

## 11.1 Objective

Deliver public complaint submission and internal resolution.

## 11.2 Public form

Implement:

- Name
- Phone
- Optional order reference
- Subject
- Description
- Optional image
- Consent
- Honeypot
- Rate limit
- Generic safe response

## 11.3 Attachment

- Images only
- Private storage
- Signature validation
- Re-encode
- Strip metadata
- Size/pixel limit
- Authorized download only

## 11.4 Back-office list/detail

Implement:

- Search
- Status filter
- Order filter
- Date filter
- Attachment indicator
- Detail
- Link to order
- Notes
- Private preview/download
- Timeline

## 11.5 Workflow

```text
nouvelle -> en_cours -> resolue
```

## 11.6 Required tests

- Public validation
- Consent
- Honeypot
- Rate limit
- Order existence not leaked
- Private attachment
- Wrong MIME
- Oversize
- XSS
- Status transition
- Link order
- Role access
- Sentry no complaint body
- Audit

## 11.7 Acceptance gate

Customers can submit complaints safely, and staff can manage them without exposing private files.

---

# Phase 12 — Meta Pixel and Conversions API

## 12.1 Objective

Deliver reliable, consent-aware Meta event tracking with browser/server deduplication.

## 12.2 Consent manager

Complete:

- Accept all
- Refuse all
- Manage
- Advertising category
- Version
- Timestamp
- Withdrawal
- Footer preferences link
- Store remains usable after refusal

## 12.3 Public browser events

Implement approved events:

- PageView
- ViewContent
- Search
- AddToCart
- InitiateCheckout
- Purchase when trigger is `nouvelle`

Only after consent.

## 12.4 Attribution capture

Capture safely:

- `_fbp`
- `_fbc`
- Landing URL
- Referrer
- UTM
- IP server-side
- User agent server-side
- Consent
- Submission time

## 12.5 Meta configuration

Super Admin UI and API:

- Pixel ID
- CAPI token
- Tracking enabled
- Test mode
- Test-event code
- Purchase trigger
- Configuration version
- Masking

## 12.6 Test and activation

Implement:

1. Proposed values
2. Safe test event
3. Test record
4. Password confirmation
5. Phrase
6. Expected version
7. Atomic activation
8. Encrypted token
9. Audit

## 12.7 Purchase event creation

At order creation:

- Snapshot trigger
- Create logical event only when trigger condition is met
- Same event ID for browser/server
- One event per order
- Queue server event after commit

At later transition:

- If snapshot trigger reached
- Create server Purchase
- Customer revisit not required

## 12.8 Queue and retry

Implement:

- `meta` queue
- Timeouts
- Bounded exponential backoff
- Transient/permanent classification
- Attempt history
- Sanitized errors
- Manual retry
- Idempotent event ID

## 12.9 Diagnostics

Super Admin:

- Event list
- Event detail
- Attempts
- Status
- Order
- Safe error
- Retry
- Diagnostics summary

Admin:

- Summary counts only

## 12.10 Required tests

- Consent refused
- Consent accepted
- Withdrawal
- Pixel ID public only
- Token encrypted/masked
- Token absent from API/log/Sentry
- Test does not activate
- Activation requires matching successful test
- Trigger change protection
- Trigger snapshot
- Browser/server same event ID
- One Purchase
- Retry same event ID
- Permanent failure
- Later trigger transition
- No resend after trigger change
- No Meta network call in checkout transaction
- Sentry redaction

## 12.11 Acceptance gate

Meta Test Events confirms browser/server behavior, and the application proves one logical Purchase maximum per order.

No claim of exact equality with Meta Ads Manager is made.

---

# Phase 13 — Dashboard, Audit, and Operational Diagnostics

## 13.1 Objective

Provide useful operational visibility without adding another monitoring platform.

## 13.2 Dashboard

Implement date filters:

- Today
- Last 7 days
- Last 30 days
- Current month
- Custom

Metrics:

- Order counts by status
- Delivered revenue
- Average delivered order
- Best sellers
- Low stock
- Recent complaints
- Meta event summary

## 13.3 Audit log

Implement:

- Append-only records
- List
- Filters
- Detail
- Safe before/after diff
- No update/delete
- Super Admin only
- Secret/PII minimization

## 13.4 Operational health

Expose in admin only where useful:

- Failed queue jobs count
- Meta permanent failures
- Last successful backup indicator if deployment plan supplies it
- Scheduler heartbeat
- Storage/image processing failures

Do not build a second monitoring system.

Sentry remains the external monitoring platform.

## 13.5 Required tests

- Delivered revenue only
- Date timezone
- Dashboard authorization
- Query performance
- Audit immutable
- Secret redaction
- Meta detail Super Admin only
- Admin summary only
- Empty states
- Mobile dashboard

## 13.6 Acceptance gate

The owner can understand sales operations, stock, complaints, and Meta delivery from the back office.

---

# Phase 14 — SEO, Accessibility, and Content Completion

## 14.1 Objective

Complete all public discovery, accessibility, and policy requirements.

## 14.2 SEO

Implement:

- Product metadata
- Category metadata
- Static-page metadata
- Canonical URLs
- Open Graph
- Product JSON-LD
- Breadcrumb JSON-LD
- Organization/LocalBusiness after owner data
- Sitemap
- Robots
- Slug redirects
- Noindex:
  - cart
  - checkout
  - confirmation
  - admin
  - internal search where approved

## 14.3 Accessibility review

Manual and automated review:

- Keyboard
- Focus
- Screen reader
- Labels
- Errors
- Drawers
- Dialogs
- Tables
- Filters
- Upload
- Variant selector
- Cart
- Checkout
- Consent manager
- Reduced motion
- Contrast

## 14.4 Content completion

Owner must provide:

- Legal business name
- Address
- Contact
- Social links
- Policies
- FAQ
- Hero text
- Banners
- Category descriptions
- Product content
- Reassurance statements
- Ritual Finder content

## 14.5 Required tests

- Rendered HTML
- Title/description
- Canonical
- Sitemap excludes inactive
- Redirect
- Structured data schema validation
- Noindex
- Axe automated tests
- Keyboard Playwright flows
- Contrast review
- French missing translation check

## 14.6 Acceptance gate

Public pages are indexable where intended, accessible, and contain no unresolved launch placeholders.

---

# Phase 15 — Security, Privacy, and Performance Hardening

## 15.1 Objective

Validate the complete system against the mandatory quality baseline.

## 15.2 Security hardening

Verify:

- HTTPS assumptions
- Headers
- CSP report-only then enforce
- Secure cookies
- CSRF
- CORS
- Rate limits
- Upload security
- SQL injection controls
- XSS controls
- SSRF controls
- Open redirect
- Path traversal
- Debug disabled
- Service privacy
- Secret scanning
- Dependency audit
- Admin noindex
- Sentry scrub
- Audit behavior

## 15.3 Privacy hardening

Verify:

- Meta blocked before consent
- Withdrawal
- Public French privacy page
- Data inventory
- Retention jobs
- Sentry no PII
- Export expiry
- Private files
- No PII in URLs
- Legal placeholders resolved
- INPDP/transfer launch checklist completed by owner

## 15.4 Performance hardening

Measure:

- LCP
- INP
- CLS
- Server response
- Query counts
- Bundle sizes
- Image sizes
- Cache hit behavior
- Admin route splitting
- Checkout response
- Queue latency

Optimize:

- Queries
- Indexes
- Eager loading
- Caches
- Images
- JavaScript
- Fonts
- Critical CSS where justified

## 15.5 DAST and manual tests

Run on staging:

- OWASP ZAP baseline
- Header scan
- TLS scan
- Authorization manipulation
- Cart/price manipulation
- Stock race
- File upload abuse
- Complaint abuse
- Login brute-force controls
- Meta secret inspection
- Sentry PII inspection

## 15.6 Required deliverables

- Security checklist
- Privacy checklist
- Performance report
- Query review
- Bundle report
- Sentry redaction evidence
- Dependency audit
- DAST report
- Resolved findings or documented exceptions

## 15.7 Acceptance gate

No unresolved:

- Critical security issue
- High exploitable security issue
- PII leak
- Stock integrity issue
- Duplicate order issue
- Duplicate Meta Purchase issue
- Core Web Vital critical regression
- Legal/privacy launch blocker

---

# Phase 16 — End-to-End QA and User Acceptance

## 16.1 Objective

Validate the product as a complete business system.

## 16.2 Test environments

Use:

- Staging
- Production-like MySQL
- Production-like Redis
- Synthetic products/orders/customers
- Meta test mode
- Sentry staging environment

## 16.3 Customer journeys

Test:

1. Browse homepage
2. Open category
3. Search
4. Open product without variants
5. Open product with variants
6. Add cart
7. Change quantity
8. Stock correction
9. Promo code
10. Free shipping
11. Consent accept/refuse
12. Checkout
13. Confirmation
14. Complaint

## 16.4 Admin journeys

Test:

1. Login
2. Forced password change
3. Create category
4. Create product
5. Add variants
6. Upload images
7. Activate product
8. Receive order
9. Edit new order
10. Confirm order
11. Deliver order
12. Cancel order
13. Failed delivery
14. Return with and without restock
15. Complaint handling
16. Promo management
17. Shipping setting
18. Homepage section
19. Static policy
20. Meta test/activation
21. Meta diagnostic
22. User reset
23. Audit review

## 16.5 Role testing

Repeat restricted journeys as Admin.

Verify no access to:

- Users
- Meta configuration
- Shipping/global settings
- Checkout fields
- Content management
- Audit logs

## 16.6 Device testing

- 360 px mobile
- 390 px mobile
- Tablet
- 1024 px
- 1280 px
- 1440 px

Browsers:

- Chrome
- Safari
- Firefox
- Edge
- Mobile Safari
- Mobile Chrome

## 16.7 Failure testing

Simulate:

- Redis temporary failure
- Meta timeout
- Sentry unavailable
- Image job failure
- Queue retry
- Stale order version
- Stock conflict
- Expired confirmation token
- Session expiry
- Invalid upload
- Database deadlock retry behavior where implemented

## 16.8 Acceptance gate

The owner or assigned tester signs off:

- Storefront
- Checkout
- Product management
- Order operations
- Complaints
- Content
- Meta
- Mobile
- French copy
- Policies

All blocking defects are closed.

---

# Phase 17 — Release Readiness and Handover

## 17.1 Objective

Prepare the complete application for either approved deployment plan.

This phase does not define the Docker or non-Docker deployment procedure. Codex will provide those separately.

## 17.2 Deployment-neutral release deliverables

- Production build commands
- Required PHP extensions
- Required Node version for build
- Required MySQL version range
- Required Redis version range
- Environment variable reference
- Queue names
- Scheduler commands
- Storage paths
- Health endpoints
- Migration commands
- Cache commands
- Rollback constraints
- Sentry release commands
- Backup scope
- Seed procedure for first Super Admin
- Post-deploy smoke-test command

## 17.3 Environment reference

Create:

```text
docs/environment-reference.md
```

It lists:

- Variable name
- Purpose
- Secret/public
- Required/optional
- Example placeholder
- Docker/non-Docker relevance

Never include real secret values.

## 17.4 Operational runbooks

Create:

```text
docs/runbooks/
```

Minimum runbooks:

- Create first Super Admin
- Reset Admin password
- Rotate Meta token
- Disable Meta tracking
- Handle failed Meta events
- Restore order stock safely
- Investigate queue failure
- Handle Sentry incident
- Restore backup
- Put site in maintenance mode
- Roll back release
- Rebuild image derivatives
- Revoke user sessions

## 17.5 Handover package

- Source repository
- Approved documents
- Environment reference
- Runbooks
- Test reports
- Security report
- Performance report
- Backup restore evidence
- Sentry access instructions
- Meta configuration instructions
- Owner content checklist
- Known limitations
- Future roadmap

## 17.6 Final production checklist

### Business

- [ ] Products loaded
- [ ] Categories loaded
- [ ] Prices verified
- [ ] Stock verified
- [ ] Shipping verified
- [ ] Policies published
- [ ] Contact data correct
- [ ] Meta decision approved
- [ ] Admin users correct

### Technical

- [ ] CI green
- [ ] Production build
- [ ] Migrations tested
- [ ] Redis working
- [ ] Queue working
- [ ] Scheduler working
- [ ] Sentry working
- [ ] Images optimized
- [ ] Health endpoints
- [ ] Backup configured
- [ ] Restore tested

### Security/privacy

- [ ] HTTPS
- [ ] Headers
- [ ] Debug disabled
- [ ] Secrets protected
- [ ] Rate limits
- [ ] Consent
- [ ] Privacy notice
- [ ] Transfer/legal checks
- [ ] Sentry PII test
- [ ] Meta token masked
- [ ] Admin access reviewed

### UX

- [ ] Mobile
- [ ] Desktop
- [ ] Checkout
- [ ] Confirmation
- [ ] Complaint
- [ ] Back office
- [ ] Accessibility
- [ ] French copy

## 17.7 Acceptance gate

The application is ready for the selected deployment plan and owner handover.

---

# 5. Recommended Pull Request Breakdown

The phases above are too large for one pull request each.

Use smaller PRs.

## Phase 1 example

```text
PR 1.1 Laravel/MySQL/Redis foundation
PR 1.2 Error envelopes and request IDs
PR 1.3 Blade storefront shell
PR 1.4 Vue admin foundation
PR 1.5 Sentry and redaction
PR 1.6 Quality tooling and CI
```

## Phase 2 example

```text
PR 2.1 User model and roles
PR 2.2 Login/session endpoints
PR 2.3 Authorization policies
PR 2.4 Admin login UI
PR 2.5 User management
PR 2.6 Session revocation and security tests
```

## Phase 4 example

```text
PR 4.1 Categories
PR 4.2 Products without variants
PR 4.3 Variant domain
PR 4.4 Secure image upload
PR 4.5 SEO and redirects
PR 4.6 Catalog filters/search foundation
```

## Phase 7 example

```text
PR 7.1 Order schema and snapshots
PR 7.2 Checkout fields API
PR 7.3 Quote-to-checkout UI
PR 7.4 Transactional order creation
PR 7.5 Idempotency
PR 7.6 Confirmation page
PR 7.7 Concurrency and abuse tests
```

## Phase 12 example

```text
PR 12.1 Consent manager
PR 12.2 Attribution snapshot
PR 12.3 Meta configuration
PR 12.4 Connection test and activation
PR 12.5 Purchase event creation
PR 12.6 Queue/retries
PR 12.7 Diagnostics
PR 12.8 Meta security and privacy tests
```

---

# 6. Required Test Layers by Phase

| Phase | Unit | Feature/API | Integration | Component | Browser | Security | Performance |
|---|---:|---:|---:|---:|---:|---:|---:|
| 0 | No | No | No | No | No | Repository | No |
| 1 | Yes | Yes | Yes | Yes | Smoke | Yes | Build |
| 2 | Yes | Yes | Redis session | Yes | Login | Yes | No |
| 3 | Yes | No | No | Yes | Shells | Accessibility | Asset |
| 4 | Yes | Yes | MySQL/storage/queue | No | No | Upload/auth | Query |
| 5 | Yes | Yes | Cache | Yes | Storefront | XSS | CWV/query |
| 6 | Yes | Yes | Redis/cache | Yes | Cart | Tamper | Quote |
| 7 | Yes | Yes | MySQL concurrency | Yes | Checkout | Abuse | Checkout |
| 8 | Yes | Yes | Storage/cache | Yes | Admin catalog | Upload/auth | Admin |
| 9 | Yes | Yes | MySQL locks/export | Yes | Orders | Auth/data | Query |
| 10 | Yes | Yes | Cache/concurrency | Yes | Settings | Sanitizer | Cache |
| 11 | Yes | Yes | Private storage | Yes | Complaint | Upload/abuse | No |
| 12 | Yes | Yes | Queue/HTTP fake | Yes | Consent/Meta | Secret/privacy | Queue |
| 13 | Yes | Yes | Aggregates | Yes | Dashboard | Audit | Query |
| 14 | Yes | Yes | HTML | Yes | A11y | Privacy | Lighthouse |
| 15 | Yes | Yes | Full | Yes | Full | DAST | Full |
| 16 | No new logic | Full | Full | Full | Full | Full | Full |
| 17 | Smoke | Smoke | Restore | No | Smoke | Checklist | Smoke |

---

# 7. Phase Completion Report Template

At the end of every phase, Codex should produce:

```markdown
# Phase X Completion Report

## Delivered
- ...

## Documents followed
- ...

## Database changes
- ...

## API changes
- ...

## UI changes
- ...

## Security controls
- ...

## Privacy controls
- ...

## Performance considerations
- ...

## Tests executed
- Command:
- Result:

## Known limitations
- ...

## Deferred items
- ...

## Acceptance criteria
- [x] ...
- [ ] ...

## Recommended next phase
- ...
```

Codex must not mark a phase complete when acceptance items remain unchecked.

---

# 8. Recommended Codex Prompt Pattern

Use one focused prompt per PR-sized slice.

```text
Implement PR <number> for ToutDispo.

Read first:
- docs/<relevant files>

Scope:
- <exact deliverable>

Required:
- Follow API contracts
- Follow security rules
- Follow design tokens
- Add tests
- Run quality commands
- Update documentation if behavior changes

Do not:
- Add unrelated features
- Change approved business rules
- Weaken security or types
- Introduce deployment-specific domain behavior

At the end, provide:
- Files changed
- Decisions
- Commands run and results
- Remaining issues
```

---

# 9. Critical Dependency Order

The following order must not be reversed without an ADR.

```text
Foundation
  -> Authentication
  -> Design system
  -> Catalog domain
  -> Public catalog
  -> Cart
  -> Checkout/orders
  -> Admin catalog
  -> Admin orders
  -> Settings/content/promotions
  -> Complaints
  -> Meta
  -> Dashboard/audit
  -> SEO/accessibility
  -> Hardening
  -> UAT
  -> Release
```

Examples:

- Do not implement Meta before stable order creation and transitions.
- Do not implement checkout before authoritative cart quote.
- Do not implement admin product forms before catalog invariants.
- Do not implement dashboard revenue before order statuses.
- Do not enable consent-based Meta tracking before privacy behavior exists.

---

# 10. Final Product Acceptance Criteria

The final product is delivered only when all conditions pass.

## Storefront

- [ ] Mobile-first
- [ ] Server-rendered
- [ ] Homepage complete
- [ ] Categories complete
- [ ] Search complete
- [ ] Product pages complete
- [ ] Variants complete
- [ ] Cart complete
- [ ] Checkout complete
- [ ] Confirmation complete
- [ ] Complaints complete
- [ ] Policies complete
- [ ] Consent complete
- [ ] SEO complete
- [ ] Accessibility complete

## Back office

- [ ] Secure login
- [ ] Role separation
- [ ] Dashboard
- [ ] Products
- [ ] Categories
- [ ] Variants
- [ ] Images
- [ ] Stock
- [ ] Orders
- [ ] Transitions
- [ ] Complaints
- [ ] Promotions
- [ ] Shipping
- [ ] Checkout fields
- [ ] Content
- [ ] Users
- [ ] Meta
- [ ] Audit

## Data integrity

- [ ] Server prices
- [ ] Integer millimes
- [ ] Transactional stock
- [ ] Idempotent checkout
- [ ] Promo concurrency
- [ ] Order snapshots
- [ ] Valid transitions
- [ ] Restore once
- [ ] One Meta Purchase

## Security and privacy

- [ ] OWASP controls
- [ ] API authorization
- [ ] Secure sessions
- [ ] Rate limits
- [ ] Safe uploads
- [ ] Secret encryption
- [ ] Sentry redaction
- [ ] Meta consent
- [ ] Private complaints
- [ ] Backups
- [ ] Legal privacy checklist

## Quality

- [ ] CI green
- [ ] Static analysis
- [ ] Coverage thresholds
- [ ] Browser tests
- [ ] Security tests
- [ ] Performance budgets
- [ ] No high/critical exploitable dependency issue
- [ ] Documentation complete
- [ ] Runbooks complete

---

# 11. Future Enhancements Outside Initial Delivery

Do not include these in the initial implementation unless the PRD is revised.

- Customer accounts
- Customer order tracking portal
- Online payments
- Newsletter
- Email/SMS/WhatsApp order notifications
- Product reviews
- Wishlist
- Multiple languages
- Multiple currencies
- Courier integration
- Inventory warehouses
- Loyalty program
- TOTP MFA
- WebAuthn
- Advanced fraud scoring
- Advanced analytics
- External search engine
- Mobile app
- Multi-vendor features

---

# 12. Source of Truth

This file is the source of truth for implementation sequence.

When Codex proposes a different order, it must explain:

- Why
- Which dependency changes
- Which risks are introduced
- Which document requires an ADR
- How acceptance gates remain protected

No phase may be skipped only because a feature appears simple.

# 18 phases, but execute them in 7 delivery milestones


## Milestone 1 — Foundation
Laravel, MySQL, Redis
Blade and Vue setup
Sentry
CI
Authentication and roles
Design tokens and application shells
## Milestone 2 — Catalogue
Categories
Products
Variants
Images
Stock
Public homepage, listings, search, and product pages
## Milestone 3 — Commerce core
Cart
Authoritative quotation
Checkout fields
Transactional order creation
Idempotency
Stock locking
Confirmation page
## Milestone 4 — Operations
Admin product management
Inventory management
Orders
Order editing
Status transitions
Stock restoration
Print and export
## Milestone 5 — Store management
Promotions
Shipping settings
Checkout-field configuration
Homepage sections
Banners
Static pages
Complaints
## Milestone 6 — Tracking and visibility
Consent manager
Meta Pixel
CAPI
Deduplication
Retry handling
Dashboard
Audit logs
Diagnostics
## Milestone 7 — Release quality
SEO
Accessibility
Security hardening
Performance testing
End-to-end tests
User acceptance
Deployment readiness

Do not begin a later milestone when its dependency is unstable.
