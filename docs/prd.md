# Product Requirements Document

## ToutDispo E-Commerce Platform

**Document version:** 1.0
**Document status:** Initial approved scope
**Documentation language:** English
**Application interface language:** French only
**Target market:** Tunisia
**Primary payment method:** Cash on Delivery
**Planned platform:** Laravel backend with a Vue.js storefront and back office
**Deployment model:** Single VPS with logically separated public storefront and private back office

---

# 1. Product Overview

## 1.1 Product Summary

The product is a high-performance e-commerce platform for a cosmetics brand and shop.

The platform will replace the current generic store-builder implementation with a custom Laravel-based solution that provides:

* A simple French-language shopping experience
* Product and variant management
* Guest cart and checkout
* Cash-on-delivery order management
* Configurable storefront content
* Strong technical SEO
* A simple French-language back office
* Reliable Meta Pixel and Meta Conversions API tracking
* Detailed Meta event diagnostics and retry handling
* Strong security, performance, and maintainability

Customers will not need to create accounts. They will browse products, add items to their cart, provide delivery information, and submit a Cash-on-Delivery order.

The main technical differentiator is a controlled Meta tracking implementation combining browser-side Meta Pixel events with server-side Conversions API events.

## 1.2 Product Vision

Provide the owner with a normal, easy-to-manage e-commerce experience while delivering significantly more control, observability, and reliability for Meta advertising conversion tracking.

## 1.3 Primary Product Goals

1. Allow customers to discover and purchase cosmetic products easily.
2. Keep the shopping and checkout experience simple.
3. Give the owner full control over products, orders, storefront content, shipping, promotions, SEO, and Meta settings.
4. Provide reliable Meta `Purchase` event delivery through Pixel and Conversions API.
5. Prevent duplicate Meta events through event-ID deduplication.
6. Provide logs, retries, and diagnostics for Meta server events.
7. Deliver strong mobile performance and SEO.
8. Keep the operational workflow appropriate for a small cosmetics business.
9. Avoid unnecessary customer accounts, online payment complexity, and real-time infrastructure.
10. Keep the architecture maintainable and suitable for Codex-assisted implementation.

## 1.4 Non-Goals

The initial version will not include:

* Customer registration or login
* Customer profiles
* Online card payments
* Multiple currencies
* Multiple interface languages
* Product reviews or ratings
* Real-time chat
* WebSocket functionality
* Email order confirmations
* SMS notifications
* WhatsApp order notifications
* Marketplace or multi-vendor functionality
* Nested categories
* Advanced warehouse management
* Multiple shipping zones or city-based shipping prices
* Per-variant pricing
* Loyalty points
* Wishlists
* Product subscriptions
* Complex conditional checkout fields
* Per-customer promo-code limits
* Native mobile applications

---

# 2. Users and Personas

## 2.1 Customer

A visitor who wants to browse cosmetic products and place a Cash-on-Delivery order quickly.

### Customer needs

* Fast mobile storefront
* Clear product images and prices
* Simple product and variant selection
* Easy cart management
* Short checkout form
* No account creation
* Clear delivery cost
* Clear promotional pricing
* Immediate order confirmation
* Ability to submit a complaint

### Customer limitations

* Does not have an account
* Cannot log in
* Cannot view an order-history dashboard
* Cannot submit product reviews
* Cannot pay online in the initial version

## 2.2 Admin

A back-office user responsible for day-to-day product and order operations.

### Admin responsibilities

* Manage products
* Manage product variants
* Manage categories
* Manage stock
* View and process orders
* Edit eligible orders
* Change order statuses
* Add internal order notes
* Search, filter, print, and export orders
* Handle complaints related to orders
* View operational dashboard information

### Admin restrictions

The Admin cannot:

* Manage back-office users
* Reset user passwords
* Access or change Meta credentials
* Change the Meta Purchase trigger
* Manage security settings
* Manage infrastructure credentials
* Access unrestricted system configuration
* Delete audit history

## 2.3 Super Admin

The owner or highest-privileged back-office user.

### Super Admin responsibilities

The Super Admin can perform every Admin action and can additionally:

* Create, edit, disable, and remove back-office users
* Reset another user’s password without knowing the existing password
* Manage Meta Pixel and Conversions API configuration
* Change the Meta Purchase trigger
* Enable or disable Meta tracking
* Manage checkout fields
* Manage shipping settings
* Manage promo-code settings
* Manage storefront content
* Manage static pages and policies
* Manage SEO settings
* Manage contact and social information
* View audit logs
* Manage global store configuration

### Password-reset rule

The Super Admin may replace another user’s password without providing that user’s current password.

The existing password must never be displayed or recoverable.

The reset action must:

* Be recorded in the audit log
* Identify the acting Super Admin
* Identify the affected account
* Record the timestamp
* Never store the new password in logs

---

# 3. Application Structure

## 3.1 Public Storefront

The public storefront will be accessible through the primary domain, for example:

`https://passioncosmetic.com`

