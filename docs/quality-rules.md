# Quality Rules

## ToutDispo E-Commerce Platform

**Document version:** 1.0  
**Status:** Mandatory engineering and release baseline  
**Documentation language:** English  
**Application UI language:** French only  
**Architecture:** Laravel modular monolith, Blade storefront with Vue islands, Vue 3 admin SPA  
**Monitoring:** Sentry only  
**Deployment:** Deployment-neutral; Docker and non-Docker VPS plans supported  
**Related documents:** `prd.md`, `roles-authorization-matrix.md`, `system-design.md`, `api-contracts.md`, `security.md`, `privacy.md`

---

# 1. Purpose

This document defines the quality standards that Codex and human developers must follow.

Quality means more than code formatting. A change is acceptable only when it is:

- Correct
- Secure
- Performant
- Testable
- Maintainable
- Accessible
- SEO-safe
- Observable
- Deployment-neutral
- Consistent with the approved documents

Security, performance, and data integrity have priority over development speed.

---

# 2. Rule Priority

| Level | Meaning |
|---|---|
| **MUST** | Required; failure blocks merge or release |
| **SHOULD** | Expected; deviation needs a documented reason |
| **MAY** | Optional improvement |

---

# 3. Source-of-Truth Order

When documents conflict, use:

1. Approved architecture or security exception
2. `security.md`
3. `privacy.md`
4. `roles-authorization-matrix.md`
5. `api-contracts.md`
6. `system-design.md`
7. `prd.md`
8. `design.md`
9. Current implementation

Codex must report a conflict instead of silently choosing one interpretation.

---

# 4. Architectural Quality Rules

The implementation MUST remain:

- A modular Laravel monolith
- MySQL as the durable business source of truth
- Redis for cache, sessions, rate limits, locks, and queues
- Blade-rendered public storefront
- Vue 3 and TypeScript only for public interactive islands
- Vue 3 and TypeScript for the private admin SPA
- Sentry as the only external application-monitoring platform
- REST/JSON under `/api/v1`
- Deployable with Docker or without Docker
- Free of mandatory Node SSR runtime in production
- Free of real-time WebSockets and polling loops

Do not introduce:

- Microservices
- Laravel Reverb
- Pusher
- Socket.IO
- GraphQL
- Elasticsearch
- Kubernetes
- A second monitoring platform
- A client-rendered-only storefront
- Customer authentication
- Online payments

unless the approved documents are intentionally revised.

---

# 5. Module Boundaries

Recommended domain modules:

```text
Catalog
Content
Checkout
Orders
Inventory
Promotions
Complaints
MetaTracking
IdentityAccess
Settings
Audit
Shared
```

Each module should contain its own:

- Actions or services
- Data-transfer objects where useful
- Policies
- Form Requests
- Models
- Events and listeners
- Jobs
- Tests

Rules:

- Controllers remain thin.
- Vue components do not contain business rules.
- Models do not become unbounded god objects.
- Cross-module writes go through approved application services.
- Circular module dependencies are prohibited.
- `Shared` must contain only genuinely shared infrastructure or value objects.

---

# 6. Backend Coding Standards

## 6.1 PHP

Use:

- Strict types in project-owned PHP files where compatible
- Typed properties
- Return types
- Constructor property promotion where clear
- Native enums for stable domain states
- Readonly value objects where useful
- Final classes by default for actions/services that are not designed for inheritance
- Dependency injection through constructors
- Laravel contracts for replaceable infrastructure

Avoid:

- Mixed return types without justification
- Dynamic properties
- Large associative arrays crossing multiple layers
- Static service locators
- Hidden global state
- Business logic in route closures
- Catching `Throwable` only to ignore it
- Boolean parameters whose meaning is unclear

## 6.2 Formatting

Laravel Pint is mandatory.

CI command:

```text
./vendor/bin/pint --test
```

No formatting-only debate should block functional review once the formatter passes.

## 6.3 Static analysis

