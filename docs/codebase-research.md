# Codebase Research

**Scope:** implementation audit through Phase 9. This document records repository facts observed in source, migrations, route output, configuration, and automated tests. It does not claim that planned behavior exists unless code or tests show it.

## Research inputs and document availability

- Read repository guidance in [AGENTS.md](../AGENTS.md) and the available product, architecture, API, security, privacy, design, quality, and implementation-plan documents under `docs/`.
- `.specify/memory/constitution.md` is not present in this checkout.
- `docs/security.md` is the authoritative security control document and is titled `Security Rules and Controls`.
- [implementation-plan.md](implementation-plan.md) names Phases 0 through 17. Its Phase 9 is `Back-Office Order Operations` (line 1399); Phases 10 through 13 cover promotions/content, complaints, Meta, and diagnostics, respectively.

## Current architecture

- The application is a Laravel 12 modular monolith on PHP 8.2+. Dependencies include Sanctum, Predis, and `sentry/sentry-laravel`; the frontend uses Vue 3, Vue Router, Pinia, Vite, Tailwind, Vitest, and `@sentry/vue` ([composer.json](../composer.json), [package.json](../package.json)).
- Public pages are server-rendered Blade views routed by [routes/web.php](../routes/web.php) and rendered by [StorefrontCatalogController.php](../app/Http/Controllers/StorefrontCatalogController.php). `resources/js/storefront/main.ts` provides public interactive behavior.
- `/admin` is an authenticated Vue SPA shell served by `resources/views/admin/app.blade.php`; its client entry is [resources/js/admin/main.ts](../resources/js/admin/main.ts). Login is a Blade page.
- Domain code is grouped under `app/Domain/Catalog` and `app/Domain/Commerce`; HTTP controllers remain thin relative to actions/services such as [CreateGuestOrderAction.php](../app/Domain/Commerce/Actions/CreateGuestOrderAction.php).
- [bootstrap/app.php](../bootstrap/app.php) registers web/API/console routes, appends `AssignRequestId`, redirects non-API guests to login, and emits JSON `401` for unauthenticated API requests.

## Implemented modules

- **Catalog:** categories, products, product option groups/values, variants, image upload/processing, URL redirects, search suggestions, and Redis-backed storefront page caching. Key code: `app/Domain/Catalog/`, [ProductController.php](../app/Http/Controllers/Api/Admin/ProductController.php), and [StorefrontCatalogController.php](../app/Http/Controllers/StorefrontCatalogController.php).
- **Storefront:** home, product list, category, product detail, search, cart, checkout, and signed confirmation routes. Views are under `resources/views/storefront/`.
- **Commerce:** client-held cart data is re-quoted server-side, guest checkout creates order snapshots, decrements stock transactionally, and uses a checkout idempotency key. See [CartQuoteService.php](../app/Domain/Commerce/Services/CartQuoteService.php) and [CreateGuestOrderAction.php](../app/Domain/Commerce/Actions/CreateGuestOrderAction.php).
- **Admin operations:** category/product CRUD, media management, inventory adjustment/history, order listing/detail/editing, notes, status transitions, CSV export, bulk product lifecycle actions, and bulk order transition/archive/restore endpoints.
- **Not evidenced as implemented:** promotions/configurable shipping/content management, complaints, consent management, Meta Pixel/CAPI, dashboard/audit-log modules, and user-management APIs. No matching domain modules or routes exist; these are planned after Phase 9.

## Route and API inventory

### Web routes

| Area | Routes | Evidence |
|---|---|---|
| Storefront | `/`, `/produits`, `/produits/{slug}`, `/categories/{slug}`, `/recherche`, `/panier`, `/commande` | [routes/web.php](../routes/web.php) |
| Confirmation | `/commande/confirmee/{order}`, signed middleware | [routes/web.php](../routes/web.php) |
| Admin auth | `GET/POST /admin/login`, `POST /admin/logout` | [routes/web.php](../routes/web.php), [AdminAuthController.php](../app/Http/Controllers/AdminAuthController.php) |
| Admin SPA | `/admin` and `/admin/{path}` | [routes/web.php](../routes/web.php) |

### Public API, `/api/v1/public`

| Method/path | Controller | Rate limit |
|---|---|---|
| `GET /search/suggestions` | `PublicSearchController` | 60/min |
| `POST /cart/quote` | `CartQuoteController` | 60/min |
| `GET /checkout-fields` | `CheckoutFieldsController` | 30/min |
| `POST /orders` | `GuestOrderController` | 5 per 10 minutes |

Source: [routes/api.php](../routes/api.php). Health endpoints are `GET /api/health/live` and `/ready`, each throttled at 30/min.

### Admin API, `/api/v1/admin`