It will contain:

* Homepage
* Product catalogue
* Category pages
* Product-details pages
* Search results
* Cart
* Checkout
* Order-confirmation page
* Complaint page
* About page
* Contact page
* FAQ
* Terms and conditions
* Privacy policy
* Delivery policy
* Returns and complaints policy

The public interface will be French only.

## 3.2 Private Back Office

The back office will be logically separated from the storefront and protected by authentication.

Preferred access pattern:

`https://admin.passioncosmetic.com`

A `/admin` path may be used if deployment constraints make a subdomain unnecessary.

The back office will include:

* Authentication
* Dashboard
* Product management
* Category management
* Variant management
* Inventory management
* Order management
* Complaint management
* Storefront-content management
* Static-page management
* Shipping configuration
* Promotion management
* Checkout-field management
* Meta configuration
* User management
* Audit logs
* SEO settings

The back-office interface will be French only.

## 3.3 Deployment

The storefront, back office, Laravel backend, database, cache, and queue workers may be hosted on the same VPS.

They remain logically separated even when deployed on the same server.

---

# 4. Product Catalogue

## 4.1 Product Fields

A product supports the following fields.

### Required fields

* Name
* Category
* Regular price
* Stock when the product has no variants
* Active/inactive status

### Optional fields

* Short description
* Full description
* Promotional price
* Product gallery
* Variant groups
* Variant combinations
* Low-stock threshold
* SEO title
* SEO description
* Custom slug

### Automatically generated field

* Slug, generated from the product name and editable by an authorized user

## 4.2 Product Status

### Active product

An active product:

* Is visible on the public storefront
* Appears in eligible homepage sections
* Appears in category pages
* Appears in search results
* Can be added to the cart
* Can be purchased

### Inactive product

An inactive product:

* Remains stored in the back office
* Is hidden from public browsing
* Cannot be added to the cart
* Cannot be purchased
* Remains visible in historical orders and reports

## 4.3 Product Pricing

Each product has one regular price.

A product may have one promotional price.

Variant combinations inherit the product-level regular and promotional prices.

The promotional price must:

* Be greater than or equal to zero
* Be lower than the regular price
* Be ignored when empty

When a promotional price exists, the storefront displays:

* The regular price
* The promotional price
* An automatically calculated discount-percentage badge

Example:

* Regular price: 100 TND
* Promotional price: 80 TND
* Displayed badge: `-20%`

The badge must appear consistently on:

* Homepage product cards
* Custom product sections
* Category pages
* Search results
* General product listings
* Product-details pages

## 4.4 Categories

Categories remain flat.

The system will not support parent categories or subcategories.

Each product belongs to exactly one category.

Authorized users can:

* Create categories
* Edit categories
* Activate or deactivate categories
* Reorder categories
* Delete categories directly

A category can be removed directly. Its catalog products are soft-deleted in the same operation, while historical orders and their product snapshots remain readable.

## 4.5 Variants

A product may have:

* No variant groups
* One variant group
* Multiple variant groups

Example variant groups:

* Color
* Size
* Volume
* Packaging

Example:

* Color: Green, Blue
* Size: Small, Medium

The system supports multiple values for each variant group.

Each resulting variant combination may have:

* Its own stock
* Its own optional image
* Its own internal identifier or SKU if introduced in the system design
* Active or unavailable state if required by the implementation

Prices remain product-level and do not vary by combination.

## 4.6 Variant Image Behaviour

A variant combination may optionally have an image.

When the customer selects a variant with an assigned image:

* The variant image becomes the main product image
* Other product images remain accessible in the gallery
* Desktop thumbnails are displayed vertically
* Mobile thumbnails are displayed horizontally

When the selected variant has no image:

* The current product image remains displayed, or
* The default primary product image is used

A customer must select all required variant values before adding a variant-based product to the cart.

---

# 5. Homepage and Storefront Content

## 5.1 Default Homepage Sections

The homepage is a mobile-first, server-rendered Blade page. Vue is used only for approved interactive islands such as the hero carousel, search, cart quantity badge, and optional horizontal enhancements.

The default homepage supports the following section order:

1. Announcement bar
2. Main header
3. Hero carousel
4. Circular category explorer
5. Best-sellers introduction
6. Featured products
7. Large visual category tiles
8. Must-haves editorial section
9. Quality and reassurance
10. Social gallery
11. Brand and SEO content
12. Footer
13. Optional floating cart

Functional requirements:

* The announcement bar displays configurable business or delivery messaging from backend settings.
* The main header exposes Accueil, Boutique, dynamic category or ritual links, search, and a cart quantity badge.
* The hero carousel supports configurable slides, separate desktop and mobile images, limited active slides, and a slow reduced-motion-aware autoplay.
* The circular category explorer uses back-office-managed categories and images.
* Featured products show configured promotional content and respect product-variant behavior.
* Reassurance content uses only approved business claims.
* Social gallery images link only to approved social URLs.
* Brand and SEO content is editable in French and remains server-rendered.
* The optional floating cart must not obscure checkout actions, cookie controls, or the footer.

