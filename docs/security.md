# Security Rules and Controls

## ToutDispo E-Commerce Platform

**Document version:** 1.0  
**Status:** Mandatory security baseline  
**Documentation language:** English  
**Application UI language:** French only  
**Application type:** Public e-commerce storefront plus private back office  
**Architecture:** Laravel modular monolith, Blade storefront, Vue 3 interactive components, Vue 3 admin SPA  
**Deployment:** One VPS; Docker and non-Docker plans supported later  
**External monitoring platform:** Sentry only  
**Primary standards:** OWASP Top 10:2025, OWASP ASVS 5.0.0, OWASP API Security Top 10:2023  
**Target assurance level:** OWASP ASVS Level 2 where applicable  
**Related documents:** `prd.md`, `roles-authorization-matrix.md`, `system-design.md`, `api-contracts.md`, `privacy.md`, `quality-rules.md`

---

# 1. Purpose

This document defines mandatory security rules for the ToutDispo e-commerce platform.

It is intended to prevent Codex or human developers from implementing security-sensitive behavior inconsistently or relying only on framework defaults.

The document covers:

- Secure architecture and trust boundaries
- OWASP Top 10:2025 controls
- OWASP API Security Top 10:2023 controls
- Authentication, sessions, password security, and optional MFA
- Backend authorization and object-level access control
- Input validation and output encoding
- SQL injection, command injection, XSS, CSRF, SSRF, open redirect, and path traversal defenses
- File-upload security
- Checkout abuse, fake-order mitigation, idempotency, stock integrity, and rate limiting
- Meta Pixel and Conversions API secret protection
- HTTP security headers and Content Security Policy
- Redis, MySQL, queue, scheduler, and filesystem security
- Sentry privacy and redaction
- GitHub repository and GitHub Actions hardening
- Docker and non-Docker VPS hardening requirements
- Dependency and software supply-chain controls
- Backups, restoration, incident response, and security testing
- Release-blocking acceptance criteria

This document is binding for implementation unless a later approved Architecture Decision Record explicitly replaces a rule.

---

# 2. Security Priority Levels

Every rule is assigned one of the following priorities.

| Priority | Meaning |
|---|---|
| **MUST** | Required before production release |
| **SHOULD** | Strongly recommended; deviation requires a documented reason |
| **MAY** | Optional hardening or future enhancement |

A failed **MUST** requirement blocks production deployment.

---

# 3. Security Objectives

The platform must preserve:

## 3.1 Confidentiality

Protect:

- Back-office credentials
- Session identifiers
- Customer names, phone numbers, cities, and addresses
- Complaint descriptions and attachments
- Meta CAPI access tokens
- Database and Redis credentials
- Laravel application keys
- Backup archives
- Audit information
- Deployment secrets

## 3.2 Integrity

Prevent unauthorized or duplicate modification of:

- Products
- Prices
- Promotional prices
- Product variants
- Stock
- Promo-code usage
- Shipping configuration
- Checkout fields
- Orders
- Order statuses
- Revenue calculations
- Meta Purchase events
- Meta configuration
- Users and roles
- Audit logs
- Static policies and storefront content

## 3.3 Availability

Protect the platform from:

- Login brute force
- Automated fake orders
- Complaint spam
- Search abuse
- Large request bodies
- Image decompression bombs
- Queue flooding
- Expensive exports
- Slow database queries
- Resource exhaustion
- Redis or database exposure
- Accidental configuration failures
- Failed deployments

## 3.4 Accountability

Security-sensitive actions must be attributable to:

- A specific authenticated back-office user
- A request identifier
- A timestamp
- A resource
- An old and new state where appropriate

Secrets must not appear in accountability records.

---

# 4. Security Standards Baseline

## 4.1 OWASP Top 10:2025

Implementation must address:

1. A01: Broken Access Control
2. A02: Security Misconfiguration
3. A03: Software Supply Chain Failures
4. A04: Cryptographic Failures
5. A05: Injection
6. A06: Insecure Design
7. A07: Authentication Failures
8. A08: Software or Data Integrity Failures
9. A09: Security Logging and Alerting Failures
10. A10: Mishandling of Exceptional Conditions

## 4.2 OWASP API Security Top 10:2023

The API must address:

1. API1: Broken Object Level Authorization
2. API2: Broken Authentication
3. API3: Broken Object Property Level Authorization
4. API4: Unrestricted Resource Consumption
5. API5: Broken Function Level Authorization
6. API6: Unrestricted Access to Sensitive Business Flows
7. API7: Server Side Request Forgery
8. API8: Security Misconfiguration
9. API9: Improper Inventory Management
10. API10: Unsafe Consumption of APIs

## 4.3 OWASP ASVS 5.0.0

The project targets ASVS Level 2 where requirements apply.

The security test matrix SHOULD reference stable ASVS identifiers using:

```text
v5.0.0-<requirement_identifier>
```

The project does not claim formal ASVS certification unless a separate assessment is performed.

---

# 5. Data Classification

## 5.1 Public data

Examples:

- Active product names
- Public descriptions
- Public product images
- Public prices
- Category names
- Store contact information
- Published static pages
- Meta Pixel ID

Public data still requires integrity protection.

## 5.2 Internal data

Examples:

- Stock quantities
- Low-stock thresholds
- Admin notes
- Dashboard metrics
- Internal product identifiers
- Queue status
- Non-sensitive operational logs
- Meta event status without raw attribution data

Internal data must require back-office authorization.

## 5.3 Personal data

Examples:

- Customer name
- Phone
- City
- Address
- Complaint content
- Complaint attachment
- IP address
- User agent
- `_fbp`
- `_fbc`
- UTM and attribution context when linked to an order

Access must be limited to business need.

## 5.4 Secret data

Examples:

- Passwords
- Password hashes
- Session IDs
- CSRF/session secrets
- Meta CAPI token
- `APP_KEY`
- Database password
- Redis credentials
- Sentry authentication token
- Backup encryption key
- Deployment private keys
- GitHub deployment secrets

Secret data must never be exposed to public or admin JSON responses.

## 5.5 Highly privileged configuration

Examples:

- Meta Purchase trigger
- Meta tracking status
- User role
- User active state
- Password reset
- Shipping amount
- Free-shipping threshold
- Checkout-field structure
- Security configuration

Changes require elevated authorization and audit logging.

---

# 6. Threat Model Summary

## 6.1 Likely threat actors

- Automated bots
- Credential-stuffing attackers
- Fake-order spammers
- Complaint-form spammers
- Malicious customers manipulating cart payloads
- Attackers probing public endpoints
- Compromised Admin accounts
- Malicious or careless back-office users
- Supply-chain attackers through dependencies or CI actions
- Attackers exploiting exposed VPS services
- Third-party API compromise or malformed responses
- Accidental developer misconfiguration

## 6.2 Primary attack surfaces

- Public search
- Public cart quote
- Guest checkout
- Complaint submission and attachment upload
- Back-office login
- Admin API
- Product rich text
- Product and banner image uploads
- Meta configuration
- Meta outbound API calls
- Export and print functions
- Redis, MySQL, Nginx, and PHP-FPM
- GitHub Actions
- Deployment credentials
- Backups
- Sentry event payloads

## 6.3 Trust boundaries

```text
Untrusted Internet
    |
    v
Nginx / optional CDN-WAF
    |
    v
Laravel public and admin routes
    |
    +--> MySQL
    +--> Redis
    +--> Local public media
    +--> Private complaint storage
    +--> Queue workers
    +--> Meta CAPI
    +--> Sentry
    +--> Off-site backup storage
```

Every boundary must validate assumptions independently.

---

# 7. Secure Architecture Principles

## 7.1 Server authority

Laravel is authoritative for:

- Product availability
- Variant validity
- Stock
- Prices
- Promotional prices
- Promo-code validity
- Shipping fee
- Free-shipping threshold
- Order totals
- Order status
- Authorization
- Meta trigger rules
- Meta event uniqueness

Browser values are suggestions only.

## 7.2 Default deny

Every protected action must be denied unless explicitly permitted.

## 7.3 Least privilege

Apply least privilege to:

- Application roles
- Database users
- Redis ACL users
- Filesystem users
- Queue workers
- GitHub Actions permissions
- Deployment users
- Sentry access
- Backup credentials

## 7.4 Separation of concerns

Security-sensitive logic must not be duplicated across controllers or Vue components.

Use dedicated services/actions for:

- Checkout
- Stock mutation
- Order editing
- Order transitions
- Password reset
- Meta configuration activation
- Meta event creation and sending
- File processing
- Audit recording

## 7.5 Fail closed

When authorization, validation, stock locking, configuration validation, or security checks fail, the operation must be rejected.

## 7.6 Secure by default

Defaults:

- `APP_DEBUG=false`
- Meta tracking disabled until validated configuration exists
- Promo-code field hidden initially
- New back-office user forced to change temporary password
- Products inactive until intentionally published where workflow supports it
- Private complaint attachments
- No remote database or Redis exposure
- No wildcard CORS
- No public registration
- No arbitrary outbound URLs

---

# 8. OWASP Top 10:2025 Mapping