Larastan/PHPStan is mandatory.

Target:

```text
Level 8 or the highest stable level supported by the selected Laravel stack
```

Rules:

- Baseline files may be used only during initial migration.
- Every new change must not add baseline entries.
- Baseline entries require an issue and owner.
- Do not suppress errors with broad ignores.
- Use precise PHPDoc for collections, generics, and arrays.
- Avoid `@phpstan-ignore-next-line` unless a comment explains the framework limitation.

## 6.4 Exceptions

Use domain-specific exceptions for expected business conflicts.

Examples:

- `InsufficientStock`
- `InvalidOrderTransition`
- `OrderVersionConflict`
- `PromoCodeUnavailable`
- `MetaConfigurationInvalid`

Do not use exceptions for ordinary collection filtering.

Unexpected exceptions go to Sentry after redaction.

## 6.5 Logging

Use structured context.

Good:

```php
logger()->warning('Meta event retry scheduled', [
    'meta_event_id' => $event->public_id,
    'attempt' => $attempt,
    'request_id' => $requestId,
]);
```

Never log entire models or request payloads containing personal data.

---

# 7. Laravel Quality Rules

## 7.1 Controllers

A controller should:

1. Receive a validated request
2. Authorize the operation
3. Call one action/service
4. Return an API Resource or response

Controllers must not:

- Calculate prices
- Mutate stock directly
- Build Meta payloads
- Implement state machines
- Call multiple repositories in an uncontrolled transaction
- Return Eloquent models directly

## 7.2 Form Requests

Every mutation uses a dedicated Form Request.

Form Requests define:

- Validation
- Normalization when appropriate
- Authorization only when resource context is simple
- French validation messages or translation keys

Complex business authorization remains in Policies/actions.

## 7.3 API Resources

API responses use dedicated API Resources.

Resources must:

- Match `api-contracts.md`
- Expose public identifiers
- Hide internal IDs
- Hide secret and sensitive attributes
- Avoid lazy-loading queries

Enable prevention of lazy loading in non-production and tests.

## 7.4 Policies and Gates

Every protected resource action uses a Policy or Gate.

No controller may depend only on frontend menu visibility.

## 7.5 Actions and services

Critical operations use a single explicit entry point.

Examples:

```text
QuoteCartAction
CreateGuestOrderAction
UpdateEditableOrderAction
TransitionOrderStatusAction
RestoreOrderStockAction
ActivateMetaConfigurationAction
CreateMetaPurchaseEventAction
```

Each action has:

- Clear input DTO
- Clear result
- Transaction boundary
- Domain exceptions
- Unit or feature tests

## 7.6 Events and jobs

Domain events describe completed facts.

Jobs perform asynchronous work.

Do not use events to hide critical synchronous business logic.

External work runs after database commit.

## 7.7 Configuration

Use typed configuration access through Laravel config files.

Do not call `env()` outside config files.

Admin-editable settings must use an allow-listed settings service.

---

# 8. Database Quality Rules

## 8.1 Migrations

Every migration must:

- Have a descriptive name
- Use explicit column types
- Add required indexes and constraints
- Avoid silent data loss
- Be tested on MySQL
- Include a safe rollback when practical
- Document irreversible changes

Do not use SQLite as the only database in CI because stock locks, constraints, and MySQL behavior are critical.

## 8.2 Money

All TND values use integer millimes.

Never use:

- Float
- Double
- JavaScript floating-point calculation as authority

Formatting is separate from calculation.

## 8.3 Foreign keys and constraints

Use database enforcement for:

- Relationships
- Unique email
- Unique slug where required
- Unique idempotency key
- Unique logical Meta Purchase per order
- Non-negative stock
- Valid promo percentage
- Unique variant combinations

Application validation does not replace constraints.

## 8.4 Indexes

Indexes must support actual query patterns.

Before release, use `EXPLAIN` for:

- Homepage product sections
- Category listing
- Search suggestions
- Product detail
- Order list filters
- Dashboard status/date aggregates
- Complaint list
- Meta diagnostic list

Do not add unused indexes blindly because writes and storage also have costs.

## 8.5 N+1 prevention

Tests or development configuration must detect lazy loading.

Public listing pages and admin tables require reviewed eager loading.

## 8.6 Transactions

Transactions are mandatory for:

- Guest checkout
- Promo-code usage
- Stock deduction
- Order edit
- Order transition
- Stock restoration
- Meta configuration activation
- User role changes affecting last Super Admin protection

No external HTTP call inside a database transaction.

## 8.7 Concurrency

Use:

- Row locks for stock and promo limits
- Deterministic lock ordering
- Optimistic `lock_version` for editable orders
- Unique constraints for idempotency
- Idempotent restoration records

Concurrency tests must run against MySQL.

---

# 9. API Quality Rules

## 9.1 Contract compliance

Every endpoint must match `api-contracts.md`.

Changes to:

- Route
- Method
- Field name
- Type
- Enum
- Error code
- Status code
- Authorization

require updating the contract and tests in the same pull request.

## 9.2 Versioning

All JSON endpoints use `/api/v1`.

Do not introduce unversioned production APIs.

## 9.3 Response consistency

Use the approved:

- Success envelope
- Collection envelope
- Error envelope
- Validation envelope
- Request ID

## 9.4 Error codes

Frontend behavior depends on stable machine error codes, not French message text.

Do not reuse one error code for unrelated conditions.

## 9.5 Pagination

All unbounded collections paginate.

Maximum `per_page`:

```text
100
```

## 9.6 Filtering and sorting

Allow-list every filter and sort field.

Reject unknown values with `422`.

## 9.7 Idempotency

Guest order creation requires `Idempotency-Key`.

Tests must prove:

- Same key and same payload returns same order
- Same key and different payload returns `409`
- Stock changes once
- Promo usage changes once
- Meta event is created once

## 9.8 OpenAPI

Generate OpenAPI 3.1 after endpoint stabilization.

The generated file must not contradict the handwritten contract.

Recommended location:

```text
docs/openapi/api-v1.yaml
```

---

# 10. Frontend TypeScript Rules

## 10.1 Compiler

Use strict TypeScript.

Required:

```text
strict: true
noImplicitAny: true
noUncheckedIndexedAccess: true where practical
exactOptionalPropertyTypes: true where practical
```

## 10.2 `any`

Explicit `any` is prohibited except at a verified external boundary.

Use:

- `unknown`
- Type guards
- Validated schemas
- Generated API types

Every allowed `any` requires a comment.

## 10.3 Components

Use Vue Composition API and `<script setup lang="ts">`.

Components should:

- Have one clear responsibility
- Receive typed props
- Emit typed events
- Avoid hidden network calls when a parent/service should own them
- Avoid business-rule duplication
- Handle loading, empty, success, and error states

## 10.4 State management

Use Pinia only for genuinely shared state.

Do not put all API data into global stores.

Appropriate shared state:

- Authenticated admin
- Cart
- Global safe settings
- Notification state

## 10.5 API client

Use one typed API client layer.

It must handle:

- Base URL
- CSRF
- Request ID
- Standard errors
- `401` session expiration
- `409` conflicts
- `422` validation errors
- Cancellation where needed

Components must not duplicate raw Axios/fetch behavior.

## 10.6 Forms

Forms must:

- Use server errors as authority
- Provide client validation only for immediate usability
- Preserve entered non-secret data after correctable errors
- Prevent duplicate submit
- Re-enable after failure
- Show French errors
- Keep labels associated with fields
- Move focus to the error summary when appropriate

## 10.7 Public Vue islands

Every public island must justify its JavaScript cost.

Allowed examples:

- Cart
- Product variant selector
- Product gallery
- Search autocomplete
- Checkout interactions
- Consent manager

