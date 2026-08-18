# Privacy and Data-Handling Rules

## ToutDispo E-Commerce Platform

**Document version:** 1.0  
**Status:** Implementation baseline; legal review required before production  
**Documentation language:** English  
**Public privacy notice language:** French  
**Target market:** Tunisia  
**Related documents:** `prd.md`, `system-design.md`, `api-contracts.md`, `security.md`

---

# 1. Purpose

This document defines the platform's privacy and personal-data handling rules.

It is intentionally simple. It gives Codex and developers enough direction to build the platform without collecting unnecessary data or exposing customer information.

This file is not a substitute for legal advice. Before production launch, the store owner must confirm the final public privacy notice, Tunisian declaration requirements, foreign-transfer authorizations, legal retention periods, and third-party agreements with a qualified Tunisian privacy professional or the relevant authority.

---

# 2. Legal and Compliance Baseline

The implementation must be reviewed against the Tunisian personal-data framework applicable at launch.

The current baseline identified during drafting includes:

- Organic Law No. 2004-63 of 27 July 2004 on the protection of personal data
- Decree No. 2007-3004 of 27 November 2007 concerning declarations and authorizations
- Requirements of the Tunisian National Authority for the Protection of Personal Data, commonly referred to as INPDP
- Any later law, decree, authority decision, or reform that has entered into force before launch

The owner must verify whether the following are required before processing begins:

1. A prior declaration for customer and administrator data processing
2. Authorization for transfers of personal data outside Tunisia
3. Specific authorization or additional safeguards for Meta, Sentry, hosting, CDN, and backup providers
4. Written or recorded consent for advertising and Meta tracking
5. Required controller, processor, and representative information in the public notice

The application must not assume that configuring a third-party service automatically makes the transfer lawful.

---

# 3. Privacy Principles

The platform must follow these principles:

1. **Purpose limitation:** collect data only for clear business purposes.
2. **Data minimization:** do not collect information that the store does not need.
3. **Transparency:** explain what is collected, why, and with whom it is shared.
4. **Accuracy:** allow incorrect customer information to be corrected.
5. **Limited retention:** remove or anonymize data when it is no longer needed and legal retention has ended.
6. **Security:** protect data through the controls in `security.md`.
7. **Access limitation:** only authorized Admin and Super Admin users may access customer data.
8. **Consent control:** do not activate advertising tracking before the applicable consent condition is satisfied.
9. **No hidden reuse:** do not reuse order or complaint data for unrelated marketing.
10. **Accountability:** record important administrative actions without copying unnecessary personal data into audit logs.

---

# 4. Data Controller Information

The public privacy policy must identify the store's legal data controller.

The following values are placeholders until the owner provides them:

```text
Legal business name: [TO BE PROVIDED]
Legal representative: [TO BE PROVIDED]
Registered address: [TO BE PROVIDED]
Business registration number: [TO BE PROVIDED]
Privacy contact email: [TO BE PROVIDED]
Privacy contact phone: [OPTIONAL]
INPDP declaration/authorization references: [TO BE PROVIDED IF APPLICABLE]
```

The public policy must not be published with unresolved placeholders.

---

# 5. People Whose Data Is Processed

The platform may process data relating to:

- Customers placing guest orders
- People submitting complaints
- Admin and Super Admin users
- Visitors who consent to Meta advertising tracking
- Visitors whose technical errors are captured by Sentry under the approved configuration
- People contacting the store through published contact channels

There are no customer accounts in the initial version.

---

# 6. Data Inventory

## 6.1 Guest checkout and orders

Collected:

- Full name
- Phone number
- City
- Delivery address
- Products and variants ordered
- Quantities
- Prices, discounts, delivery fee, and order total
- Promo code, when used
- Order reference
- Order status and history
- Custom checkout answers enabled by the Super Admin
- Order notes entered by authorized staff
- Submission timestamp
- Technical request information needed for security and abuse prevention

Purpose:

- Create and fulfil Cash-on-Delivery orders
- Contact the customer to confirm or deliver the order
- Manage cancellation, failed delivery, returns, and complaints
- Prevent duplicate or abusive orders
- Maintain business, accounting, and audit records
- Produce internal operational statistics

The checkout form must not request:

- National identity number
- Passport number
- Date of birth
- Health information
- Religion
- Political opinion
- Bank or card details
- Password
- Unnecessary demographic information

## 6.2 Complaint data

Collected:

- Name
- Phone
- Optional order reference
- Subject
- Description
- Optional image attachment
- Consent acknowledgement
- Complaint status and internal notes

Purpose:

- Receive, investigate, and resolve complaints
- Associate a complaint with an order when appropriate
- Protect the rights of the store and customer in a dispute

Complaint attachments are private and never publicly accessible.

## 6.3 Back-office users

Collected:

- Name
- Email
- Role
- Active status
- Password hash
- Forced-password-change state
- Login and security timestamps
- Security and audit events
- Session records stored in Redis

Purpose:

- Authenticate authorized staff
- Enforce permissions
- Protect the platform
- Investigate administrative activity

The system never stores a recoverable plaintext password.

## 6.4 Meta attribution data

Collected only when the approved tracking and consent rules permit:

- `_fbp`
- `_fbc`
- Landing URL
- Referrer
- UTM parameters
- IP address
- User agent
- Consent state
- Event ID
- Event timestamps
- Hashed customer matching fields required for the approved Meta event

Purpose:

- Send and deduplicate approved advertising events
- Attribute advertising conversions
- Diagnose failed or duplicate events

The CAPI token is a secret credential, not customer data, and is encrypted separately.

## 6.5 Sentry error-monitoring data

May include, after mandatory scrubbing:

- Error type and stack trace
- Application release
- Route template
- Browser and device category
- Request ID
- Safe user or resource identifiers
- Queue and job metadata
- Performance traces under conservative sampling

Sentry must not receive:

- Passwords
- Cookies
- Session IDs
- CSRF tokens
- Meta tokens
- Full names
- Full phone numbers
- Addresses
- Checkout request bodies
- Complaint descriptions or attachments
- `_fbp` or `_fbc`
- Raw Meta payloads

`send_default_pii` or its framework equivalent must remain disabled.

## 6.6 Logs and security data

May include:

- Request ID
- Timestamp
- Route
- Response status
- Authenticated user public ID
- Safe resource public ID
- Masked or hashed abuse-prevention identifiers
- Rate-limit events
- Security-relevant failures

Logs must not contain full checkout or complaint payloads.

## 6.7 Guest cart

The guest cart is stored in the visitor's browser for seven days.

It may contain only:

- Product public ID
- Variant public ID
- Quantity
- Non-authoritative display data

It must not contain customer contact or delivery information.

---

# 7. Purposes and Processing Conditions

The platform processes personal data only for the following approved purposes.

| Purpose | Data | Operational condition |
|---|---|---|
| Create and fulfil an order | Checkout and order data | Necessary for the customer-requested transaction |
| Confirm delivery | Name, phone, address, order | Necessary for order fulfilment |
| Manage returns and complaints | Order and complaint data | Necessary to handle the request or dispute |
| Prevent abuse and fraud | IP, phone hash, request context | Necessary for platform and business security |
| Maintain legal/business records | Order snapshots and status | Required business or legal retention |
| Authenticate administrators | Admin identity and session data | Necessary for secure back-office access |
| Audit sensitive actions | Actor, action, safe old/new values | Necessary for accountability and security |
| Meta advertising measurement | Approved attribution and matching data | Only under the applicable specific consent and legal-transfer requirements |
| Error monitoring | Minimized technical diagnostics | Necessary for security and reliable service, subject to legal review and transfer safeguards |
| Backups and disaster recovery | Encrypted copies of necessary records | Necessary for resilience and integrity |

Data collected for an order must not automatically be used for promotional email, SMS, or WhatsApp marketing.

---

# 8. Consent and Tracking

## 8.1 Essential functionality

The following may operate without advertising consent, subject to legal review:

- Guest cart storage
- Checkout
- Order security controls
- Admin session and CSRF cookies
- Strictly minimized operational logging
- Strictly minimized Sentry error monitoring when approved as necessary

## 8.2 Meta advertising consent

Meta Pixel and CAPI advertising events must not be sent until the applicable consent requirement is satisfied.

The consent UI must:

- Be shown before non-essential advertising tracking
- Be written in French
- Explain that Meta receives advertising and conversion data
- Provide a clear accept option
- Provide a clear refuse option
- Avoid preselected acceptance
- Record the consent state and time
- Allow later withdrawal
- Keep the storefront usable after refusal

Refusal must not prevent ordering.

## 8.3 Consent storage

Store only:

- Consent category
- State
- Timestamp
- Policy/version identifier
- Anonymous or order-linked identifier where necessary

Do not store unnecessary fingerprinting data.

## 8.4 Withdrawal

After withdrawal:

- Stop future Meta Pixel events
- Stop future CAPI events that depend on consent
- Clear optional Meta browser identifiers where technically and contractually appropriate
- Preserve only records that must remain for legal, audit, or event-integrity reasons
- Do not claim that already received third-party data can always be deleted by the application itself

---

# 9. Cookies and Browser Storage

## 9.1 Essential admin cookies

Examples:

- Laravel admin session cookie
- XSRF cookie

Required properties:

- Secure
- HttpOnly for session cookie
- SameSite=Lax
- Short lifetime
- Host-only where possible

## 9.2 Guest cart storage

The cart uses browser local storage and expires after seven days.

The public privacy notice must disclose this.

## 9.3 Meta cookies and identifiers

Meta-related cookies or identifiers are optional advertising storage.

They may operate only after the approved consent condition is met.

## 9.4 Sentry browser storage

Avoid persistent Sentry identifiers where not required.

Session Replay remains disabled initially.

## 9.5 Cookie preference

The visitor must be able to revisit privacy preferences through a visible footer link such as:

```text
Gérer mes préférences de confidentialité
```

---

# 10. Third Parties and Recipients

Personal data may be available only to recipients required for approved purposes.

## 10.1 Internal recipients

- Super Admin
- Admin users handling products, orders, and complaints
- Authorized technical personnel strictly when necessary

## 10.2 External processors or recipients

Potential services:

- VPS hosting provider
- Off-site backup provider
- Sentry
- Meta
- Optional CDN or object-storage provider added later
- Professional advisers or authorities where legally required

Before activation, the owner must record for each provider:

- Legal entity
- Service purpose
- Data categories
- Hosting/processing countries
- Contract or terms
- Security measures
- Retention controls
- Deletion process
- Transfer authorization status
- Contact for privacy requests

No provider may be added only by inserting a frontend script without updating this inventory and the public privacy notice.

---

# 11. International Transfers

Meta, Sentry, backup storage, or hosting may process data outside Tunisia.

Before enabling such processing, the owner must:

1. Identify the destination country or countries.
2. Verify the applicable Tunisian legal requirements.
3. Determine whether INPDP authorization is required.
4. Complete required declarations or authorization requests.
5. Verify contractual and security safeguards.
6. Update the public privacy notice.
7. Record the decision in the project's privacy register.

Codex must not implement a setting that lets a Super Admin send personal data to an arbitrary external URL.

---

# 12. Data Retention

## 12.1 Retention rule

Data must be kept only while needed for:

- Order fulfilment
- Complaint handling
- Accounting or legal obligations
- Fraud and security investigation
- Audit
- Backup recovery

The exact statutory retention period for commercial and accounting records must be confirmed with a Tunisian accountant or lawyer before automated deletion is enabled.

## 12.2 Technical default schedule

The following is the recommended operational schedule, subject to legal approval:

| Data | Recommended retention |
|---|---|
| Guest cart | 7 days in browser |
| Signed confirmation token | 7 days |
| Checkout idempotency record | 7 days after creation |
| Raw application logs | 30 days |
| Sentry events | 30 days |
| Rate-limit counters | Automatic short Redis expiry |
| Raw Meta attribution fields | 90 days after terminal order status |
| Sanitized Meta event diagnostics | 13 months |
| Resolved complaints | 24 months after resolution, unless dispute requires longer |
| Audit logs | 24 months, unless legal/security requirement requires longer |
| Disabled administrator profile | 12 months after deactivation, while preserving required audit references |
| Order and accounting records | Statutory period to be confirmed before launch |
| Backups | 7 daily, 4 weekly, 6 monthly, then automatic expiry |

## 12.3 No premature automatic deletion

Until legal retention is confirmed:

- Do not automatically delete order records.
- Do not hard-delete financial history.
- Provide an anonymization or retention job only after approval.
- Continue minimizing logs, attribution data, and temporary records using the schedule above.

### Implemented Meta tracking retention

The scheduled `meta:prune-retention` command removes synthetic Meta test events
after 30 days, CAPI attempt history after 90 days, and sanitized non-Purchase
event diagnostics or permanent failures after 13 months. Purchase diagnostics
are not automatically deleted by this command. This schedule stores no raw
Meta cookies, raw telephone number, raw request body, outbound payload, or
Graph API response.

## 12.4 Legal hold

Deletion must pause for data connected to:

- Active complaint
- Legal dispute
- Fraud investigation
- Authority request
- Security incident

The legal-hold reason and release must be recorded.

---

# 13. Rights and Requests

The public policy must explain how a person may request:

- Access to their personal data
- A readable copy
- Correction or update
- Deletion where legally permitted
- Objection to processing
- Withdrawal of consent
- Information about recipients and foreign transfers
- Complaint escalation to the relevant Tunisian authority

## 13.1 Request channel

Use a dedicated privacy contact:

```text
[PRIVACY EMAIL TO BE PROVIDED]
```

## 13.2 Identity verification

Before disclosing or changing order data, verify identity proportionately.

Possible verification:

- Order reference
- Phone number used for the order
- Additional order information known to the requester

Do not request a national ID copy by default.

## 13.3 Response records

Maintain a private request register containing:

- Request date
- Type
- Verification status
- Decision
- Completion date
- Responsible person

Do not store more identity evidence than necessary.

## 13.4 Timeframe

The final policy must follow the legal response timeframe applicable at launch.

The current Tunisian baseline reviewed during drafting includes a one-month period for certain access/copy requests. Legal review must confirm how this applies to the store.

---

# 14. Children

The store is not designed to knowingly collect personal data directly from children.

The public policy should state that:

- A person under 18 should place an order with the involvement of a parent or legal guardian.
- The platform does not request age or date of birth.
- If the store learns that data was submitted by a child without the required authorization, it will review and remove it where legally permitted.

The exact child-consent wording must be legally reviewed because Tunisian law contains specific requirements for children's personal data.

---

# 15. Data Sharing Restrictions

The platform must not:

- Sell customer data
- Give order lists to unrelated advertisers
- Use complaint data for advertising
- Export customer data without authorization and a clear purpose
- Store customer exports indefinitely
- Share admin credentials
- Send personal data to Sentry beyond the approved minimized configuration
- Send Meta events after consent refusal
- Reuse order phone numbers for marketing without separate permission
- Expose customer information in public URLs

---

# 16. Data Export and Administrative Access

## 16.1 Order exports

Exports must:

- Require authentication and authorization
- Be audit-logged
- Include only requested fields
- Use a bounded date range
- Expire automatically
- Be stored privately
- Avoid unnecessary attribution or security data
- Be deleted after the configured short download period

Recommended export expiry:

```text
24 hours
```

## 16.2 Access control

- Admin may access orders and complaints required for operations.
- Super Admin has broader configuration access.
- Neither role may view plaintext passwords or Meta tokens.
- Audit logs should avoid full customer values.

## 16.3 Developer access

Production access is exceptional.

Developers must not copy production data to personal devices or development environments.

Use synthetic data for tests.

---

# 17. Security Incidents and Personal Data