| OWASP category | Project controls |
|---|---|
| A01 Broken Access Control | Policies, Gates, role middleware, object-level checks, public IDs, default deny, authorization tests |
| A02 Security Misconfiguration | Production environment checks, debug disabled, headers, strict CORS, no exposed services, config validation |
| A03 Software Supply Chain Failures | Lock files, dependency audits, Dependabot, pinned CI actions, human review, immutable releases |
| A04 Cryptographic Failures | HTTPS, Argon2id/bcrypt, Laravel encryption, encrypted backups, secret rotation |
| A05 Injection | Form Requests, parameterized queries, escaped output, HTML allow-list, no shell interpolation |
| A06 Insecure Design | Threat model, transactional checkout, idempotency, stock locks, protected Meta trigger, abuse controls |
| A07 Authentication Failures | Strong passwords, throttling, secure sessions, forced reset, optional MFA, session revocation |
| A08 Software or Data Integrity Failures | Signed deployment artifacts where adopted, lock files, CI gates, audit logs, idempotent jobs |
| A09 Security Logging and Alerting Failures | Structured logs, Sentry alerts, audit events, queue-failure alerts, redaction |
| A10 Mishandling of Exceptional Conditions | Safe error envelopes, transaction rollback, retry classification, no stack traces, fail-closed logic |

---

# 9. OWASP API Security Top 10:2023 Mapping

| API category | Project controls |
|---|---|
| API1 BOLA | Policies on every resource lookup; public IDs do not replace authorization |
| API2 Broken Authentication | Sanctum sessions, CSRF, throttling, secure cookies, session rotation |
| API3 Broken Object Property Level Authorization | Form Request allow-lists, API Resources, no blind mass assignment |
| API4 Unrestricted Resource Consumption | Pagination caps, upload limits, query limits, rate limits, export limits |
| API5 Broken Function Level Authorization | Super-Admin-only endpoint groups and explicit policy tests |
| API6 Sensitive Business Flows | Checkout, promo, complaints, login, exports, and Meta tests are separately throttled |
| API7 SSRF | No user-controlled outbound URLs; fixed Meta endpoint; URL allow-lists |
| API8 Security Misconfiguration | Secure environment, headers, CORS, service isolation, no debug routes |
| API9 Improper Inventory Management | Versioned `/api/v1`, endpoint inventory, no undocumented production routes |
| API10 Unsafe Consumption of APIs | Validate Meta responses, timeouts, TLS verification, safe retries, response limits |

---

# 10. Authentication Rules

## 10.1 Scope

Only Admin and Super Admin authenticate.

There is no customer authentication in the initial version.

## 10.2 Login identifier

- Email is the login identifier.
- Email comparison must use normalized lowercase values.
- A unique database constraint is mandatory.
- Login error messages must not reveal whether the email exists.

Public French response:

> Identifiants incorrects.

## 10.3 Password requirements

**MUST:**

- Minimum 15 characters for back-office users
- Maximum at least 128 characters
- Allow spaces and Unicode
- Allow password-manager generated values
- Allow paste
- Reject passwords found in an approved breached-password check when the check is enabled
- Reject known project name, email, and common-password patterns
- Never trim meaningful password spaces silently
- Never impose periodic password changes without evidence of compromise
- Force change after Super Admin temporary reset
- Force change after suspected compromise

Composition rules such as mandatory uppercase, number, and symbol are optional if minimum length and breached-password checks are strong.

## 10.4 Password storage

Use:

1. Argon2id where the PHP/Laravel runtime is configured and tested for it
2. Otherwise bcrypt with a reviewed cost

Passwords must be hashed through Laravel's hashing service.

Never:

- Encrypt passwords reversibly
- Store plaintext passwords
- Log passwords
- Send passwords to Sentry
- Return passwords from an API
- Email passwords
- Include passwords in URLs

Hash parameters must be benchmarked on production-class hardware.

Target password verification time SHOULD be approximately 100–500 ms without harming availability.

Use `Hash::needsRehash()` after successful authentication to upgrade old hashes.

## 10.5 Authentication throttling

Use Redis-backed limits.

Recommended baseline:

| Scope | Limit |
|---|---:|
| Admin login per IP | 8 attempts per minute |
| Per IP | 20 attempts per hour |
| Per account | Progressive delay after repeated failures |
| Password confirmation | 5 attempts per 15 minutes |
| Password change | 5 attempts per hour |

Do not create a permanent account lockout that an attacker can trigger.

After repeated failures, use temporary cooldown and generic messages.

## 10.6 Successful login

After login:

- Regenerate the session ID
- Clear login-failure counters for the account where appropriate
- Record a security event without the password
- Update `last_login_at`
- Record last-login IP only if approved by privacy policy
- Reject disabled accounts
- Redirect forced-password-change users only to the change-password flow

## 10.7 Failed login logging

Log:

- Request ID
- Timestamp
- Hashed or redacted account identifier
- Source IP according to privacy policy
- Failure reason category internally
- Rate-limit state

Do not expose the reason to the user.

## 10.8 Super Admin password reset

When a Super Admin resets another user:

- Require recent password confirmation
- Generate or accept a strong temporary password
- Set `force_password_change=true`
- Revoke all sessions of the affected user
- Increment an authentication version if used
- Audit the action
- Never log or return the password after the request
- Never reveal the previous password

## 10.9 Multi-factor authentication

### Launch baseline

MFA is not required by the existing API contract.

### Strong recommendation

TOTP MFA SHOULD be added before or shortly after launch, with:

- Mandatory MFA for Super Admin
- Optional or mandatory MFA for Admin
- Recovery codes stored hashed
- Rate-limited verification
- Reauthentication before disabling MFA
- Audit logging

Adding MFA requires a controlled update to API contracts and UI design.

---

# 11. Session Security

## 11.1 Session storage

Use Redis for production sessions.

## 11.2 Session cookie

Recommended:

```text
Secure=true
HttpOnly=true
SameSite=Lax
Path=/
```

The cookie domain SHOULD be host-only when possible.

Do not use a broad `.passioncosmetic.com` cookie domain unless cross-subdomain authentication is explicitly required.

## 11.3 Session lifetime

Recommended baseline:

- Idle timeout: 30 minutes
- Absolute back-office session lifetime: 8 hours
- No persistent remember-me session in the first release

If `remember` remains in the login API, production SHOULD reject or ignore `true` until a reviewed persistent-session design exists.

## 11.4 Session rotation

Regenerate session ID:

- After login
- After privilege change
- After password change
- After security-sensitive reauthentication
- When restoring a session after forced password change

## 11.5 Session invalidation

Revoke all sessions when:

- User is disabled
- Password is reset by Super Admin
- User changes own password
- Role changes
- Account compromise is suspected
- Last Super Admin protections trigger a security review

## 11.6 Concurrent sessions

MAY limit concurrent sessions per back-office account.

The initial implementation SHOULD provide a way for the Super Admin to revoke active sessions through a later API enhancement.

---

# 12. CSRF, CORS, and Origin Security

## 12.1 CSRF

All authenticated cookie-based mutation routes MUST use Laravel CSRF protection.

The Vue admin client must:

1. Request `/sanctum/csrf-cookie`
2. Send the `X-XSRF-TOKEN` header automatically
3. Retry only according to a controlled session-expiration flow

Never exclude admin endpoints from CSRF middleware.

## 12.2 SameSite

Use `SameSite=Lax` unless a verified cross-site requirement exists.

`SameSite=None` requires `Secure` and a written justification.

## 12.3 CORS

Preferred architecture uses same-origin requests:

- Public UI and public API on the storefront host
- Admin SPA and admin API on the admin host

Therefore:

- Cross-origin CORS SHOULD be disabled in production
- No wildcard origins
- No wildcard origin with credentials
- Development origins must be explicit
- Allowed methods and headers must be allow-listed
- Preflight cache duration must be reasonable

## 12.4 Origin validation

For highly sensitive endpoints such as Meta configuration activation, the server SHOULD validate `Origin` or `Referer` in addition to CSRF when present.

This is defense in depth, not a CSRF replacement.

---

# 13. Authorization Rules

## 13.1 Backend-only authority

Vue may hide unauthorized controls, but Laravel must enforce every permission.

## 13.2 Role model

Exactly:

- `super_admin`
- `admin`

No role names are accepted from arbitrary client input except through Super-Admin-authorized user management.

## 13.3 Laravel controls

Use:

- Authentication middleware
- Policies for resource actions
- Gates for global capabilities
- Role middleware for route groups
- Service-level checks for critical domain operations

## 13.4 Object-level authorization

Every endpoint that receives a resource identifier must check:

1. Resource exists
2. Acting user may perform the action
3. Resource state permits the action
4. Related object belongs to the expected parent

Example:

A product image request must verify that the image belongs to the product in the route.

## 13.5 Public IDs

ULIDs and UUIDs reduce enumeration but are not authorization controls.

## 13.6 Last Super Admin protections

The backend must prevent:

- Disabling the final active Super Admin
- Deleting the final active Super Admin
- Downgrading the final active Super Admin
- Self-lockout through unsafe role or status changes

## 13.7 Mass assignment

- Models must use explicit `$fillable` or protected DTO/action mapping.
- Never call `Model::create($request->all())`.
- Never call `update($request->all())`.
- Form Requests must return validated allow-listed fields.
- Sensitive fields such as role, status, price, token, and ownership must be assigned explicitly.

## 13.8 Authorization tests

Every protected endpoint requires tests for:

- Super Admin allowed
- Admin allowed where specified
- Admin denied where prohibited
- Unauthenticated denied
- Wrong parent resource denied
- Terminal state denied
- Manipulated request denied

---

# 14. Input Validation

## 14.1 Form Requests

Every JSON and multipart mutation endpoint MUST use a Laravel Form Request or equivalent dedicated validator.

## 14.2 Allow-list validation

Validate:

- Presence
- Type
- Length
- Range
- Format
- Enum
- Relationship existence
- Relationship ownership
- Business state
- File signature and size
- Unknown field handling

## 14.3 Unknown fields

Security-sensitive endpoints SHOULD reject unknown top-level fields.

This protects against accidental mass assignment and stale clients.

## 14.4 String limits

Every string requires an explicit maximum.