Do not hydrate static content unnecessarily.

---

# 11. CSS and UI Quality

## 11.1 Design source

`design.md` will define visual details.

Until then:

- Use semantic design tokens
- Avoid one-off magic colors
- Keep spacing scale consistent
- Keep typography scale consistent
- Support mobile first
- Avoid layout shift
- Respect reduced motion

## 11.2 CSS architecture

Use one approved approach consistently.

Recommended:

- Tailwind CSS with documented tokens, or
- Scoped component CSS plus global tokens

Do not mix multiple UI frameworks.

A large public UI component framework is prohibited unless performance testing proves it is justified.

## 11.3 Responsive quality

Required test widths:

```text
360
390
768
1024
1280
1440
```

No horizontal overflow except intentional carousels.

## 11.4 Motion

Animations must:

- Serve feedback or orientation
- Avoid blocking interaction
- Normally stay under 300 ms
- Respect `prefers-reduced-motion`
- Avoid animating expensive layout properties
- Use transform and opacity where appropriate

---

# 12. Accessibility Quality

Minimum target:

```text
WCAG 2.1 AA
```

Preferred target:

```text
WCAG 2.2 AA where applicable
```

Mandatory:

- Semantic landmarks
- One logical `h1`
- Correct heading order
- Keyboard navigation
- Visible focus
- Accessible names
- Form labels
- Error summary
- Color contrast
- Alternative text
- Dialog focus trapping
- Escape to close eligible dialogs
- No keyboard traps
- Touch targets appropriate for mobile
- Status messages announced where needed
- Reduced-motion support

Automated checks do not replace manual keyboard and screen-reader review.

---

# 13. SEO Quality

Public pages must be server-rendered.

Every indexable page requires:

- Unique title
- Meta description
- Canonical URL
- Correct language
- One `h1`
- Crawlable content
- Open Graph metadata
- Structured data where applicable

Required structured data:

- Product
- Breadcrumb
- Organization or LocalBusiness when approved

Requirements:

- XML sitemap
- Robots file
- Redirect on slug change
- Noindex admin
- Noindex cart
- Noindex checkout
- Noindex confirmation
- Noindex internal search where appropriate
- No broken canonical links
- No inactive products in sitemap

SEO tests should parse rendered HTML, not only inspect Vue state.

---

# 14. Performance Quality

## 14.1 Core Web Vitals

Public target at the 75th percentile on representative mobile traffic:

- LCP <= 2.5 seconds
- INP <= 200 milliseconds
- CLS <= 0.1

## 14.2 Storefront asset budgets

Initial hard budgets for production-compressed assets:

| Asset | Budget |
|---|---:|
| Shared initial storefront JavaScript | 180 KB gzip |
| Additional initial page JavaScript | 80 KB gzip |
| Shared storefront CSS | 100 KB gzip |
| Initial HTML document | 100 KB gzip |
| Primary mobile hero/LCP image | 250 KB |
| Product-card image | 120 KB each |
| Fonts loaded initially | Maximum two families and four files |

A design change that exceeds a budget requires:

- Measured justification
- Performance comparison
- Approval in the pull request

## 14.3 Backend response targets

Measured in a production-like environment, excluding client network latency:

| Request | Target p95 |
|---|---:|
| Cached public page application response | <= 300 ms |
| Product/category page uncached response | <= 500 ms |
| Search suggestions | <= 200 ms |
| Cart quote | <= 400 ms |
| Checkout submission before response | <= 900 ms |
| Admin paginated list | <= 500 ms |
| Admin order detail | <= 500 ms |

Checkout must not wait for Meta CAPI or image processing.

## 14.4 Query budgets

Starting query-count budgets:

| Page/action | Maximum |
|---|---:|
| Homepage | 25 |
| Category page | 25 |
| Product detail | 20 |
| Search suggestions | 10 |
| Cart quote | 25 |
| Admin order list | 25 |
| Admin order detail | 35 |