## 5.2 Custom Product Sections

The Super Admin can create custom homepage sections.

Example sections:

* Promotions
* Best sellers
* Summer selection
* Recommended products
* New collection

For each custom section, the Super Admin can:

* Enter a section title
* Activate or deactivate the section
* Reorder the section
* Select products manually
* Reorder selected products
* Enable or disable filter controls

## 5.3 Storefront Content Management

The Super Admin can manage storefront content without modifying code.

This includes:

* Homepage section order and activation state
* Hero slide content and images
* Promotional images
* Announcement-bar text
* Category explorer imagery and labels
* Editorial and product section content
* Footer content
* Phone number
* Email address
* Physical address
* WhatsApp link
* Social-media links
* Static-page SEO titles
* Static-page SEO descriptions

## 5.4 Content Consistency

Values such as delivery fees and free-shipping thresholds must be stored once and reused throughout the application.

The platform must not require the owner to manually update the same business value in multiple unrelated pages.

---

# 6. Search, Filtering, and Product Discovery

## 6.1 Global Search

A search field must remain accessible in the storefront top bar.

It searches:

* Product names
* Category names

Selecting a product result opens the product-details page.

Selecting a category result opens the category page.

## 6.2 Product Filters

The initial product filtering scope includes:

* Category
* Price range
* Promotional products only

## 6.3 Sorting

Product results can be sorted by:

* Newest
* Price ascending
* Price descending
* Name

## 6.4 Category Pages

Selecting a category opens a dedicated category page.

The category page:

* Displays all active products in that category
* Supports price filtering
* Supports promotional-product filtering
* Supports the agreed sorting options
* Includes SEO metadata and an indexable URL

## 6.5 Section Filters

Each configurable product section can independently enable or disable its filtering interface.

---

# 7. Cart

## 7.1 Cart Type

The system uses a guest cart.

No customer account is required.

## 7.2 Cart Persistence

The cart is stored in the browser and:

* Survives page refreshes
* Survives browser reopening
* Expires after seven days

## 7.3 Cart Actions

Customers can:

* Add products
* Add selected product variants
* Change quantities
* Remove items
* Open the cart from the top bar

The top-bar cart icon displays the total item quantity.

## 7.4 Stock Behaviour

The cart does not reserve stock.

Stock is validated again:

* When checkout is opened
* When the order is submitted

The customer cannot submit quantities exceeding available stock.

## 7.5 Server Authority

The browser cart is not trusted for:

* Product prices
* Promotional prices
* Stock
* Discount amounts
* Shipping fees
* Final totals

Laravel must recalculate all values before creating the order.

Inactive, deleted, invalid, or unavailable products must be removed or rejected with a clear French message.

Example:

> La quantité demandée n’est plus disponible. Votre panier a été mis à jour avec le stock disponible.

---

# 8. Checkout

## 8.1 Checkout Type

Checkout is:

* Guest-only
* Cash-on-Delivery only
* Available without customer authentication

## 8.2 Default Checkout Fields

The default checkout fields are:

* Full name
* Phone number
* City
* Address

The city remains a free-text field.

## 8.3 Checkout-Field Customization

The Super Admin can:

* Add custom fields
* Edit custom fields
* Reorder fields
* Activate fields
* Hide fields
* Mark fields as required or optional

Supported custom-field types:

* Text
* Textarea
* Number
* Dropdown
* Radio
* Checkbox

Conditional field logic is excluded from the initial version.

## 8.4 Historical Checkout Data

Each order stores a snapshot of:

* Field labels
* Field types where necessary
* Submitted values

Later changes to the checkout form must not change historical orders.

## 8.5 Promo-Code Field Visibility

The promo-code field can be:

* Displayed
* Hidden

Only the Super Admin can control its visibility.

The initial deployment may keep it hidden until the owner decides to use promo codes.

## 8.6 Order Submission

When checkout is submitted, Laravel must:

1. Validate all fields.
2. Revalidate products and variants.
3. Revalidate stock.
4. Recalculate prices.
5. Validate the promo code when provided.
6. Calculate the shipping fee.
7. Calculate the final total.
8. Create the order transactionally.
9. Deduct stock.
10. Create the initial order status.
11. Create the Meta attribution and event records.
12. Dispatch required asynchronous Meta jobs.
13. Display the order-confirmation page.

---

# 9. Shipping

## 9.1 Fixed Delivery Fee

The store uses one fixed delivery fee for all products and orders.

The Super Admin configures the delivery fee.

## 9.2 Free-Shipping Threshold

The Super Admin may configure a free-shipping threshold.

When the eligible order subtotal reaches or exceeds the threshold:

* Delivery fee becomes zero
* The storefront displays free delivery

The threshold may be enabled, disabled, or modified.

## 9.3 Calculation Example

Configured values:

* Delivery fee: 8 TND
* Free-shipping threshold: 120 TND