Examples:

| Field | Maximum |
|---|---:|
| Product name | 180 |
| Category name | 160 |
| Customer full name | 180 |
| Phone | 40 |
| City | 160 |
| Address | 2000 |
| Complaint subject | 200 |
| Complaint description | 5000 |
| Internal note | 5000 |
| SEO title | 255 |
| SEO description | 320 |
| Search term | 100 |

## 14.5 Arrays and pagination

- Maximum cart/order lines: 100
- Maximum variants per product: set a reviewed configurable cap
- Maximum option groups: recommended 5
- Maximum values per group: recommended 50
- Maximum API page size: 100
- Maximum reorder list size: set per resource
- Reject duplicate IDs inside arrays

## 14.6 Numeric values

- Use integer millimes for money
- Reject floats for authoritative money
- Quantities: integer, minimum 1, maximum 99 per line unless explicitly changed
- Stock: non-negative integer
- Percentage: integer 1–100
- Sort order: bounded non-negative integer
- File dimensions and megapixels: bounded

## 14.7 Phone normalization

- Preserve submitted display value in order snapshot if desired
- Store a normalized form for search and abuse controls
- Apply Tunisia-aware normalization without inventing missing digits
- Never use the phone as authentication
- Use a keyed or plain SHA-256 hash for rate-limit keys; never expose rate-limit key material

## 14.8 Validation errors

Public validation errors must:

- Be in French
- Avoid stack traces
- Avoid SQL or internal field names where possible
- Use stable machine error codes
- Avoid revealing whether sensitive records exist

---

# 15. Output Encoding and XSS Prevention

## 15.1 Blade

Use escaped Blade output:

```php
{{ $value }}
```

Do not use:

```php
{!! $value !!}
```

unless the value has passed the approved server-side HTML sanitizer.

## 15.2 Vue

- Rely on Vue interpolation escaping.
- Avoid `v-html`.
- `v-html` is allowed only for server-sanitized content from an approved field.
- Never render user-provided strings as component templates.
- Do not dynamically execute strings.

## 15.3 Rich text

Product descriptions and static-page content may contain limited HTML.

Server sanitizer allow-list SHOULD include only necessary tags such as:

- `p`
- `br`
- `strong`
- `em`
- `ul`
- `ol`
- `li`
- `h2`
- `h3`
- `blockquote`
- Safe `a` links

Disallow:

- `script`
- `iframe`
- `object`
- `embed`
- `style`
- `svg`
- `math`
- Form controls
- Inline event handlers
- `javascript:` URLs
- Dangerous `data:` URLs
- Arbitrary classes and styles unless reviewed

Sanitize on write and optionally verify on read.

## 15.4 URL rendering

- Escape attribute values.
- Validate schemes.
- Prefer relative same-site links.
- Add `rel="noopener noreferrer"` to external links opened in a new tab.
- Do not allow user-controlled `target` or arbitrary protocol values.

## 15.5 Content Security Policy

CSP is mandatory as a second layer; it does not replace encoding and sanitization.

---

# 16. Injection Prevention

## 16.1 SQL injection

Use:

- Eloquent
- Query Builder
- Prepared parameter binding

Never concatenate untrusted input into SQL.

Raw expressions require human security review.

Sort and filter columns must come from allow-lists.

## 16.2 Command injection

- Do not invoke shell commands with user-controlled values.
- Prefer PHP libraries over shell tools.
- If a deployment script invokes commands, arguments must be fixed or safely escaped and never originate from HTTP requests.
- Do not expose Artisan command execution through the admin UI.

## 16.3 Template injection

- Do not compile user-provided Blade templates.
- Do not store executable PHP in database content.
- Do not evaluate dynamic expressions.

## 16.4 Header injection

- Do not place unvalidated strings into response headers.
- Sanitize download filenames.
- Strip CR and LF.
- Use framework response APIs.

## 16.5 Log injection

- Structured logging is preferred.
- Normalize line breaks in untrusted values.
- Do not allow user input to control log templates.

## 16.6 Regex denial of service

- Avoid complex nested regex patterns.
- Bound input lengths before regex.
- Use simple validators.
- Add tests for worst-case input where custom regex is necessary.

## 16.7 Deserialization

- Never use PHP `unserialize()` on user-controlled data.
- Use JSON with schema validation.
- Do not accept arbitrary serialized objects in queues or cache.
- Queue jobs should serialize identifiers and approved scalar DTOs.

---

# 17. SSRF and Outbound Request Security

## 17.1 General rule

The application must not fetch arbitrary URLs supplied by users or administrators.

## 17.2 Meta API

Meta endpoint host and path must be constructed from trusted configuration.

Do not store an arbitrary Meta base URL in editable settings.

Allow only the expected official HTTPS endpoint.

## 17.3 External links

Banner and content links:

- Prefer relative paths
- Allow `https` only for external URLs
- Reject localhost, private network, link-local, file, FTP, and custom schemes when a URL will ever be fetched server-side
- Avoid open redirects

## 17.4 Future remote image import

Remote image import is excluded from the initial release.

If added later, require:

- Host allow-list
- DNS rebinding defenses
- Private/reserved IP rejection before and after resolution
- Redirect limit
- Response size limit
- Content-type and signature validation
- Connection and read timeouts
- No credential forwarding

## 17.5 Outbound request limits

Meta requests must have:

- TLS verification enabled
- Connect timeout
- Total timeout
- Response-size expectation
- Retry classification
- Bounded retries
- No request secrets in logs

---

# 18. Open Redirect Prevention

- Internal redirect destinations must be relative same-site paths.
- Never redirect directly to a request-provided absolute URL.
- URL redirect management must reject loops.
- External redirects require explicit Super Admin action and an allow-list if ever supported.
- Authentication redirects must use server-defined destinations.

---

# 19. Path Traversal and Filesystem Safety

- Never accept filesystem paths from requests.
- Generate random storage names.
- Use Laravel storage disks.
- Normalize and reject dangerous filenames.
- Never join raw input to filesystem paths.
- Download private files by database identifier after authorization.
- Disable directory listing.
- Web server must not execute files from upload directories.
- Complaint attachments must remain outside the public web root.
- Temporary files must use safe system directories and restrictive permissions.

---

# 20. File Upload Security

## 20.1 General rules

Every upload must validate:

- Authentication or identifiable public flow
- Authorization
- Extension
- MIME type
- File signature
- Size
- Dimensions
- Megapixels
- Filename
- Storage destination
- Processing result

## 20.2 Product and banner images

Recommended initial allow-list:

- JPEG
- PNG
- WebP

Disallow:

- SVG
- GIF unless explicitly required
- PDF
- ZIP
- XML
- Office documents
- Executables
- Polyglot files

Recommended source limits:

- Maximum file size: 10 MB
- Maximum dimensions: 8000 × 8000
- Maximum megapixels: 25 MP

The product editor and product-image API use a stricter maximum of 2 MB per
image. The web-server and PHP request-body limits remain larger than 2 MB so
an editor save can contain several images and multipart overhead.

The image-processing job must:

- Decode the image through an approved library
- Reject decode failure
- Re-encode to controlled output formats
- Strip EXIF and metadata
- Generate responsive sizes
- Use randomized names
- Never preserve executable extension
- Apply memory/time limits

Originals SHOULD be private or deleted after successful processing according to image policy.

## 20.3 Complaint images

Complaint attachments are public unauthenticated uploads and require stricter limits.

Recommended:

- JPEG, PNG, WebP only
- Maximum file size: 5 MB
- Maximum megapixels: 20 MP
- One attachment per complaint in v1
- Private storage
- Re-encode and strip metadata
- Never return direct storage URL

## 20.4 Decompression bombs

Before full processing:

- Read safe image metadata
- Enforce pixel limits
- Enforce memory constraints
- Reject malformed images
- Run image processing asynchronously when possible
- Limit queue concurrency for image jobs

## 20.5 Antivirus

Because initial uploads are images only and are re-encoded, antivirus is optional.

ClamAV or another malware scanner MAY be added if broader file types are introduced.

## 20.6 Serving public images

Serve only generated safe derivatives from public storage.

Headers:

```text
X-Content-Type-Options: nosniff
Content-Type: exact generated image type
```

Do not use user filename as the public filesystem name.

## 20.7 Private attachment download

Require:

- Authenticated Admin or Super Admin
- Complaint access authorization
- Safe content disposition
- Exact MIME
- `Cache-Control: private, no-store`
- No path disclosure

---

# 21. Checkout and Fake-Order Abuse Controls

## 21.1 Server-side pricing

Checkout ignores all client-supplied:

- Unit prices
- Totals
- Discounts
- Shipping fees
- Meta event IDs
- Order statuses

## 21.2 Idempotency

`Idempotency-Key` is mandatory.

The system must store:

- Key
- Canonical payload hash
- Resulting order
- Expiry

Rules:

- Same key + same payload returns the existing order
- Same key + different payload returns `409`
- Stock and promo usage occur once
- Meta logical Purchase event occurs once

## 21.3 Stock locking

Checkout must:

1. Resolve products and variants
2. Sort lock order deterministically
3. Lock stock rows
4. Validate availability
5. Validate promo usage under lock
6. Create order and snapshots
7. Deduct stock
8. Commit
9. Dispatch external jobs after commit

No Meta or Sentry network call may run inside the transaction.

## 21.4 Public checkout rate limits

Recommended baseline:

| Key | Limit |
|---|---:|
| Per IP | 5 order submissions per 10 minutes |
| Per IP | 20 order submissions per 24 hours |
| Per normalized phone hash | 3 new orders per 24 hours |
| Per idempotency key | One canonical payload |
| Global emergency threshold | Configurable circuit breaker |