These are guardrails, not targets to consume fully.

A necessary increase requires an explanation and query-plan review.

## 14.5 Images

On upload:

- Validate
- Re-encode
- Strip metadata
- Generate responsive sizes
- Produce WebP
- Add AVIF only after browser and operational testing
- Store width and height
- Use `srcset`
- Lazy-load below the fold
- Preload only the real LCP image

## 14.6 JavaScript

- Code-split admin routes
- Lazy-load non-critical public islands
- Debounce search
- Cancel stale search requests
- Avoid large utility libraries for one function
- Prefer browser APIs
- No polling loops
- No unused polyfills for unsupported legacy browsers

## 14.7 Redis cache quality

Use exact invalidation.

Never use broad Redis flush commands.

Tests must verify invalidation after:

- Product update
- Category update
- Homepage section update
- Shipping update
- Static page update
- Meta public Pixel setting update

## 14.8 Performance regression

CI or staging must compare production build size against the main branch.

Block:

- Unexpected public bundle increase above 10 KB gzip
- Core Web Vital budget failure on critical pages
- New N+1 query
- Checkout synchronous external call

---

# 15. Testing Strategy

## 15.1 Test pyramid

Use:

1. Unit tests for pure rules and value objects
2. Feature/API tests for Laravel behavior
3. Integration tests for MySQL, Redis, queues, storage, and HTTP fakes
4. Component tests for Vue
5. Browser tests for critical end-to-end journeys
6. Performance and security checks

## 15.2 Backend framework

Use Pest on PHPUnit.

Tests must be deterministic and isolated.

## 15.3 Frontend framework

Recommended:

- Vitest
- Vue Test Utils
- Playwright for critical browser flows

## 15.4 Required critical scenarios

### Authentication

- Login
- Invalid credentials
- Disabled user
- CSRF
- Rate limit
- Password change
- Password reset
- Session revocation
- Last Super Admin protection

### Catalogue

- Active/inactive visibility
- Promotional price validation
- Variant combination uniqueness
- Variant image behavior data
- Category safe deletion
- Product archive behavior

### Cart and checkout

- Authoritative quote
- Invalid variant
- Insufficient stock
- Concurrent last-stock order
- Idempotency
- Promo-code race
- Free-shipping threshold
- Checkout-field snapshot
- Meta trigger snapshot
- No synchronous Meta call

### Orders

- Every valid transition
- Every invalid transition
- Editable-status restrictions
- Optimistic conflict
- Stock restoration once
- Return restock decision
- Audit entry

### Meta

- Browser/server event ID consistency
- One Purchase per order
- Queue retry
- Permanent failure
- Secret masking
- Configuration test before activate
- Password and phrase protection
- Consent refusal
- Sentry/log redaction

### Complaints

- Validation
- Rate limit
- Private attachment
- Status transition
- Linked-order authorization
- XSS escaping

### Content and SEO

- Sanitized HTML
- Slug redirect
- Canonical
- Sitemap
- Noindex pages
- Structured data

## 15.5 Coverage thresholds

### Archived-order migration recovery

Never run the rollback for `2026_07_18_000700_add_archived_at_to_orders.php` on populated production data. Export and verify archived timestamps first; if rollback has already removed the column, restore it with a forward migration, reload the verified timestamps, and record the recovery before continuing.

Coverage is a guardrail, not the goal.

Backend minimum:

- 85% line coverage overall
- 75% branch coverage overall
- No reduction on critical domain modules without approval

Frontend minimum for tested TypeScript/Vue source:

- 80% line coverage
- 70% branch coverage

Critical business scenarios listed above require explicit tests even when metric thresholds already pass.

Generated files, framework bootstrap, simple DTOs, and migrations may be excluded only through reviewed configuration.

## 15.6 Mutation testing

Mutation testing MAY be applied to:

- Price calculations
- Shipping threshold
- Promo-code rules
- Order transitions
- Meta trigger conditions