Order subtotal of 90 TND:

* Delivery: 8 TND
* Final total: 98 TND

Order subtotal of 130 TND:

* Delivery: Free
* Final total: 130 TND

## 9.4 Consistent Display

The same configured values must be reused in:

* Announcement bar
* Cart
* Checkout
* Order summary
* Order-confirmation page
* Delivery policy
* Terms and conditions where applicable

---

# 10. Promo Codes

## 10.1 Promo-Code Scope

Promo codes:

* Are used only during checkout
* Apply percentage-based discounts only
* Have a global usage limit
* Do not have per-customer limits
* Can be active or inactive

## 10.2 Promo-Code Fields

A promo code includes:

* Code
* Percentage discount
* Global usage limit
* Current usage count
* Active/inactive status
* Optional start date
* Optional end date
* Optional minimum order amount

## 10.3 Usage Limit

The usage limit defines the total number of successful orders on which the code may be applied.

Example:

* Code: `BEAUTY20`
* Discount: 20%
* Usage limit: 200
* Current usage: 46

The code becomes unavailable after the usage limit is reached.

## 10.4 Active/Inactive Status

An active code can be used when all other rules pass.

An inactive code remains stored but cannot be used.

## 10.5 Concurrency Requirement

Promo-code usage validation and incrementing must be handled safely so concurrent checkout submissions cannot exceed the configured limit.

---

# 11. Order Lifecycle

## 11.1 Order Statuses

Primary flow:

`Nouvelle → Confirmée → Livrée`

Alternative outcomes:

`Nouvelle → Annulée`

`Confirmée → Échec de livraison`

`Livrée → Retournée`

## 11.2 Status Definitions

### Nouvelle

The customer has successfully submitted the order.

### Confirmée

The store has contacted the customer and accepted or confirmed the order.

### Livrée

The order has been successfully delivered.

### Annulée

The order was cancelled before confirmation.

Possible reasons:

* Customer cancellation
* Invalid phone number
* Customer unreachable during confirmation
* Duplicate order
* Suspected fraudulent order

### Échec de livraison

The order was confirmed but could not be delivered.

Possible reasons:

* Customer refused the parcel
* Customer did not answer the delivery agent
* Incorrect delivery information
* Delivery could not be completed

### Retournée

The order was delivered and later returned.

## 11.3 Allowed Transitions

Only valid transitions may be applied.

The backend must enforce transition rules even if a frontend request is manipulated.

## 11.4 Order Editing

Orders may be edited only while their status is:

* Nouvelle
* Confirmée

Editable information may include:

* Customer full name
* Phone number
* City
* Address
* Product quantities
* Selected variants
* Eligible custom checkout values

Orders become read-only after:

* Livrée
* Annulée
* Échec de livraison
* Retournée

Internal notes may remain appendable according to authorization rules.

## 11.5 Transactional Recalculation

When quantities or variants are changed, the system must:

1. Validate the new request.
2. Restore previous stock allocations.
3. Validate new stock availability.
4. Deduct new stock allocations.
5. Recalculate subtotal.
6. Recalculate promotional pricing.
7. Recalculate promo-code discount.
8. Recalculate shipping.
9. Recalculate final total.
10. Save the modification atomically.
11. Record all relevant changes in the audit log.

## 11.6 Order Deletion

Orders are archived first through the normal back office when an authorized operator explicitly confirms the action. Archiving removes the order from the active operational list while preserving its details and history.

Permanent deletion is a separate exceptional clean-up operation available only from the Archives view. It requires the order to already be archived and an explicit confirmation. Permanent deletion removes the order and its directly dependent operational records, including order items, checkout-value snapshots, notes, status history, idempotency records, restoration markers, and terminal Meta diagnostics.

The operation must refuse to delete an order when a linked Meta event is queued, sending, or scheduled for retry. Deleting a `Nouvelle` or `Confirmée` order restores its reserved stock before removal; fulfilled and terminal orders do not alter stock. Inventory-movement history is retained. The operation writes an append-only audit record containing only deleted order references and record counts. Meta events already accepted by Meta cannot be recalled.

Orders with active complaints may be deleted; the complaint remains and is safely unlinked from the deleted order.

---

# 12. Inventory

## 12.1 Stock Location

For products without variants:

* Stock is stored at product level.

For products with variants:

* Stock is stored per purchasable variant combination.

## 12.2 Stock Deduction

Stock is deducted immediately when a new order is successfully created.

## 12.3 Stock Restoration

Stock is restored automatically when an order becomes:

* Annulée
* Échec de livraison

For a returned order, the Admin must choose whether returned items are suitable for restocking.

This decision is required because opened or damaged cosmetic products may not be resellable.

## 12.4 Low-Stock Threshold

A product or variant may have an optional low-stock threshold.

The dashboard displays:

* Low-stock items
* Out-of-stock items

## 12.5 Insufficient Stock

Checkout must be blocked when stock is insufficient.

Stock checks and deductions must use database-safe concurrency controls to avoid overselling.