All current admin API routes use `web`, `auth`, and `can:catalog.manage` middleware ([routes/api.php](../routes/api.php)). They comprise:

- Category resource routes plus `POST categories/reorder`.
- Product list/create/show/update/destroy; product status; bulk status/archive/restore/force-delete; variant-mode and variant replacement.
- Product-image create/reorder/update/delete and product inventory adjustments; inventory movement list.
- Order list/export/show/update/items/transition/notes plus bulk transition/archive/restore.

`php artisan route:list --json` was used to inspect the registered route inventory.

## Database tables and relationships

| Area | Tables and key relationships |
|---|---|
| Identity | `users` has `public_id`, `role`, `is_active`, and `disabled_at`; migration [2026_07_18_000110_add_backoffice_access_fields_to_users.php](../database/migrations/2026_07_18_000110_add_backoffice_access_fields_to_users.php). Laravel default tables also create `password_reset_tokens` and `sessions`. |
| Catalog | `categories` and `products` both soft-delete. Removing a category soft-deletes its catalog products in the same transaction; historical order items retain their product snapshots. Product option groups cascade from products; option values cascade from groups; variants cascade from products; the variant/value pivot joins variants and option values. Product images cascade from products and may reference a variant. See [2026_07_18_000100_create_catalog_tables.php](../database/migrations/2026_07_18_000100_create_catalog_tables.php). |
| Inventory | `inventory_movements` references a product restrictively, optionally a variant restrictively, and optionally a user with `nullOnDelete`; it records delta, before/after values, type, and reason ([2026_07_18_000120_create_inventory_movements_table.php](../database/migrations/2026_07_18_000120_create_inventory_movements_table.php)). |
| Commerce | `checkout_fields`; `orders`; `order_items`; `order_checkout_values`; `order_status_history`; and `order_notes`. Order item product/variant links are nullable and `nullOnDelete`, while snapshot columns retain name, variant data, and prices. `orders.archived_at` is nullable and indexed. See [2026_07_18_000200_create_commerce_core_tables.php](../database/migrations/2026_07_18_000200_create_commerce_core_tables.php), [2026_07_18_000300_create_order_operations_tables.php](../database/migrations/2026_07_18_000300_create_order_operations_tables.php), and [2026_07_18_000700_add_archived_at_to_orders.php](../database/migrations/2026_07_18_000700_add_archived_at_to_orders.php). |
| Framework queues/cache | `cache`, `cache_locks`, `jobs`, `job_batches`, and `failed_jobs` come from Laravel foundation migrations. |

## Authentication and authorization

- Browser login validates email/password with `Auth::attempt`, rejects inactive users and roles outside `admin`/`super_admin`, regenerates the session, and is throttled `5,1` ([AdminAuthController.php](../app/Http/Controllers/AdminAuthController.php), [routes/web.php](../routes/web.php)).
- `CatalogPolicy::manage` authorizes active `admin` and `super_admin` users; it is bound as the `catalog.manage` Gate in [AppServiceProvider.php](../app/Providers/AppServiceProvider.php). That one Gate protects all currently registered admin APIs.
- API mutation clients send an `X-CSRF-TOKEN` value in the admin modules, while the admin API group includes `web` middleware. Examples: [products.ts](../resources/js/admin/products.ts), [orders.ts](../resources/js/admin/orders.ts).
- No customer-authentication routes or user-management routes are registered.

## Redis, queues, and Sentry

- [config/database.php](../config/database.php) defines `default` and `cache` Redis connections. Application cache calls use Laravel's default cache store, selected through `CACHE_STORE`; cache keys include `CatalogCacheVersion`.
- Cache, session, and queue drivers are selected through their environment-backed configuration entries ([config/cache.php](../config/cache.php), [config/session.php](../config/session.php), [config/queue.php](../config/queue.php)). Redis remains an available configured driver, not an application-level hard-coded dependency.
- `ProcessProductImage` and `DeleteProductImageFiles` implement `ShouldQueue`, are assigned to the `media` queue, and are dispatched after the image database transaction commits ([ProcessProductImage.php](../app/Jobs/ProcessProductImage.php), [DeleteProductImageFiles.php](../app/Jobs/DeleteProductImageFiles.php), [ProductImageController.php](../app/Http/Controllers/Api/Admin/ProductImageController.php)). `routes/console.php` schedules failed-job pruning daily.
- Sentry Laravel and Vue packages are installed, but no `Sentry::init`, Sentry config file, or Sentry provider/bootstrap call was found under `app/`, `bootstrap/`, `config/`, `resources/`, or `routes/`. Therefore package installation is verified; active Sentry integration is not.

## Catalogue, images, stock, cart, checkout, and orders

