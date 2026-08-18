# Repository Guidelines

## Project Structure & Module Organization

This repository contains a Laravel modular monolith with a Blade storefront, Vue 3 storefront islands, and a Vue 3 admin SPA.

- `app/Domain/`: business modules such as `Catalog`, `Checkout`, `Orders`, `Inventory`, `MetaTracking`, and `IdentityAccess`
- `app/Http/`: controllers, middleware, Form Requests, and API Resources
- `resources/views/`: server-rendered storefront pages
- `resources/js/storefront/`: small public Vue islands
- `resources/js/admin/`: admin SPA, routes, stores, and components
- `resources/css/`: shared design tokens and application styles
- `database/`: migrations, factories, and seeders
- `tests/`: Pest/PHPUnit unit, feature, and integration tests
- `docs/`: product, architecture, API, security, privacy, design, quality, and implementation guides

Before coding, read `docs/implementation-plan.md` and the relevant source-of-truth files.

## Build, Test, and Development Commands

Use the repository scripts when available:

```bash
composer install
npm ci
php artisan serve
npm run dev
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
npm run lint
npm run typecheck
npm run test:coverage
npm run build
composer audit
npm audit --audit-level=high
```

Tests must use the local MariaDB/MySQL-compatible service and Memurai/Redis-compatible service for database, locking, queue, cache, and session behavior. Do not replace these integration services with SQLite, array, file, or database queue fallbacks.

## Local Development Constraint

Do not use Docker or add Docker, Compose, Sail, container, or orchestration configuration for the current project phases. Run Laravel, XAMPP MariaDB, Memurai, queues, and front-end tooling directly on the local development environment. Revisit containerization only when it is explicitly planned and approved.

## Coding Style & Naming Conventions

Use PSR-12, Laravel conventions, strict TypeScript, and `snake_case` JSON fields.

- Classes: `PascalCase`
- Methods/variables: `camelCase`
- Database columns: `snake_case`
- Actions: `CreateGuestOrderAction`
- Form Requests: `CreateProductRequest`
- Tests: describe behavior, for example `it_rejects_an_invalid_order_transition`

Keep controllers thin. Put business rules in explicit actions/services. Never call `env()` outside configuration files.

## Testing Guidelines

Add tests in the same change. Critical workflows require feature and integration coverage: authorization, checkout idempotency, stock concurrency, order transitions, uploads, consent, Meta deduplication, and secret redaction.

Minimum targets are defined in `docs/quality-rules.md`. Do not reduce coverage or add broad static-analysis suppressions.

## Commit & Pull Request Guidelines

Use focused Conventional Commits, for example:

```text
feat(checkout): add idempotent guest order creation
fix(inventory): prevent duplicate stock restoration
```

Every pull request must explain scope, affected documents, security/privacy impact, migrations, API changes, tests, screenshots for UI changes, and rollback considerations.

## Security, Performance & Agent Instructions

Security, privacy, and performance are release requirements. Follow `docs/security.md`, `docs/privacy.md`, and `docs/design.md`.

### Milestone Branch Workflow

Implement all phases belonging to one documented milestone on one dedicated branch. Start the next milestone from the current `main` only after the previous milestone has been merged. The contributor runs the verification suite; the owner performs the milestone merge.

```text
milestone/03-commerce-core
fix/meta-event-deduplication
```

Keep each milestone branch focused. Do not merge a milestone into `main` without explicit confirmation that its phases' acceptance criteria, tests, security review, performance checks, and documentation updates pass. Use a separate `fix/*` branch only for an isolated correction that cannot safely remain on the milestone branch.

### Meta Pixel Reliability

Meta tracking is a critical checkout concern, not a cosmetic integration. Implement browser and server events only through the approved tracking boundary, preserve consent, generate stable `event_id` values, and deduplicate browser/server copies. Never emit purchase events from an unverified browser total. Test retries, delayed queues, duplicate requests, consent refusal, and secret redaction before approving a phase.

### Performance Baseline

Prefer server-rendered storefront pages, small lazy-loaded Vue islands, optimized responsive images, cache-aware queries, and measured third-party scripts. Do not add a client dependency, tracking tag, N+1 query, or synchronous external request without documenting its performance impact and verifying the relevant budget in `docs/quality-rules.md`.

Work one PR-sized vertical slice at a time. Do not skip phase gates, invent features, weaken authorization, trust browser totals, expose secrets, add real-time infrastructure, or introduce deployment-specific domain logic.

### Admin UI Consistency

For back-office work, follow `docs/design.md` section 70. Use the shared styled native `SelectControl` for every dropdown: it must preserve browser keyboard and touch reliability, responsive sizing, and consistent closed-control treatment. Do not introduce custom hover menus or page-specific dropdowns. Keep mobile navigation sticky with visible section labels and no horizontal scrolling. Scope sidebar styles to the direct sidebar only, preserve reduced-motion behavior, and keep print styles limited to operational information.

At completion, report:

- Files changed
- Decisions made
- Commands run and results
- Remaining issues
- Acceptance criteria status