---

# 13. Order Confirmation

## 13.1 Customer Notifications

The initial version does not send:

* Email confirmations
* SMS messages
* WhatsApp messages

## 13.2 Confirmation Page

After successful order submission, the customer sees a confirmation page containing:

* Success message
* Order reference
* Customer name
* Phone number
* City
* Address
* Ordered products
* Quantities
* Selected variants
* Subtotal
* Promo-code discount when applicable
* Delivery fee
* Final total
* Payment method: Paiement à la livraison
* Message explaining that the shop may contact the customer
* Button to return to the homepage
* Button to continue shopping where appropriate

The page must not expose internal identifiers or sensitive implementation details.

---

# 14. Complaints

## 14.1 Customer Complaint Page

The storefront includes a dedicated complaint page.

No product review system is included.

The complaint form supports:

* Full name
* Phone number
* Optional order reference
* Complaint subject
* Complaint description
* Optional image attachment
* Required consent acknowledgement for personal-data submission

## 14.2 Complaint Statuses

Complaint lifecycle:

`Nouvelle → En cours → Résolue`

## 14.3 Back-Office Complaint Management

Authorized users can:

* View complaints
* Search complaints
* Filter complaints
* Link a complaint to an order
* Add internal notes
* Change complaint status
* View optional attachments

Complaint submissions and attachments must be validated and protected according to the security document.

---

# 15. Static Pages and Store Information

The storefront includes:

* About us
* Contact
* Terms and conditions
* Privacy policy
* Delivery policy
* Returns and complaints policy
* FAQ
* Social-media links
* WhatsApp link

The Super Admin can manage these pages and contact details without editing code.

Each static page supports:

* Page title
* Page content
* Active/inactive status
* SEO title
* SEO description
* Slug where relevant

---

# 16. Back-Office Dashboard

## 16.1 Dashboard Metrics

The dashboard includes:

* New-order count
* Confirmed-order count
* Delivered-order count
* Cancelled-order count
* Failed-delivery count
* Returned-order count
* Delivered-order revenue
* Best-selling products
* Low-stock products
* Recent complaints
* Meta event-delivery status
* Meta event failures

## 16.2 Date Filters

Dashboard metrics support:

* Today
* Last seven days
* Last 30 days
* Current month
* Custom date range

## 16.3 Revenue Definition

Operational revenue is calculated from orders with status `Livrée`.

Orders in `Nouvelle` or `Confirmée` do not count as realized revenue.

Meta may receive a `Purchase` event at another configured status, but that does not change the platform’s internal revenue definition.

---

# 17. Meta Pixel and Conversions API

## 17.1 Tracking Objective

The platform must provide the most reliable practical Meta conversion tracking possible without promising absolute equality between Meta Ads Manager and the order database.

The implementation combines:

* Browser Meta Pixel
* Server-side Meta Conversions API
* Matching event IDs
* Deduplication
* Customer and browser matching data
* Queue-based delivery
* Retry handling
* Idempotency
* Event logs
* Diagnostics
* Meta Test Events validation

## 17.2 Standard Storefront Events

The implementation may support standard events such as:

* PageView
* ViewContent
* Search
* AddToCart
* InitiateCheckout
* Purchase

The exact event matrix will be defined in the system design.

## 17.3 Purchase Trigger

The Meta `Purchase` trigger is configurable.

Available trigger statuses:

* Nouvelle
* Confirmée
* Livrée

Initial default:

`Nouvelle`

Therefore, in the initial configuration:

1. The customer submits checkout.
2. Laravel successfully creates the order.
3. The order receives status `Nouvelle`.
4. The browser sends `Purchase`.
5. Laravel queues the server-side CAPI `Purchase`.
6. Both use the same event ID.
7. Meta deduplicates them.

## 17.4 Purchase-Once Rule

A single order may produce the standard Meta `Purchase` event only once.

Changing the configured trigger:

* Applies only according to the safely defined activation policy
* Must not resend historical orders
* Must not produce another Purchase for an order already marked as sent
* Must not rewrite events already accepted by Meta

## 17.5 Delayed Purchase Trigger

If the trigger is changed to `Confirmée` or `Livrée`:

* No browser Purchase is sent during checkout
* Laravel stores the original attribution context
* Laravel sends the server-side Purchase when the selected status is reached
* The customer does not need to revisit the website

## 17.6 Attribution Context

The platform must preserve relevant attribution and matching data captured during checkout, subject to consent and privacy requirements.

This may include:

* `_fbp`
* `_fbc`
* Customer phone
* Customer name where permitted
* Customer location data where permitted
* IP address
* User agent
* Landing URL
* Referrer
* UTM parameters
* Original submission time
* Consent state

## 17.7 Meta Configuration

The Super Admin can manage:

* Meta Pixel ID
* Encrypted CAPI access token
* Tracking enabled/disabled state
* Purchase trigger
* Test mode
* Optional test-event code

## 17.8 Protected Trigger Change