It is recommended before major launch, but not required in every CI run.

---

# 16. Test Data Quality

Use factories and builders.

Rules:

- Synthetic data only
- No real customer phone or address
- Deterministic clocks for time-sensitive rules
- Explicit timezone
- Reset database between tests
- Fake Meta and Sentry HTTP calls
- Fake storage where appropriate
- Use the real local MariaDB/MySQL-compatible service and Memurai/Redis-compatible service for integration tests
- Avoid brittle tests based on auto-increment values
- Test public identifiers

Recommended fictional data:

```text
Name: Client Test
Phone: 20000000
City: Tunis Test
Address: 1 rue de Test
```

---

# 17. Time and Date Quality

- Store timestamps in UTC.
- Display using `Africa/Tunis`.
- Use an injectable clock or Laravel time helpers in tests.
- Avoid direct uncontrolled `now()` calls deep in domain rules.
- Test date boundaries, expiration, and DST/timezone assumptions.
- Promo start/end comparisons must be explicit and documented.

---

# 18. Localization Quality

All customer-facing and admin-facing text is French.

Rules:

- No hard-coded English UI strings
- Use translation files
- API machine codes remain English
- Validation messages are French
- Dates and TND format are French/Tunisian appropriate
- Keep technical logs and source code in English
- Test missing translation keys

---

# 19. Security Quality Gates

Every change must comply with `security.md`.

CI must run:

```text
composer audit
npm audit --audit-level=high
secret scan
static analysis
security-sensitive tests
```

A high or critical vulnerability blocks merge unless a documented exception exists.

Security-sensitive changes require focused human review:

- Authentication
- Authorization
- Checkout
- Stock
- Promo codes
- File upload
- Meta
- Admin users
- Exports
- Rich text
- Deployment workflow

---

# 20. Privacy Quality Gates

Tests must prove:

- Meta does not fire after refusal
- Sentry receives no default PII
- Checkout and complaint bodies are redacted
- Private files require authorization
- Exports expire
- Customer data does not appear in URLs
- Guest cart contains no customer contact details
- Raw attribution expires according to approved retention
- Deleted/withdrawn consent is respected by future events

Do not add a field containing personal data without updating `privacy.md`.

---

# 21. Sentry Quality Rules

Every production release must set:

- Environment
- Release identifier
- Commit SHA where available
- Backend and frontend project
- Conservative trace sample rate

Rules:

- No PII
- No raw request bodies for checkout/complaints
- No token fields
- No cookie headers
- No Session Replay initially
- Queue permanent failures captured
- Deploy/release recorded
- Source maps uploaded privately
- Test event sent in staging

Sentry failure must not break checkout or admin operations.

---

# 22. CI Quality Pipeline

The same CI applies to Docker and non-Docker deployment plans.

Recommended GitHub Actions jobs:

## 22.1 Backend quality

```text
composer validate --strict
composer install --prefer-dist --no-interaction
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
php artisan test --coverage
composer audit
```

Use the local MariaDB/MySQL-compatible service and Memurai/Redis-compatible service. Do not require containers.

## 22.2 Frontend quality

```text
npm ci
npm run lint
npm run typecheck
npm run test:coverage
npm run build
npm audit --audit-level=high
```

## 22.3 Contract and browser smoke tests

Run:

- API contract feature tests
- Playwright smoke tests for critical flows
- Rendered SEO tests

## 22.4 Security and repository

Run:

- Secret scan
- Dependency review where available
- CodeQL where useful
- Workflow lint
- Check that `.env` and dumps are absent

## 22.5 Build budgets

Run:

- Bundle-size check
- Public asset manifest check
- Optional Lighthouse CI for homepage, product, category, and checkout

## 22.6 CI behavior

- Fail fast on formatting/static analysis
- Do not hide failures with `continue-on-error`
- No production secrets in CI tests
- Cache dependencies by lock-file hash
- Pin actions to reviewed versions or commit SHAs
- Minimum workflow permissions
- Separate CI and deployment jobs