Limits must be adjustable without code deployment.

Do not reveal exact abuse thresholds publicly.

## 21.5 Duplicate-order detection

SHOULD flag potential duplicates based on:

- Same phone
- Similar cart
- Similar total
- Short time window
- Same IP/device context

Do not automatically reject legitimate retries already handled by idempotency.

Potential duplicates may be created but flagged for Admin review, depending on final business rule.

## 21.6 Honeypot

A hidden honeypot field MAY be used for public forms.

Bots that fill it should receive a generic success or rejection according to anti-automation policy without creating a resource.

## 21.7 CAPTCHA

CAPTCHA is not required initially.

An approved privacy-conscious challenge MAY be enabled only when abuse exceeds rate-limit and honeypot controls.

It must not become a mandatory dependency without a fallback plan.

## 21.8 Promo-code probing

Public promo errors SHOULD use a generic message such as:

> Code promo invalide ou indisponible.

Detailed reason remains available only to authorized users.

Rate limit quote attempts using promo codes.

## 21.9 Inventory abuse

- Cart does not reserve stock.
- Checkout is the only stock-deduction point.
- Maximum quantities are bounded.
- Repeated failed stock requests are rate-limited.
- Stock values are not publicly exposed.

---

# 22. Public Search Security

Recommended limits:

| Key | Limit |
|---|---:|
| Per IP | 60 requests per minute |
| Minimum query length | 2 characters |
| Maximum query length | 100 characters |
| Maximum suggestions | 10 |

Controls:

- Indexed allow-listed fields only
- No wildcard-leading full scans when avoidable
- Short Redis cache
- Escape display output
- No raw query syntax
- No category or product data for inactive records
- Query timeout/slow-query monitoring

---

# 23. Complaint Form Abuse Controls

Recommended:

| Key | Limit |
|---|---:|
| Per IP | 3 submissions per hour |
| Per normalized phone hash | 5 submissions per 24 hours |
| Attachment | One image, maximum 5 MB |
| Description | Maximum 5000 characters |

Additional controls:

- Honeypot
- Generic response
- No public order-existence disclosure
- Private attachments
- Input and file validation
- Sentry does not receive complaint body or attachment
- Admin views escape complaint text
- Rate-limit failures are logged without storing full complaint content

---

# 24. Rate-Limiting Matrix

Redis is the rate-limit store.

| Flow | Recommended baseline |
|---|---|
| Admin login per IP | 8/minute |
| Login per IP | 20/hour |
| Password confirmation | 5/15 minutes/user |
| Own password change | 5/hour/user |
| Public search | 60/minute/IP |
| Cart quote | 30/minute/IP |
| Checkout | 5/10 minutes/IP |
| Checkout daily | 20/day/IP |
| Checkout per phone | 3/day/phone hash |
| Complaint | 3/hour/IP |
| Complaint per phone | 5/day/phone hash |
| Admin API general | 120/minute/user |
| Product image upload | 30/hour/user |
| Export creation | 5/hour/user |
| Meta connection test | 5/15 minutes/user |
| Meta activation | 5/hour/user |
| Manual Meta retry | 10/hour/user |
| Password reset by Super Admin | 10/hour/user |

Rules:

- Limits are configuration values.
- Rate-limit keys must avoid storing plaintext secrets or unnecessary PII.
- Return `Retry-After`.
- Use stable `RATE_LIMITED` error code.
- Nginx MAY enforce coarser limits in addition to Laravel.
- Application limits remain authoritative for business flows.

---

# 25. Order and Inventory Integrity

## 25.1 State machine

Administrators may select any different local status (`nouvelle`, `tentative_1`,
`tentative_2`, `tentative_3`, `confirmee`, or `annulee`) to correct the
operational record. Each change is optimistic-lock protected and appended to
the immutable status history. The usual contact path is:

```text
nouvelle -> tentative_1 -> tentative_2 -> tentative_3 -> confirmee
```

Changing to `annulee` restores stock once, only after any active Navex parcel
has been cancelled. Re-activating an annulled order reserves the exact order
items again in the same transaction and fails safely if stock is no longer
available. Selecting the current status is rejected/no-op in bulk operations.

## 25.2 Terminal edits

Articles remain editable until a Navex shipment exists. Delivery details remain
editable for every local status except `confirmee`, unless a live Navex shipment
already exists; this prevents the local order from diverging from a parcel
already registered with the provider. Internal notes remain append-only.

## 25.3 Optimistic locking

Order update and transition require `lock_version`.

Stale version returns `409 ORDER_VERSION_CONFLICT`.

## 25.4 Stock restoration

Use a unique restoration record or equivalent idempotency marker.

Stock must not be restored twice.

## 25.5 Cancellation and Navex

`annulee` restores stock once. If the order already has a non-cancelled Navex shipment, Navex cancellation must be confirmed before the local cancellation can proceed.

## 25.6 Database constraints

Use:

- Foreign keys
- Unique constraints
- Check constraints where supported and reliable
- Non-negative stock checks
- Unique logical Meta Purchase per order
- Unique idempotency key
- Unique variant combination
- Unique normalized promo code

## 25.7 Audit

Record:

- Old order state
- New order state
- Acting user
- Reason
- Stock delta
- Total delta
- Request ID

Do not record full customer data repeatedly in audit JSON when not necessary.

---

# 26. Meta Pixel and Conversions API Security

## 26.1 Public and secret values

| Value | Classification |
|---|---|
| Pixel ID | Public configuration |
| CAPI access token | Secret |
| Test-event code | Sensitive configuration |
| Purchase trigger | Highly privileged configuration |
| `_fbp` and `_fbc` | Personal/attribution data |
| Event ID | Internal operational identifier |
| Hashed customer matching data | Personal data, still protected |

Hashing personal data for Meta does not make it anonymous.

## 26.2 Token storage

CAPI token MUST:

- Be encrypted using Laravel encryption
- Never be sent to Vue
- Never be included in an API response
- Be displayed only as configured/not configured and optional last four characters
- Never be written to logs, audit logs, Sentry, or backups in plaintext
- Be rotated after suspected exposure

## 26.3 Encryption key

`APP_KEY` must:

- Remain outside source control
- Be backed up securely
- Be available during disaster recovery
- Be rotated only through a documented key-rotation process

Loss of `APP_KEY` may make encrypted credentials unrecoverable.

## 26.4 Meta configuration update

Required flow:

1. Super Admin authenticated
2. Recent password confirmation
3. New values submitted
4. Safe test event sent
5. Test result linked to current user/session
6. Proposed values fingerprinted
7. Confirmation phrase required
8. Expected configuration version checked
9. New token encrypted
10. Activation committed atomically
11. Cache invalidated
12. Audit event stored without secret

## 26.5 Purchase-trigger change

Require:

- Super Admin
- Recent password confirmation
- Large warning
- Typed `CONFIRMER`
- Old/new value display
- Optimistic configuration version
- Audit log
- Future-order-only behavior

## 26.6 Outbound payload

Send only required and permitted fields.

Normalize and hash matching fields according to Meta requirements.

Never send:

- Admin notes
- Complaint information
- Internal audit data
- Credentials
- Unnecessary customer data

## 26.7 Event uniqueness

Database must guarantee at most one logical standard `Purchase` event per order.

Queue retries reuse the same event ID.

## 26.8 Queue behavior

- Send after database commit
- Use bounded exponential backoff
- Classify transient and permanent errors
- Do not retry deterministic validation errors indefinitely
- Move exhausted events to `permanent_failed`
- Allow protected manual retry only after corrective action
- Store sanitized response metadata only

## 26.9 Timeouts

Recommended starting values:

- Connect timeout: 5 seconds
- Total timeout: 15 seconds

Review against production behavior.

## 26.10 API version

Meta Graph API version must be pinned in configuration.

Do not use an unversioned endpoint.

Review before version deprecation.

## 26.11 Sentry and logs

Never send raw Meta request payloads containing:

- Phone
- Name
- Address
- `_fbp`
- `_fbc`
- IP
- User agent
- Token

Log event ID, order reference, response status category, attempt number, and safe error code.

---

# 27. Cryptography and Secret Management

## 27.1 Transport encryption

All external HTTP traffic must use HTTPS.

Support:

- TLS 1.2
- TLS 1.3

Disable obsolete protocols.

## 27.2 Data at rest

Mandatory:

- Password hashing
- Meta token encryption
- Encrypted off-site backups
- Provider disk encryption where available
- Restricted database and filesystem access

Operational customer fields may remain queryable in MySQL because phone and order details are needed for fulfilment and search.

Compensating controls:

- No public database access
- Least privilege
- Encrypted backups
- Restricted Admin access
- No raw PII in Sentry
- Retention rules in privacy document

## 27.3 Randomness

Use cryptographically secure generators for:

- ULIDs/UUIDs
- Signed tokens
- Password-reset or confirmation tokens
- Idempotency values generated by trusted clients
- Filenames
- CSP nonces

Do not use `uniqid()` for security-sensitive identifiers.

## 27.4 Secret locations

### Environment/deployment secret

- `APP_KEY`
- Database password
- Redis password/ACL secret
- Backup key
- Sentry auth token used during release upload
- Deployment SSH key
- Off-site storage credentials

### Encrypted database

- Meta CAPI token
- Other owner-editable secret integrations approved later

### Public database setting

- Meta Pixel ID
- Store phone
- Social links
- Public shipping amount

## 27.5 `.env`

Production `.env`:

- Never committed
- Never served by Nginx
- Restrictive permissions
- Outside shared artifact where possible
- Not printed by CI
- Not included in normal support bundles
- Not copied to Sentry

Non-Docker recommended permissions:

```text
owner: deploy
group: www-data
mode: 640
```

Adjust according to deployment design.

## 27.6 Secret rotation

Document rotation for:

- Meta CAPI token
- Database credential
- Redis credential
- Sentry auth token
- GitHub deployment key
- Backup credentials
- `APP_KEY`

`APP_KEY` rotation requires special planning because existing encrypted values and cookies depend on it.

---

# 28. HTTP Security Headers

## 28.1 Mandatory headers

Public and admin HTML responses:

```text
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
X-Frame-Options: DENY
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()
```

After HTTPS validation:

```text
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

Add `preload` only after:

- Every subdomain is permanently HTTPS
- Ownership accepts preload consequences
- Preload readiness is verified

## 28.2 Content Security Policy

Deploy in stages:

1. `Content-Security-Policy-Report-Only`
2. Fix violations
3. Enforce `Content-Security-Policy`

Recommended baseline template:

```text
default-src 'self';
base-uri 'self';
object-src 'none';
frame-ancestors 'none';
form-action 'self';
script-src 'self' 'nonce-{REQUEST_NONCE}' https://connect.facebook.net;
style-src 'self' 'nonce-{REQUEST_NONCE}';
img-src 'self' data: blob: https://www.facebook.com;
font-src 'self' data:;
connect-src 'self' https://www.facebook.com https://graph.facebook.com https://*.ingest.sentry.io;
media-src 'self';
worker-src 'self' blob:;
manifest-src 'self';
upgrade-insecure-requests;
```

The exact allow-list must be tested with:

- Meta Pixel
- Sentry browser SDK
- Vite production assets
- Image delivery
- Future CDN host

Rules:

- No blanket `*`
- Avoid `'unsafe-inline'`
- Avoid `'unsafe-eval'`
- Use request-scoped nonces for necessary inline script/style
- Bundle Sentry client code locally where practical
- Review every added external domain

## 28.3 Cross-origin isolation headers

Recommended where compatible:

```text
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
```

Do not enable `Cross-Origin-Embedder-Policy: require-corp` without testing because Meta and other third-party resources may break.

## 28.4 API responses

JSON endpoints:

```text
Content-Type: application/json
X-Content-Type-Options: nosniff
Cache-Control: no-store
```

CSP and X-Frame-Options are mainly relevant to document responses but may be applied consistently where harmless.

## 28.5 Sensitive response caching

Use:

```text
Cache-Control: no-store, private
Pragma: no-cache
```

for:

- Login
- Current user
- Orders
- Complaints
- Users
- Audit logs
- Meta configuration
- Private downloads

## 28.6 Server information

Hide:

- Nginx version
- PHP version
- Laravel exception details
- Internal hostnames
- Database details

---

# 29. TLS and Domain Security

## 29.1 HTTPS

- Redirect HTTP to HTTPS.
- No mixed content.
- Secure cookies only.
- Certificate auto-renewal tested.
- TLS expiry alert through Sentry deployment check or scheduled application check if no other external monitoring exists.

## 29.2 Admin domain

Preferred:

```text
admin.passioncosmetic.com
```

Security:

- Separate host-only session cookie
- No public indexing
- `X-Robots-Tag: noindex, nofollow`
- Login route only publicly accessible
- Optional IP allow-list MAY be added if business operations permit
- Cloudflare Access MAY be considered later but is not required

## 29.3 DNS

- Use registrar MFA
- Lock domain transfer
- Restrict DNS account access
- Enable DNSSEC if supported and operationally understood
- Document renewal ownership

---

# 30. Error and Exception Handling

## 30.1 Production behavior

Mandatory:

```text
APP_ENV=production
APP_DEBUG=false
```

## 30.2 Public errors

Return:

- Stable error code
- Safe French message
- Request ID

Do not return:

- Stack trace
- SQL
- File path
- Environment value
- Source code
- Secret
- Raw upstream response containing PII

## 30.3 Transactions

On exception:

- Roll back database transaction
- Do not dispatch after-commit jobs
- Do not leave partial stock changes
- Return safe conflict or server error
- Capture sanitized exception in Sentry

## 30.4 Exceptional conditions

Explicitly handle:

- Redis unavailable
- MySQL deadlock
- Stock conflict
- Promo usage conflict
- Duplicate idempotency key
- Sentry unavailable
- Meta timeout
- Meta invalid token
- Image processing failure
- Disk full
- Backup failure
- Queue worker crash
- Configuration decryption failure
- Invalid `APP_KEY`
- Expired confirmation token

## 30.5 Retry policy

Retry only when safe and idempotent.

Do not retry:

- Invalid credentials
- Validation failure
- Permission denial
- Invalid Meta payload without correction
- Insufficient stock
- Non-idempotent command without key

---

# 31. Logging Rules

## 31.1 Structured logs

Prefer JSON logs in production with:

- Timestamp
- Level
- Environment
- Release
- Request ID
- Route name
- User public ID where authenticated
- Safe resource ID
- Safe event code

## 31.2 Never log

- Password
- Password hash
- Session ID
- Cookie
- CSRF token
- Authorization header
- Meta token
- Database/Redis credential
- `APP_KEY`
- Full checkout request
- Full complaint body
- Full customer address
- Raw `_fbp` or `_fbc`
- Raw Meta payload
- File content

## 31.3 PII minimization

Where phone is needed for correlation:

- Use masked form
- Or use a stable keyed hash
- Do not log full phone

## 31.4 Log retention

Retention will be finalized in `privacy.md`.

Logs must have:

- Rotation
- Maximum disk usage
- Restricted permissions
- Secure deletion according to retention
- No indefinite storage

---

# 32. Audit Logging

## 32.1 Mandatory audited actions

- User creation
- User role/status change
- Password reset
- Product creation/update/status
- Category changes
- Stock changes
- Order edits
- Order transitions
- Complaint transitions
- Promo-code changes
- Shipping changes
- Checkout-field changes
- Content and policy changes
- Meta Pixel ID change
- Meta token replacement
- Meta trigger change
- Meta tracking enable/disable
- Manual Meta retry

## 32.2 Audit fields

- Actor user public ID
- Actor role
- Action
- Resource type
- Resource public ID
- Timestamp
- Request ID
- IP if approved
- Safe old values
- Safe new values

## 32.3 Audit restrictions

- Append-only through application
- No update endpoint
- No delete endpoint
- Super Admin read-only
- Database backup included
- Secrets redacted
- Avoid full PII snapshots

## 32.4 Tamper resistance

MUST:

- Restrict DB permissions
- No app API deletion
- Backup audit table

SHOULD:

- Add immutable storage or hash chaining later if risk increases

---

# 33. Sentry Security and Privacy

Sentry is the only external application-monitoring platform.

Local Nginx, PHP, Laravel, MySQL, and Redis logs still exist but are not separate external monitoring products.

## 33.1 Projects

Use separate Sentry projects or clearly separated environments for:

- Laravel backend
- Vue frontend

## 33.2 Environment and release

Every event should include:

- Environment
- Release identifier
- Server name or container role where safe
- Queue name where relevant
- Request ID

## 33.3 PII

Set:

```text
send_default_pii = false
```

Frontend and backend configuration must scrub:

- `password`
- `password_confirmation`
- `current_password`
- `capi_token`
- `access_token`
- `authorization`
- `cookie`
- `set-cookie`
- `xsrf`
- `phone`
- `address`
- `full_name`
- `customer`
- `fbp`
- `fbc`
- Complaint description
- Request bodies for checkout and complaints

## 33.4 `beforeSend`

Implement explicit redaction in Sentry SDK hooks.

The hook must:

- Remove sensitive headers
- Remove cookies
- Remove sensitive request body
- Mask customer identifiers
- Remove Meta payloads
- Remove database connection strings
- Remove local file contents

## 33.5 Frontend session replay

Sentry Session Replay SHOULD remain disabled initially.

If later enabled:

- Mask all text
- Block all media where appropriate
- Exclude checkout
- Exclude admin sensitive forms
- Obtain privacy approval
- Update privacy notice

## 33.6 Performance tracing

Use conservative sampling.

Do not include:

- Full query bindings with PII
- Request bodies
- Secrets
- Customer names or phones in transaction names

## 33.7 Alerts

Create Sentry alerts for:

- New production exceptions
- Repeated checkout failures
- Queue permanent failures
- Meta permanent failures
- Backup job failure
- Scheduler heartbeat failure if implemented
- High 5xx rate
- Authentication anomaly threshold
- Disk/storage errors surfaced by application

## 33.8 Source maps

Upload source maps using a CI secret.

Do not publish source maps publicly unless reviewed.

Use hidden source maps or Sentry artifact upload.

Delete build-time Sentry auth token after use.

---

# 34. Database Security

## 34.1 Network exposure

MySQL must not be publicly reachable.

Bind to:

- Localhost for non-Docker
- Private internal Docker network for Docker

No public port mapping.

## 34.2 Application database user

Use a dedicated application user.

Grant only required privileges on the application database.

Do not use:

- `root`
- Global privileges
- Remote `%` host unless unavoidable
- File privileges
- Grant option

## 34.3 Migration user

A separate migration/deployment user SHOULD be used if operationally practical.

It may have schema-change permissions not granted to the runtime user.

## 34.4 Strict mode

Enable strict SQL mode.

Reject silent truncation and invalid dates.

## 34.5 Constraints

Use database constraints as defense in depth.

## 34.6 Backups

Database backups must be:

- Encrypted
- Off-site
- Access-controlled
- Restorable
- Retention-managed

## 34.7 Query security

- No unbounded admin queries
- Pagination mandatory
- Export row limits
- Slow query review
- Indexes per system design
- No raw user-selected column names
- Query timeouts where practical

## 34.8 Sensitive database output

Database errors must not be sent to clients.

---

# 35. Redis Security

## 35.1 Exposure

Redis must never be internet-accessible.

Use:

- Localhost for non-Docker
- Internal Docker network for Docker
- Firewall protection
- No public port mapping

## 35.2 Authentication and ACL

Use Redis ACL with a dedicated application user where practical.

Restrict:

- Key patterns
- Commands
- Pub/Sub channels if unused
- Administrative commands

Disable or deny dangerous commands for the application user, including configuration and shutdown operations.

## 35.3 Credentials

- Strong secret
- Stored in environment/deployment secret
- Rotatable
- Not logged
- Not returned
- Not shared with unrelated applications

## 35.4 TLS

For same-host isolated communication, TLS is optional if the Docker/private network and host are secure.

TLS becomes mandatory if Redis traffic crosses a host or untrusted network.

## 35.5 Key namespaces

Use separate prefixes for:

- Cache
- Sessions
- Queues
- Rate limits
- Locks

## 35.6 Data handling

Do not cache:

- Plain passwords
- Meta tokens
- Full complaint attachments
- Unnecessary full checkout payloads

Session data in Redis is sensitive and protected by network and credential controls.

## 35.7 Persistence and memory

Configure:

- Memory policy reviewed for sessions/queues
- Queue data not evicted unexpectedly
- Persistence appropriate to queue/session needs
- Memory limits
- Alerting on failures through application/Sentry
- Safe restart behavior

---

# 36. Queue and Scheduler Security

## 36.1 Queue payload

Queue jobs should contain:

- Resource public/internal ID
- Approved scalar context
- Configuration version

Avoid serializing full customer records or secrets.

## 36.2 Queue names

Separate queues:

- `critical`
- `meta`
- `images`
- `exports`
- `default`

Suggested priority:

```text
critical > meta > default > images > exports
```

## 36.3 Worker identity

Queue worker runs as a non-root user.

## 36.4 Retry

Bound retries and backoff.

Failed jobs:

- Captured in database
- Sent to Sentry after threshold
- Do not expose payload secrets

## 36.5 Scheduler

Scheduler runs as a non-root user.

Scheduled tasks must use overlap prevention where relevant:

- Backups
- Sitemap generation
- Cleanup
- Failed-event reconciliation
- Health heartbeat

## 36.6 Command exposure

No web endpoint may execute arbitrary scheduled or Artisan commands.

---

# 37. Nginx Security

## 37.1 Required

- HTTPS redirect
- TLS configuration
- Security headers
- Server tokens disabled
- Dotfiles denied
- `.env` denied
- Composer/NPM files denied
- Storage-private paths denied
- No directory listing
- Only `public/` is the Laravel document root
- PHP execution only through front controller and approved paths
- Request-body limits
- Timeouts
- Static asset caching
- Admin `noindex`

## 37.2 Sensitive path denial

Deny direct access to:

```text
/.env
/.git
/composer.json
/composer.lock
/package.json
/package-lock.json
/storage
/vendor
/node_modules
/tests
/docs
```

Most should not exist under public root, but denial is defense in depth.

## 37.3 PHP path handling

Prevent arbitrary PHP execution in upload directories.

Use safe `try_files`.

## 37.4 Request body

Recommended:

```text
client_max_body_size 256m
```

If complaint limit is 5 MB and product image limit is 10 MB, Nginx limit can remain 10 MB plus multipart overhead.

Use route-specific application limits.

## 37.5 Timeouts

Set bounded:

- Header timeout
- Body timeout
- Keepalive timeout
- FastCGI timeout

Avoid values that permit slowloris-style resource holding.

## 37.6 Trusted proxies

If Cloudflare or another proxy is used:

- Configure exact trusted proxy ranges
- Do not trust arbitrary `X-Forwarded-For`
- Use provider-maintained IP lists
- Verify origin protection
- Ensure rate limits use the real validated client IP

---

# 38. PHP and Laravel Production Configuration

## 38.1 Laravel

Mandatory:

```text
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning or reviewed production level
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