Changing the Purchase trigger requires:

1. A prominent critical-setting warning
2. Selection of the new trigger
3. Re-entry of the Super Admin’s password
4. A typed confirmation phrase
5. A final modal showing the old and new values
6. Audit-log creation
7. Protection against accidental resending

Suggested warning:

> Critical Meta tracking setting. Changing this option affects which future orders are reported to Meta as purchases. An incorrect configuration may reduce campaign attribution quality or send conversions at the wrong stage. Existing Meta events will not be modified or resent.

## 17.9 Credential Update

When the owner changes their Meta Pixel or Meta dataset, the Super Admin can replace the integration credentials.

The update flow must:

1. Accept the new Pixel ID and token.
2. Test the new configuration.
3. Confirm Meta accepts a safe test event.
4. Require password confirmation.
5. Display the old and new Pixel IDs.
6. Activate the new configuration only after successful validation.
7. Record the change without logging the secret.
8. Keep the previous configuration active until the new one passes validation.

## 17.10 Secret Storage

Recommended storage model:

### Database settings

* Pixel ID
* Purchase trigger
* Tracking enabled status
* Test mode

### Encrypted database value

* Meta CAPI access token

### Environment or deployment secrets

* Laravel application key
* Database password
* Redis password
* Infrastructure credentials
* Other deployment-specific secrets

The CAPI token must never be:

* Sent to Vue
* Exposed to the browser
* Written to logs
* Stored in plaintext
* Committed to Git

---

# 18. SEO Requirements

## 18.1 Rendering

Public storefront pages must provide search-engine-readable HTML.

The final architecture may use:

* Laravel Blade
* Server-side rendering
* Another SEO-safe rendering strategy approved in the system design

A purely client-rendered storefront without an SEO strategy is not acceptable.

## 18.2 Product SEO

Each product supports:

* SEO title
* SEO description
* Editable slug
* Canonical URL
* Open Graph metadata
* Product structured data

Fallback values must be generated when optional SEO fields are empty.

## 18.3 Category SEO

Each category page requires:

* Unique URL
* Unique title
* Unique or generated description
* Canonical URL
* Indexable product content

## 18.4 Site-Wide SEO

The platform must support:

* XML sitemap
* Robots configuration
* Canonical URLs
* Open Graph tags
* Social-sharing images
* Product structured data
* Breadcrumb structured data
* Correct heading hierarchy
* Image alternative text
* French-language metadata
* Human-readable French slugs
* Redirect management for changed slugs
* Prevention of duplicate-content indexing
* Prevention of empty or inactive sections being indexed unnecessarily

---

# 19. Performance Requirements

Performance is a core product requirement.

## 19.1 General Principles

The platform must:

* Avoid real-time infrastructure
* Avoid unnecessary polling
* Avoid unnecessary frontend JavaScript
* Cache safe public data
* Queue slow background operations
* Optimize product media
* Minimize database queries
* Use appropriate database indexes
* Serve responsive images
* Lazy-load below-the-fold images
* Compress static assets
* Support CDN-based media delivery if later enabled

## 19.2 Performance Targets

Target public-storefront metrics at the 75th percentile on representative mobile traffic:

* Largest Contentful Paint: at or below 2.5 seconds
* Interaction to Next Paint: at or below 200 milliseconds
* Cumulative Layout Shift: at or below 0.1

Additional targets:

* Cached public pages should respond quickly under normal expected traffic.
* Checkout submission must not wait synchronously for Meta.
* Meta API calls must run through queues.
* Image processing must run asynchronously where applicable.
* Product and category queries must use appropriate indexes.
* Homepage and category content should be cacheable.
* Cache invalidation must occur when relevant content changes.

Exact load-test thresholds will be defined in the quality and system-design documents.

---

# 20. Non-Functional Requirements

## 20.1 Security

The platform must apply:

* Backend validation
* Authorization enforcement
* Secure authentication
* Rate limiting
* CSRF protection
* Secure session handling
* Password hashing
* Security headers
* Safe file-upload handling
* SQL-injection protection
* Cross-site scripting protection
* Audit logging
* Secret protection
* Encrypted sensitive configuration
* Least-privilege access
* Protected destructive actions
* Dependency monitoring
* Backup and restoration procedures

The complete requirements will be defined in the security document.

## 20.2 Reliability

* Orders must be created transactionally.
* Stock updates must remain consistent.
* Duplicate checkout submissions must be controlled.
* Meta jobs must support retries.
* Meta event sending must be idempotent.
* Failed jobs must be visible.
* Configuration changes must be auditable.
* Backups must be stored outside the primary VPS.
* Restoration must be testable.

## 20.3 Maintainability

* Business logic must not be hard-coded into Vue components.
* Authorization must be enforced in Laravel.
* Meta tracking must be isolated in a dedicated service layer.
* Order-status transitions must use centralized business rules.
* Important workflows must have automated tests.
* Configuration must be documented.
* Public APIs must follow defined contracts.
* Code must follow project quality rules.