---

# 23. Pull Request Quality

Every pull request must include:

- Purpose
- Related requirement
- Files/modules affected
- Business-rule impact
- Security/privacy impact
- Database impact
- API contract impact
- Performance impact
- Tests added
- Screenshots for UI changes
- Migration/rollback notes
- Deployment notes

A pull request should normally be:

- Focused
- Reviewable
- Free of unrelated formatting
- Small enough to understand
- Supported by tests

Large generated changes must be split when possible.

---

# 24. Code Review Checklist

Reviewer confirms:

- Requirement is understood
- Correct document was followed
- Authorization is backend-enforced
- Validation is allow-listed
- No secret/PII leak
- Transaction is correct
- Concurrency is handled
- Error codes match contract
- Cache invalidation is exact
- Query count is acceptable
- Tests cover success and failure
- UI is responsive and accessible
- French copy is correct
- Deployment neutrality remains
- No unnecessary dependency
- No dead code

Codex output is never merged without human review.

---

# 25. Dependency Quality

Before adding a package:

1. Explain the need.
2. Confirm Laravel/Vue does not already provide it.
3. Review maintenance and security.
4. Review bundle/runtime cost.
5. Review license.
6. Review transitive dependencies.
7. Add tests.
8. Update lock file intentionally.

Prohibited:

- Package added only to avoid writing a small clear function
- Unmaintained package
- Unpinned Git branch
- Package requiring broad filesystem or network permissions without review
- Multiple packages solving the same concern

---

# 26. Documentation Quality

Code changes must update documentation when they change:

- Architecture
- API
- Security
- Privacy
- Roles
- Environment
- Deployment
- Business behavior

Every module should have concise documentation for:

- Responsibility
- Public services/actions
- Important invariants
- Events/jobs
- Failure behavior

Do not document obvious syntax. Document decisions and constraints.

---

# 27. Migration and Backward Compatibility

Before a database or API breaking change:

- Identify affected clients
- Add migration plan
- Add rollback/forward-fix plan
- Preserve historical order snapshots
- Preserve existing slugs through redirects
- Do not rewrite old Meta events
- Avoid destructive deployment in one step

Use expand-and-contract migrations where needed:

1. Add new structure
2. Write both or backfill
3. Switch reads
4. Remove old structure later

---

# 28. Deployment Quality

Both future deployment plans must provide:

- Simple GitHub Actions CI
- Reproducible build
- Immutable release ID
- Environment validation
- Database backup before risky migration
- Controlled migration
- Cache warm-up
- Queue restart
- Health check
- Sentry release
- Rollback instructions

Application behavior must be identical in Docker and non-Docker deployments.

No feature may depend on a container-specific hostname in domain code.

---

# 29. Operational Quality

## 29.1 Queues

- Separate queues by purpose
- Bounded retries
- Timeouts
- Idempotent jobs
- Failed jobs persisted
- Sentry alert after permanent failure
- No secret in job payload

## 29.2 Scheduler

Scheduled tasks use overlap protection.

Important tasks:

- Backup
- Sitemap
- Cleanup
- Retention
- Meta reconciliation
- Health heartbeat

## 29.3 Backups

Release quality requires:

- Recent successful backup
- Off-site copy
- Encrypted storage
- Restore procedure
- Quarterly restore test

## 29.4 Logs

- Rotated
- Size-bounded
- Structured
- Redacted
- No debug logs in production

---

# 30. Browser and Device Quality

Support current stable versions of:

- Chrome
- Safari
- Firefox
- Edge

Test mobile Safari and mobile Chrome explicitly.

Critical flows:

- Search
- Variant selection
- Cart
- Checkout
- Confirmation
- Admin login
- Order processing
- Product image upload

No important action may depend only on hover.

---

# 31. Error-State Quality

Every interactive feature must define:

- Loading
- Empty
- Success
- Validation error
- Authorization failure
- Conflict
- Network failure
- Retry behavior
- Disabled state

Do not display generic “Something went wrong” when a safe specific French message exists.

Do not expose stack traces.

---

# 32. Empty and Edge Cases

Tests and UI must handle:

- No products
- No categories
- Empty custom section
- Product without promo
- Product with no variants
- Product with multiple variant groups
- Selected variant without image
- Last item in stock
- Stock changed after cart creation
- Promo code exhausted concurrently
- Free shipping exactly at threshold
- Disabled checkout custom field
- Empty complaint list
- Meta disabled
- Meta trigger changed after old orders
- Redis temporary failure
- Sentry unavailable

---

# 33. Definition of Done

A feature is done only when:

- Requirement is implemented
- Architecture is respected
- API contract is respected
- Security rules pass
- Privacy rules pass
- Tests pass
- Static analysis passes
- Formatting and lint pass
- No high/critical dependency issue
- Performance budget is respected
- Accessibility is reviewed
- French UI text is complete
- Error states exist
- Documentation is updated
- Sentry behavior is safe
- Migration and rollback are documented
- Human review is complete

“Works on my machine” is not done.

---

# 34. Release Gates

Production release is blocked when:

- CI is red
- Security checklist fails
- Backup is unavailable
- Migration is unreviewed
- Sentry redaction is unverified
- Critical browser flow fails
- Public page is not server rendered
- Meta fires without consent
- Checkout can duplicate orders
- Stock race test fails
- Admin authorization test fails
- `APP_DEBUG` is enabled
- High/critical exploitable dependency vulnerability exists
- Bundle or Core Web Vital budget regresses without approval
- Privacy policy has unresolved placeholders

---

# 35. Codex Working Rules

Before coding, Codex must:

1. Read the relevant approved documents.
2. State the module and requirement being implemented.
3. Identify security, privacy, database, and API constraints.
4. Inspect existing patterns before adding new ones.
5. Prefer the smallest complete change.
6. Add tests with the implementation.
7. Run relevant quality commands.
8. Report failures honestly.
9. Avoid unrelated refactoring.
10. Preserve deployment neutrality.

Codex must not:

- Invent requirements
- Skip tests because a change appears simple
- Weaken types
- Add broad ignore rules
- Disable security middleware
- Change API shapes silently
- Add dependencies without rationale
- Replace domain logic with frontend checks
- Claim success without command evidence
- Modify generated or vendor files manually
- Use production data

---

# 36. Recommended Command Set

Exact scripts may change, but the repository should provide simple commands such as:

```text
composer quality
composer test
composer analyse
composer security
npm run quality
npm run test
npm run build
```

A top-level developer command SHOULD run the complete local quality suite.

Example:

```text
make quality
```

or:

```text
composer project:quality
```

The project must not require Docker for code quality commands, although Docker may provide a convenient environment.

---

# 37. Quality Exceptions

A quality rule may be temporarily bypassed only when:

- Reason is documented
- Risk is understood
- Owner approves
- Compensating control exists
- Expiry date is set
- Follow-up issue is created

Do not hide exceptions in CI configuration.

---

# 38. Initial Tooling Baseline

Recommended tooling:

## Backend

- Pest/PHPUnit
- Laravel Pint
- Larastan/PHPStan
- Composer Audit

## Frontend

- TypeScript strict mode
- ESLint
- Prettier only if coordinated with ESLint
- Vitest
- Vue Test Utils
- Playwright
- npm Audit

## Cross-cutting

- GitHub Actions
- Secret scanner
- Optional CodeQL
- Optional Lighthouse CI
- Sentry release integration

Tooling may be replaced only when the replacement provides equal or stronger guarantees without unnecessary complexity.

---

# 39. Source of Truth

This file is the engineering-quality source of truth.

When a new feature cannot satisfy these rules, the design must be reconsidered or an explicit exception approved. Quality gates must not be weakened silently to make a build pass.