Run:

```text
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Only if compatible with the final code.

## 38.2 Debug tools

Do not install or enable in production:

- Laravel Debugbar
- Telescope unless separately secured and justified
- Ignition debug pages
- Development mail viewers
- Database web consoles
- phpMyAdmin exposed publicly

## 38.3 PHP

Recommended:

- `display_errors=Off`
- `log_errors=On`
- `expose_php=Off`
- Restrictive upload limits
- Memory limits
- Execution time limits
- Session cookie settings enforced
- OPcache enabled
- Dangerous unused functions reviewed

Do not disable functions blindly if it breaks dependencies; document and test.

## 38.4 Dependencies

Production install:

```text
composer install --no-dev --classmap-authoritative --no-interaction
```

Do not run Composer as root in a way that executes untrusted plugins.

---

# 39. Frontend Security

## 39.1 Dependency minimization

Use the minimum required packages.

Avoid adding packages for simple functions already provided by Vue/Laravel.

## 39.2 Token storage

Do not store authentication tokens in:

- `localStorage`
- `sessionStorage`
- IndexedDB

Use Secure HttpOnly session cookies.

The guest cart may use `localStorage` because it contains no trusted prices and no authentication secret.

## 39.3 Cart storage

Cart storage may contain only:

- Product public ID
- Variant public ID
- Quantity
- Non-authoritative display metadata if needed

Do not store:

- Customer phone
- Address
- Meta token
- Admin data
- Trusted price
- Session identifier

## 39.4 DOM XSS

- No direct `innerHTML`
- No unsafe `v-html`
- No JavaScript URLs
- Validate external links
- Use CSP nonce
- Keep third-party scripts minimal

## 39.5 Meta Pixel

Load only when:

- Tracking enabled
- Consent rule permits
- Pixel ID validated

Pixel ID is treated as data, not script content.

Do not construct arbitrary script URLs from settings.

## 39.6 Build

- Production mode
- No dev source server
- No debug flags
- Source maps uploaded privately to Sentry where used
- No environment secrets embedded in Vite variables

Remember: variables prefixed for Vite exposure are public.

---

# 40. Sentry and Frontend Data Boundaries

Frontend Sentry must not collect:

- Checkout form values
- Complaint form values
- Admin password fields
- Meta token fields
- Cookies
- XSRF token
- Full URL query strings containing personal data

Do not put PII in URLs.

Use route templates for transaction names, not raw identifiers where avoidable.

---

# 41. Software Supply-Chain Security

## 41.1 Lock files

Commit:

- `composer.lock`
- `package-lock.json` or approved equivalent

Production and CI use locked installs.

## 41.2 Dependency audits

CI MUST run:

```text
composer audit
npm audit --audit-level=high
```

A critical or high production dependency vulnerability blocks release unless:

- Not exploitable in this context
- Documented
- Approved with mitigation and expiry

## 41.3 Dependabot

Enable weekly Dependabot updates for:

- Composer
- npm
- GitHub Actions
- Docker images where applicable

Security updates receive priority.

## 41.4 Package review

Before adding a package, review:

- Maintainer reputation
- Release activity
- License
- Security history
- Transitive dependencies
- Need versus native feature
- Download popularity only as a weak signal
- Whether it executes Composer/npm lifecycle scripts

## 41.5 Abandoned packages

Do not add abandoned or unmaintained packages without a documented replacement plan.

## 41.6 Integrity

- Use official registries
- Avoid packages installed from arbitrary Git branches
- Pin runtime images to tested versions
- Avoid mutable `latest` tags
- Review lock-file changes in PRs

---

# 42. GitHub Repository Security

## 42.1 Repository

- Private repository unless owner chooses otherwise
- Least-privilege collaborators
- MFA enabled for repository administrators
- Remove inactive collaborators
- No shared accounts

## 42.2 Branch protection

Protect `main`:

- Pull request required
- At least one human review
- CI checks required
- Conversation resolution required
- No force push
- No branch deletion
- Restrict direct pushes
- Codex-generated changes require human review

## 42.3 Secrets

Never commit:

- `.env`
- Private keys
- Meta token
- Database dump
- Production backup
- Customer export
- Sentry auth token
- Deployment credential

Use GitHub Environments for deployment secrets.

## 42.4 Secret scanning

Enable GitHub secret scanning and push protection where available.

Also run a repository secret scanner in CI if the selected tool is approved.

## 42.5 Security policy

Add:

```text
SECURITY.md
```

It should define private vulnerability-reporting contact and supported versions.

Do not require public disclosure of an unpatched vulnerability.

---

# 43. GitHub Actions Security

Both Docker and non-Docker deployment plans will use simple GitHub Actions CI.

## 43.1 Workflow permissions

Set top-level:

```yaml
permissions:
  contents: read