## 20.4 Accessibility

The storefront should target WCAG 2.1 AA practices where practical, including:

* Keyboard accessibility
* Visible focus indicators
* Semantic HTML
* Form labels
* Error messages
* Sufficient color contrast
* Alternative text
* Accessible navigation
* Reduced-motion consideration

## 20.5 Browser Support

Support current stable versions of:

* Chrome
* Safari
* Firefox
* Edge

The mobile experience has priority because a significant part of advertising traffic is expected to come from mobile devices.

## 20.6 Responsive Design

The storefront and back office must support:

* Mobile
* Tablet
* Desktop

Important mobile requirements:

* Horizontal product-image carousel
* Large touch targets
* Simple checkout
* Sticky or easily accessible cart where appropriate
* Minimal layout shifts
* Fast image loading

---

# 21. Audit Logging

The platform must record sensitive and operationally important actions.

Examples:

* User creation
* User disabling
* Password reset
* Role change
* Product creation and update
* Stock adjustment
* Order edit
* Order-status transition
* Shipping-setting change
* Promo-code change
* Checkout-field change
* Meta trigger change
* Meta credential replacement
* Static-policy update

Audit records should include:

* Acting user
* Action
* Entity type
* Entity identifier
* Timestamp
* Relevant old values
* Relevant new values
* Request context where appropriate

Secrets and passwords must never be stored in audit logs.

---

# 22. User Journeys

## 22.1 Browse and Purchase a Product Without Variants

1. Customer opens the storefront.
2. Customer searches or browses a category.
3. Customer opens a product.
4. Customer reviews product images and price.
5. Customer chooses a quantity.
6. Customer adds the product to the cart.
7. Customer opens checkout.
8. Customer enters name, phone, city, and address.
9. Laravel validates and recalculates the order.
10. Laravel creates the order.
11. Stock is deducted.
12. Meta Purchase is sent according to the configured trigger.
13. Customer sees the confirmation page.

## 22.2 Purchase a Product With Variants

1. Customer opens a variant-based product.
2. Customer selects all required option values.
3. Product image changes when the selected variant has an image.
4. Customer adds the selected combination to the cart.
5. The system verifies that the combination exists and has stock.
6. Customer completes checkout.
7. The order stores the exact selected combination.

## 22.3 Search for a Product or Category

1. Customer uses the global top-bar search.
2. Results show matching products and categories.
3. Customer selects a product or category.
4. The system opens the correct public page.

## 22.4 Process a New Order

1. Admin opens the order dashboard.
2. Admin selects a `Nouvelle` order.
3. Admin reviews customer and product information.
4. Admin contacts the customer.
5. Admin confirms, edits, or cancels the order.
6. The system applies stock and total rules.
7. The system records the action in the audit log.

## 22.5 Change a Confirmed Order

1. Admin opens a `Confirmée` order.
2. Admin modifies customer details, quantities, or variants.
3. Laravel validates stock.
4. Laravel transactionally adjusts stock.
5. Laravel recalculates all monetary values.
6. The system saves the change.
7. The audit log records old and new values.

## 22.6 Mark Delivery Failure

1. Delivery fails after confirmation.
2. Admin changes the order to `Échec de livraison`.
3. Stock is restored automatically.
4. Dashboard metrics update.
5. The action is audited.

## 22.7 Process a Return

1. A delivered order is returned.
2. Admin changes status to `Retournée`.
3. Admin chooses whether items are resellable.
4. Stock is restored only when explicitly approved.
5. The action is audited.

## 22.8 Submit a Complaint

1. Customer opens the complaint page.
2. Customer enters the required information.
3. Customer optionally provides an order reference and attachment.
4. Laravel validates and stores the complaint.
5. Back-office users see it as `Nouvelle`.
6. An authorized user links it to an order when applicable.
7. Status changes to `En cours`, then `Résolue`.

## 22.9 Replace Meta Credentials

1. Super Admin opens Meta configuration.
2. Super Admin enters a new Pixel ID and CAPI token.
3. Laravel sends a safe test event.
4. The new configuration is validated.
5. Super Admin confirms with password.
6. The system activates the new configuration.
7. The previous secret is replaced safely.
8. The action is audited without storing the token.

---

# 23. Success Metrics

## 23.1 Commerce Metrics

The platform should measure:

* Number of submitted orders
* Confirmation rate
* Delivery rate
* Cancellation rate
* Delivery-failure rate
* Return rate
* Delivered revenue
* Average delivered-order value
* Best-selling products
* Product conversion rate where measurable
* Cart-to-checkout rate
* Checkout completion rate

Initial commercial targets should be established after baseline data is collected.

## 23.2 Meta Tracking Metrics

The platform should measure:

* Number of eligible Purchase events
* Number of queued events
* Number of accepted server events
* Number of failed events
* Retry count
* Permanent failure count
* Duplicate-prevention count
* Orders with missing attribution data
* Meta event acceptance rate
* Time between order trigger and successful CAPI delivery