- Product prices are stored and transmitted as integer millimes. A product can have simple stock or variants, not both. Product creation/replacement validates option combinations and uses transactions ([CreateProductAction.php](../app/Domain/Catalog/Actions/CreateProductAction.php), [ReplaceProductVariantsAction.php](../app/Domain/Catalog/Actions/ReplaceProductVariantsAction.php)).
- Product variant mode changes use `lock_version`; changing from variants to simple stock requires an explicit resulting stock quantity ([SwitchProductVariantModeAction.php](../app/Domain/Catalog/Actions/SwitchProductVariantModeAction.php)).
- Product images accept JPEG, PNG, and WebP, stage originals on the local disk, then the queued job creates 480/768/1200 WebP renditions on the public disk. Product upload endpoints enforce a 2 MB per-image limit, dimensions, MIME verification, and a 20-million-pixel cap ([ProductImageController.php](../app/Http/Controllers/Api/Admin/ProductImageController.php), [ProcessProductImage.php](../app/Jobs/ProcessProductImage.php)).
- Inventory adjustments lock the product or its owned variant, reject negative stock, and write `inventory_movements` ([AdjustInventoryAction.php](../app/Domain/Catalog/Actions/AdjustInventoryAction.php)).
- The quote service only loads `Product::public()` products, validates requested variants and stock, derives promotional pricing server-side, and calculates configured shipping ([CartQuoteService.php](../app/Domain/Commerce/Services/CartQuoteService.php)).
- Checkout hashes a canonical payload, detects idempotency-key reuse with different content, snapshots active checkout fields and item data, locks products, decrements stock, writes movements, creates initial history, and creates `nouvelle` orders in a transaction ([CreateGuestOrderAction.php](../app/Domain/Commerce/Actions/CreateGuestOrderAction.php)).
- Controlled order transitions are `nouvelle -> confirmee|annulee`, then a contact-attempt path through `tentative_1`, `tentative_2`, and `tentative_3`, with cancellation available where permitted. There is no manual status bypass. Confirmation from either the detail page or a compatible bulk action uses the same deduplicated Navex automatic path. Order item edits lock the order/products/variants and reconcile stock transactionally before any Navex shipment exists ([TransitionOrderStatusAction.php](../app/Domain/Commerce/Actions/TransitionOrderStatusAction.php), [ReconcileOrderItemsAction.php](../app/Domain/Commerce/Actions/ReconcileOrderItemsAction.php)).
- Product lifecycle uses active/hidden status and soft-delete archive. Archived products can restore; force deletion is limited by the controller to archived products without order-item or inventory history ([ProductController.php](../app/Http/Controllers/Api/Admin/ProductController.php)). Orders use `archived_at` and support archive/restore rather than destruction ([OrderController.php](../app/Http/Controllers/Api/Admin/OrderController.php)).

## Automated tests and conventions

- The repository uses PHPUnit feature/unit tests with `RefreshDatabase`; current test files are under `tests/Feature/Catalog`, `tests/Feature/Commerce`, and `tests/Feature/Storefront`, plus `tests/Feature/FoundationHealthTest.php` and `tests/Unit/ExampleTest.php`.
- Verified test subjects include admin authorization, catalog search/media, product creation/variants, inventory adjustment/history filters, bulk order operations, cart quotes, guest checkout/idempotency, confirmation signatures, CSV export, order transitions/reconciliation, health, and public catalog rendering. See the file inventory in `tests/`.
- The frontend has one Vitest file, [admin-modules.test.ts](../resources/js/admin/admin-modules.test.ts).
- Code conventions observed: PSR-12 PHP namespaces; domain actions/services for transactions; Eloquent public ULIDs for externally addressed catalog/order records; validation inside controllers/actions; `DB::transaction` and `lockForUpdate` for stock/order mutations; strict TypeScript; Vue components defined in `.ts` modules; French UI messages. See [AGENTS.md](../AGENTS.md) and the referenced action/controller files.

## Incomplete or temporary implementation evidence

- [config/commerce.php](../config/commerce.php) explicitly says store-management settings will replace its safe defaults in Milestone 5. It currently reads fixed-fee and free-threshold values only from environment variables.
- Order-detail API data always returns Meta purchase status `not_configured` ([OrderController.php](../app/Http/Controllers/Api/Admin/OrderController.php)); no Meta configuration/event module was found.
- The current route/domain/file inventory has no promotions, complaints, consent, content sections/banners, shipping settings administration, dashboard, audit log, or user-management implementation.
- `resources/views/welcome.blade.php` is still the generated Laravel welcome page, but `/` is routed to the storefront controller rather than that view ([routes/web.php](../routes/web.php)).
- No application-level Sentry integration was found despite installed packages, as described above.