```

Grant additional permissions only to the job that needs them.

## 43.2 Pin actions

Third-party and GitHub actions SHOULD be pinned to full commit SHA.

Document the corresponding release tag in a comment.

## 43.3 Untrusted pull requests

- Do not expose secrets to PRs from forks.
- Avoid `pull_request_target`.
- Never checkout untrusted PR code in a privileged job with secrets.
- Separate CI from deployment.

## 43.4 Checkout

Use:

```yaml
persist-credentials: false
```

unless the job explicitly needs push access.

## 43.5 Shell safety

- Quote variables
- Do not evaluate PR title/body as shell
- Do not build shell commands from branch names without sanitization
- Use environment files safely
- Avoid `eval`

## 43.6 CI environment

CI uses:

- Fake application key
- Test database
- Test Redis
- No production credentials
- Meta HTTP client faked
- Sentry disabled or test DSN with no PII

## 43.7 Artifact safety

- Do not upload `.env`
- Do not upload test database with PII
- Set artifact retention
- Do not execute artifacts from untrusted workflows in privileged deploy jobs

## 43.8 Deployment environment

Use GitHub Environment:

- `production`
- Restricted secrets
- Optional required reviewer
- Deployment branch restricted to `main`

## 43.9 Minimal CI security checks

Required:

```text
composer validate --strict
composer install from lock file
composer audit
Laravel tests
Pest/PHPUnit
Larastan/PHPStan
Laravel Pint check
npm ci
npm audit --audit-level=high
TypeScript typecheck
ESLint
Frontend tests
Production build
Secret scan
```

## 43.10 Optional GitHub security features

Where available:

- Dependency Review
- CodeQL for JavaScript/TypeScript and GitHub Actions
- Dependabot alerts
- Secret scanning
- Push protection

CodeQL does not replace PHP static analysis.

---

# 44. Secure Deployment Principles

## 44.1 Immutable release

Each deployment should reference:

- Git commit SHA
- Release ID
- Build timestamp
- Dependency lock state

Do not deploy a dirty working tree.

## 44.2 Dedicated deploy user

- No root login
- SSH key only
- Restricted sudo
- Separate from web runtime user
- Key rotation
- No shared personal key

## 44.3 Deployment sequence

1. CI passes
2. Backup or restore point verified
3. New release deployed
4. Dependencies installed/built from locks
5. Migrations run with controlled user
6. Cache warmed
7. Health checks pass
8. Queue workers restarted gracefully
9. Sentry release recorded
10. Traffic switched or release symlink updated
11. Rollback available

## 44.4 Migrations

- Reviewed
- Backward-compatible where possible
- No destructive migration without backup and explicit approval
- Long locks avoided
- Data migration tested on production-like volume
- Rollback or forward-fix plan documented

## 44.5 Production commands

Sensitive commands require SSH/deployment access, not a web UI.

---

# 45. Docker Deployment Security Requirements

The detailed Docker deployment plan will be a separate document.

## 45.1 Images

- Pin base image versions
- Prefer official minimal images
- No `latest`
- Multi-stage build
- No build secrets in final layers
- Remove package caches
- Run vulnerability scan before release where practical

## 45.2 Runtime

- Non-root containers where supported
- No privileged containers
- Drop unnecessary Linux capabilities
- Read-only root filesystem where practical
- Writable volumes only where required
- Resource limits
- Health checks
- Restart policies
- Internal network for MySQL and Redis
- No public MySQL or Redis ports
- Docker socket not mounted into application containers

## 45.3 Secrets

- Compose environment file outside repository
- Restrictive permissions
- No secrets in image
- No secrets in Dockerfile `ARG` or `ENV` layers
- No secrets printed in build logs

## 45.4 Volumes

Persistent:

- MySQL
- Redis if persistence used
- Public media
- Private complaint files
- Backup staging if necessary

Permissions must match non-root runtime users.

## 45.5 Compose

- Separate production compose override
- No dev bind mounts
- No exposed debug ports
- No phpMyAdmin
- No mail catcher
- No Vite dev server

---

# 46. Non-Docker Deployment Security Requirements

The detailed non-Docker deployment plan will be separate.

## 46.1 Services

Use systemd for:

- PHP-FPM
- Nginx
- MySQL
- Redis
- Laravel queue workers
- Scheduler or system cron

## 46.2 Users

- `deploy` owns releases
- `www-data` runs PHP/Nginx as appropriate
- Queue worker uses restricted runtime user
- Database and Redis use their service users
- No service runs as root

## 46.3 Release directories

Recommended:

```text
/var/www/passion/releases/<release-id>
/var/www/passion/current -> releases/<release-id>
/var/www/passion/shared/storage
/var/www/passion/shared/.env
```

## 46.4 Permissions

- Code read-only to runtime
- Storage writable only where needed
- `.env` restricted
- Private uploads outside public path
- No world-writable directories
- Avoid `chmod 777`

## 46.5 Process management

Queue workers:

- Auto-restart
- Memory and time limits
- Graceful restart during deploy
- Logs rotated
- Sentry notification on repeated failure

---

# 47. VPS and Operating-System Hardening

## 47.1 SSH

- Disable root SSH login
- Disable password authentication
- Use SSH keys
- Restrict users
- Consider IP restriction where practical
- Rotate keys when access changes
- Protect private keys with passphrases

## 47.2 Firewall

Allow only:

- 80/tcp
- 443/tcp
- SSH from approved networks or rate-limited public access

Do not expose:

- MySQL
- Redis
- PHP-FPM
- Docker daemon
- Internal admin ports

## 47.3 Updates

- Supported LTS distribution
- Automatic security updates enabled or scheduled
- Reboot strategy documented
- Monthly dependency and OS review
- Critical patches expedited

## 47.4 Intrusion controls

`fail2ban` MAY protect SSH and obvious Nginx abuse.

Application rate limiting remains required.

## 47.5 Time

- NTP enabled
- Consistent UTC server time
- Application display timezone `Africa/Tunis`

Accurate time is required for:

- Sessions
- Audit
- Meta events
- TLS
- Backups
- Incident analysis

## 47.6 Filesystem

- Separate or monitored disk usage
- Log rotation
- Backup staging cleanup
- No public write permission
- Mount options such as `noexec` for upload/tmp locations MAY be used after compatibility testing

---

# 48. Backup and Recovery Security

## 48.1 Backup scope

Backup:

- MySQL
- Public product media
- Private complaint attachments
- Required shared configuration manifest
- Audit logs
- Redis only if needed for durable queue recovery; Redis cache need not be restored

## 48.2 Secrets and key recovery

Securely back up:

- `APP_KEY`
- Backup encryption key
- Off-site credentials recovery procedure
- Meta token remains in encrypted database backup

Do not include plaintext `.env` in a broadly accessible archive.

## 48.3 Off-site

Backups must leave the VPS.

Use S3-compatible or approved off-site storage.

## 48.4 Encryption

Encrypt backup before or during transfer.

Use TLS in transit.

## 48.5 Retention recommendation

Initial recommendation:

- 7 daily
- 4 weekly
- 6 monthly

Final retention must align with privacy requirements and business needs.

## 48.6 Restoration testing

- Test database restore at least quarterly
- Test full application restore before major launch and after backup architecture changes
- Record restore time and failures
- Never restore production PII into an insecure developer environment

## 48.7 Access

Backup credentials:

- Read/write only to required bucket/prefix
- No unrelated cloud permissions
- Rotatable
- Not shared with application if a dedicated backup identity can be used

## 48.8 Backup failure

Backup job failure must create a Sentry alert.

---

# 49. Privacy-by-Security Rules

The privacy document will define notice and retention.

Security implementation must:

- Collect only required checkout data
- Avoid customer accounts
- Avoid unnecessary analytics
- Respect consent state before Meta tracking
- Store attribution only as long as justified
- Limit Admin access to order fulfilment
- Keep complaint attachments private
- Redact monitoring data
- Avoid putting PII in URLs
- Provide a deletion/anonymization procedure where legally required without breaking financial/audit obligations
- Document third parties: Meta, Sentry, hosting, backup provider, optional CDN

---

# 50. Security Monitoring with Sentry

## 50.1 Required monitored conditions

- Application 5xx exceptions
- Checkout transaction failure
- Order stock conflict anomaly
- Queue permanent failure
- Meta permanent failure
- Backup failure
- Scheduled-task heartbeat failure
- Image processing failure
- Database connection failure
- Redis connection failure
- Authentication exception
- Repeated authorization denials above an anomaly threshold
- Decryption failure
- Disk-related application error

## 50.2 Security events not sent as full exception payload

High-volume expected events such as invalid login or rate limit should be aggregated or locally logged.

Do not flood Sentry with every rejected bot request.

## 50.3 Health checks

Provide protected or minimal health endpoints:

- Liveness: application process responds
- Readiness: required dependencies reachable

Health endpoints must:

- Reveal no versions or credentials
- Be rate-limited
- Return minimal status
- Be protected from public detailed diagnostics

---

# 51. Incident Response

## 51.1 Severity

### Critical

- Secret exposure
- Admin account takeover
- Unauthorized order/customer access
- Database compromise
- Active remote code execution
- Backup compromise
- Meta token abuse
- Mass order or stock manipulation

### High

- Stored XSS
- Authorization bypass
- Repeated failed Meta events caused by credential compromise
- Public private-file exposure
- CI/deployment compromise

### Medium

- Rate-limit bypass
- Non-sensitive information leakage
- Security-header regression
- Dependency vulnerability with limited reachability

## 51.2 Immediate actions

1. Preserve evidence
2. Restrict access
3. Disable affected credentials
4. Rotate secrets
5. Revoke sessions
6. Patch or roll back
7. Verify data integrity
8. Restore if necessary
9. Assess personal-data impact
10. Notify owner and required parties
11. Document timeline
12. Add regression test

## 51.3 Secret exposure response

If a secret appears in Git:

- Remove exposure from current files
- Rotate immediately
- Treat Git history as compromised
- Consider history rewrite only after rotation
- Invalidate old secret
- Review audit and access logs
- Add secret scanning rule

## 51.4 Vulnerability handling

- Do not disclose exploit details publicly before remediation
- Create private issue
- Assign severity and owner
- Patch supported releases
- Verify with tests
- Record fix release
- Review similar patterns

---

# 52. Security Testing Strategy

## 52.1 Every pull request

Required:

- Unit and feature tests
- Authorization tests
- Static analysis
- Formatting/lint
- Dependency audit
- Secret scan
- Frontend typecheck
- Production build

## 52.2 Critical workflow tests

### Authentication

- Generic invalid credentials
- Disabled user denied
- Rate limit
- Session regeneration
- CSRF required
- Session revoked on password reset
- Forced password change
- Last Super Admin protection

### Authorization

- IDOR/BOLA attempt
- Admin denied Meta/user/settings
- Wrong parent image denied
- Direct API endpoint manipulation denied
- Inactive resource behavior

### Checkout

- Client price ignored
- Stock race
- Idempotent retry
- Different payload same key rejected
- Promo limit race
- Shipping calculated server-side
- Duplicate Meta Purchase prevented
- Invalid custom field rejected
- Rate limiting

### Orders

- Only valid transitions
- Stale lock version
- Terminal edit denied
- Stock restored once
- Return restock decision
- Audit written

### Meta

- Token masked
- Token not logged
- Failed test does not activate
- Activation requires recent password
- Trigger change requires phrase
- Trigger snapshot
- Retry behavior
- Raw PII absent from diagnostic response

### XSS

Test malicious payloads in:

- Product name
- Product description
- Category
- Static page
- Complaint
- Internal note
- Banner URL
- Search

### Uploads

- Double extension
- Wrong MIME
- Polyglot attempt
- Oversized image
- Excessive pixels
- Corrupted file
- SVG rejection
- Private attachment authorization

### Headers

Automated tests verify:

- CSP
- HSTS in production
- nosniff
- frame denial
- referrer policy
- permissions policy
- secure cookie flags
- admin no-store

## 52.3 Staging security testing

Before launch:

- OWASP ZAP baseline or equivalent approved DAST against staging
- Manual authorization review
- Manual checkout manipulation
- Manual file-upload review
- Security-header scanner
- TLS scanner
- Dependency review
- Sentry scrubbing verification
- Backup restore test

Testing must not target production destructively.

## 52.4 Security regression

Every fixed vulnerability receives a regression test when technically feasible.

---

# 53. Performance and Security

Security controls must not unnecessarily damage performance.

## 53.1 Safe caching

Public cache may include:

- Active product cards
- Categories
- Homepage sections
- Static pages

Never cache publicly:

- Admin responses
- Order details
- Complaint details
- User details
- Meta configuration
- Session-bound pages

## 53.2 Cache poisoning prevention

Cache keys must use normalized server-derived values.

Do not vary on arbitrary headers.

Do not cache authenticated content as public.

## 53.3 Resource limits

Set limits for:

- Upload size
- Image pixels
- Request body
- Pagination
- Export rows
- Queue attempts
- Search length
- Variant combinations
- Rich text length
- Database query duration

## 53.4 Compression

Do not compress responses that mix attacker-controlled and secret data in a way that creates a practical side channel.

This application normally avoids returning secrets alongside public reflected input.

---

# 54. Configuration Validation

At deployment/startup, validate:

- `APP_DEBUG=false`
- HTTPS URL
- Strong `APP_KEY`
- MySQL not using root
- Redis configured
- Session/cache/queue use Redis
- Sentry environment/release set
- Meta token decryptable when configured
- Pixel ID format valid
- Purchase trigger valid
- Backup destination configured
- Storage writable
- Public/private disk separation
- Trusted proxy configuration explicit
- No wildcard CORS with credentials

A failed critical configuration check should fail deployment or mark readiness unhealthy.

---

# 55. Prohibited Implementation Patterns

Codex and developers must not:

- Trust browser prices or totals
- Use hidden UI as authorization
- Store auth token in local storage
- Commit `.env`
- Return CAPI token
- Log checkout bodies
- Call Meta inside checkout DB transaction
- Use `uniqid()` for security IDs
- Use `unserialize()` on request data
- Use raw HTML without sanitizer
- Use arbitrary user URLs for server fetch
- Use wildcard CORS with credentials
- Disable CSRF for admin API
- Use `chmod 777`
- Expose MySQL or Redis publicly
- Run application as root
- Mount Docker socket
- Use mutable `latest` images
- Deploy without lock files
- Ignore failed dependency audits silently
- Allow arbitrary sort/filter column names
- Hard-delete orders or audit logs
- Allow admin to change Super-Admin-only settings
- Reveal record existence through complaint responses
- Store customer PII in Sentry
- Enable production debug tools
- Use public complaint file URLs
- Allow PHP execution in upload directories
- Make security behavior dependent on Docker versus non-Docker deployment

---

# 56. Release-Blocking Security Checklist

Production release is blocked unless all items pass.

## 56.1 Application

- [ ] `APP_DEBUG=false`
- [ ] Authorization matrix implemented and tested
- [ ] CSRF enabled
- [ ] Secure session cookie
- [ ] Login throttling
- [ ] Password hashing reviewed
- [ ] Forced password reset works
- [ ] Last Super Admin protected
- [ ] Server-side checkout totals
- [ ] Idempotent checkout
- [ ] Stock concurrency tests
- [ ] Promo concurrency tests
- [ ] Order transition tests
- [ ] Audit logs
- [ ] Meta token encrypted
- [ ] Meta token redacted
- [ ] Meta event uniqueness
- [ ] Queue retries bounded
- [ ] Complaint files private
- [ ] Rich text sanitized
- [ ] Security headers enforced
- [ ] Admin responses `no-store`

## 56.2 Infrastructure

- [ ] HTTPS
- [ ] TLS 1.2/1.3
- [ ] HTTP redirect
- [ ] MySQL private
- [ ] Redis private
- [ ] Firewall enabled
- [ ] Root SSH disabled
- [ ] Password SSH disabled
- [ ] Non-root runtime
- [ ] `.env` protected
- [ ] Log rotation
- [ ] Off-site encrypted backup
- [ ] Restore test
- [ ] Sentry alerts
- [ ] Certificate renewal tested

## 56.3 CI and supply chain

- [ ] Protected main branch
- [ ] Required CI
- [ ] Human review
- [ ] Composer lock
- [ ] npm lock
- [ ] `composer audit`
- [ ] `npm audit`
- [ ] Static analysis
- [ ] Secret scan
- [ ] Actions pinned or reviewed
- [ ] Minimal workflow permissions
- [ ] No production secrets in PR jobs
- [ ] Dependabot enabled

## 56.4 Validation

- [ ] DAST baseline on staging
- [ ] Header scan
- [ ] TLS scan
- [ ] Authorization manual review
- [ ] Upload abuse test
- [ ] Checkout manipulation test
- [ ] Sentry PII scrub test
- [ ] Backup restore test
- [ ] Security acceptance signed off

---

# 57. Recommended Future Hardening

These are not required by the current scope but are recommended.

- TOTP MFA
- WebAuthn/passkeys
- Super Admin IP allow-list
- Cloudflare WAF and bot management
- Tamper-evident audit hash chain
- Database runtime/migration user separation
- Object storage for media
- Automated container scanning
- SBOM generation
- Automated DAST in CI/staging
- Customer-data retention automation
- Admin session-management UI
- Fine-grained security notifications
- Separate secret-management service
- Central immutable log archive

Each addition requires review for operational complexity.

---

# 58. Security Ownership

## 58.1 Developer

Responsible for:

- Secure code
- Tests
- Dependency updates
- Security documentation
- Secret handling
- Deployment guardrails

## 58.2 Super Admin

Responsible for:

- Strong account security
- Safe Meta credential changes
- User access review
- Content integrity
- Reporting suspicious behavior

## 58.3 VPS owner

Responsible for:

- OS updates
- SSH
- Firewall
- Backups
- TLS
- Service availability
- Key rotation

A person may hold multiple responsibilities, but the duties remain explicit.

---

# 59. Security Exceptions

A rule may be bypassed only when:

1. The exception is documented
2. The risk is described
3. Compensating controls exist
4. An owner approves it
5. An expiry date exists
6. A follow-up issue is created

Permanent undocumented exceptions are prohibited.

---

# 60. Normative References

This document is based on the current official guidance available during drafting:

- OWASP Top 10:2025
- OWASP Application Security Verification Standard 5.0.0
- OWASP API Security Top 10:2023
- OWASP Laravel Cheat Sheet
- OWASP REST Security Cheat Sheet
- OWASP Authentication Cheat Sheet
- OWASP Session Management Cheat Sheet
- OWASP CSRF Prevention Cheat Sheet
- OWASP File Upload Cheat Sheet
- OWASP HTTP Security Response Headers Cheat Sheet
- OWASP Cryptographic Storage Cheat Sheet
- OWASP Denial of Service Cheat Sheet
- Laravel 13 authentication, session, CSRF, hashing, validation, encryption, and rate-limiting documentation
- GitHub Actions secure-use documentation
- Redis ACL and TLS documentation
- Sentry data-scrubbing and privacy configuration documentation

Security standards and framework documentation must be rechecked before implementation because current recommendations can change.

---

# 61. Source of Truth and Conflict Resolution

This document is the source of truth for security controls.

Priority when documents conflict:

1. Explicit approved security exception
2. `security.md`
3. `roles-authorization-matrix.md`
4. `api-contracts.md`
5. `system-design.md`
6. `prd.md`
7. UI implementation

A conflict must be resolved explicitly. Codex must not silently choose the least secure interpretation.

---

# 62. Codex Security Instructions

When implementing any phase, Codex must:

1. Read this file first.
2. Identify applicable controls.
3. Add security tests in the same change.
4. Avoid broad refactors unrelated to the phase.
5. Report any requirement it cannot implement.
6. Never weaken a rule only to make tests pass.
7. Never introduce a dependency without explaining why.
8. Never expose a secret in examples, logs, fixtures, or screenshots.
9. Use synthetic test customer data.
10. Preserve deployment neutrality.
11. Treat security, performance, and data integrity as release criteria.