Technical goals:

* One logical Purchase event maximum per order
* No duplicate server event caused by queue retries
* Failed Meta events remain diagnosable
* Temporary failures are retried automatically
* The majority of valid server events are accepted after retries
* Browser and server events share the same event ID when both are sent for the same purchase

## 23.3 Performance Metrics

Measure:

* LCP
* INP
* CLS
* Server response time
* Checkout response time
* Cache-hit rate
* Database-query count on key pages
* Image weight
* JavaScript bundle size
* Error rate
* Queue latency

## 23.4 Operational Metrics

Measure:

* Low-stock count
* Out-of-stock count
* Average order-confirmation delay
* Complaint volume
* Complaint-resolution time
* Failed queue jobs
* Backup status
* Application error rate

---

# 24. Acceptance Criteria Summary

The initial release is acceptable when:

1. Customers can browse active products.
2. Customers can search products and categories.
3. Category pages display and filter relevant products.
4. Product variants work correctly.
5. Variant images update the main gallery image.
6. Guest cart persists for seven days.
7. Laravel recalculates all prices and stock.
8. Customers can submit COD orders without accounts.
9. Stock is deducted transactionally.
10. Confirmation page displays correct order details.
11. Orders follow the approved lifecycle.
12. Orders can be edited only in eligible statuses.
13. Stock restoration follows cancellation, failed-delivery, and return rules.
14. Promo codes follow the approved simplified rules.
15. Shipping fee and free-shipping threshold work consistently.
16. Super Admin can manage storefront content.
17. Admin can manage products and orders.
18. Super Admin can manage users and Meta configuration.
19. Meta Purchase is sent according to the configurable trigger.
20. Browser and server Purchase events deduplicate correctly when both are used.
21. Meta server events are queued, logged, retried, and idempotent.
22. Meta credentials remain protected.
23. Public pages are SEO-readable.
24. Sitemap, metadata, structured data, and canonical URLs are available.
25. Public and private interfaces are French only.
26. The storefront performs well on mobile.
27. Complaints can be submitted and managed.
28. Important administrative actions are audited.
29. Orders cannot be permanently deleted from the active list; permanent deletion is guarded behind the Archives view.
30. Automated tests cover the critical commerce and Meta workflows.

---

# 25. Risks and Mitigations

## 25.1 Meta Attribution Differences

**Risk:** Meta Ads Manager may not exactly equal the order database.

**Mitigation:**

* Pixel and CAPI
* Event deduplication
* Stored attribution identifiers
* Correct customer-data normalization
* Event logs
* Test Events validation
* Clear distinction between submitted orders and delivered revenue

## 25.2 COD Order Quality

**Risk:** Submitted orders may later be cancelled or fail delivery.

**Mitigation:**

* Full order lifecycle
* Delivered-revenue dashboard
* Configurable Purchase trigger
* Separate internal analytics
* Potential future custom events for confirmed or delivered orders

## 25.3 Overselling

**Risk:** Concurrent customers purchase the last available items.

**Mitigation:**

* Server-side validation
* Database transactions
* Locking strategy
* Stock deduction during order creation
* Safe restoration rules

## 25.4 Accidental Meta Configuration Change

**Risk:** Super Admin changes the Purchase trigger or credentials incorrectly.

**Mitigation:**

* Critical warning
* Password confirmation
* Typed confirmation phrase
* Connection test
* Audit log
* Purchase-once rule
* No historical resend

## 25.5 VPS Failure

**Risk:** The single VPS becomes unavailable or loses data.

**Mitigation:**

* Off-server backups
* VPS snapshots
* Monitoring
* Documented restoration
* Queue recovery
* Deployment rollback plan

## 25.6 Performance Degradation

**Risk:** Large images, poor queries, or frontend bloat make the store slow.

**Mitigation:**

* Image optimization
* Server rendering
* Redis caching
* Database indexes
* Lazy loading
* Bundle limits
* Performance tests
* Query monitoring

---

# 26. Deferred Decisions

The following items will be finalized in later documents:

* Exact Laravel and Vue versions
* Blade, Vue SPA, Inertia, or SSR storefront choice
* Database schema
* Product-variant relational model
* Search implementation
* API endpoint structure
* Authentication implementation
* Queue driver
* Cache strategy
* Image-processing pipeline
* Meta payload schemas
* Consent implementation
* Backup provider
* Deployment pipeline
* Monitoring stack
* Detailed security controls
* UI visual identity
* Motion and transition specifications
* Complete automated-test matrix
* Exact infrastructure sizing

---

# 27. Document Dependencies

This PRD is the product-level source of truth.

The following documents must derive from it:

1. Business Rules
2. Roles and Authorization Matrix
3. System Design
4. API Contracts
5. Security Rules
6. Privacy Document
7. Design Specification
8. Quality Rules
9. Detailed Phase-by-Phase Implementation Plan

When another document conflicts with this PRD, the conflict must be resolved explicitly rather than silently implemented.