When an incident may involve personal data:

1. Contain the incident.
2. Preserve evidence.
3. Identify affected data and people.
4. Revoke sessions and rotate exposed credentials.
5. Determine whether data was accessed, changed, or disclosed.
6. Consult the owner and legal adviser.
7. Determine authority or customer notification obligations.
8. Record actions and timeline.
9. Fix the root cause.
10. Add regression tests.

Do not promise a specific notification deadline in the public policy unless confirmed by applicable law at launch.

---

# 18. Backups and Deletion

Deleting a record from the active database does not immediately remove it from encrypted backups.

The public privacy notice should explain that:

- Backups are protected.
- Backups expire according to a fixed schedule.
- Deleted data is not restored into active use except during disaster recovery.
- If a backup is restored, approved deletions and consent withdrawals must be replayed where practical.

---

# 19. Privacy Requirements for Sentry

Mandatory configuration:

```text
send_default_pii = false
```

Also implement explicit SDK scrubbing.

Do not enable frontend Session Replay initially.

Sentry event retention should be configured to the shortest practical period, targeted at 30 days.

Before launch:

- Send synthetic checkout and complaint errors.
- Confirm no names, phones, addresses, cookies, tokens, or bodies appear.
- Verify source-map upload does not publish production source maps publicly.
- Document Sentry processing locations and transfer approval.

---

# 20. Privacy Requirements for Meta

Before Meta tracking is activated:

- Pixel ID and CAPI token are validated.
- Public French notice identifies Meta.
- Consent flow is implemented.
- Refusal is respected.
- Consent state is stored.
- Only required data is sent.
- Customer matching data is normalized and hashed as required.
- Raw payloads are not logged.
- Transfer authorization is confirmed.
- Event retention and deletion limitations are explained accurately.

The store must not promise that hashing makes data anonymous.

---

# 21. Privacy Requirements for Codex

Codex must:

- Use synthetic names, phones, and addresses in tests and fixtures.
- Never paste production data into prompts.
- Never generate logs containing full checkout requests.
- Never add analytics or tracking without an approved requirement.
- Never enable Sentry PII by default.
- Never expose private attachments.
- Never add customer accounts to simplify data handling.
- Never add age, gender, or profile fields without explicit approval.
- Preserve consent and retention rules when refactoring.
- Add privacy tests for Meta and Sentry redaction.

---

# 22. Public French Privacy Notice Requirements

The final public page must be in French and include:

1. Store/controller identity
2. Contact details
3. Data collected
4. Purposes
5. Mandatory and optional fields
6. Consequences of not providing required order data
7. Recipients and service providers
8. Meta tracking
9. Sentry error monitoring
10. Foreign transfers
11. Retention periods
12. Cookies and local storage
13. Consent withdrawal
14. Access, correction, deletion, and objection rights
15. Complaint contact and authority information
16. Children's data statement
17. Security overview
18. Policy version and effective date

The page must use clear language and not copy a generic foreign policy that does not match the implementation.

---

# 23. Pre-Launch Privacy Checklist

- [ ] Legal business identity completed
- [ ] Privacy contact completed
- [ ] French public policy reviewed
- [ ] Data inventory matches production
- [ ] INPDP declaration requirement checked
- [ ] Foreign-transfer authorization checked
- [ ] Meta transfer reviewed
- [ ] Sentry transfer reviewed
- [ ] Hosting and backup countries documented
- [ ] Consent banner implemented
- [ ] Meta blocked before consent
- [ ] Consent withdrawal works
- [ ] Sentry PII test passed
- [ ] Retention periods approved
- [ ] Privacy-request procedure assigned
- [ ] Export expiry implemented
- [ ] Private attachments verified
- [ ] Backup expiry verified
- [ ] No unresolved policy placeholders
- [ ] Policy version and effective date published

---

# 24. Source of Truth

This file is the implementation source of truth for privacy behavior.

The final public French policy must accurately reflect the implemented behavior. When implementation changes data collection, sharing, storage, or retention, both this file and the public policy must be updated before release.
