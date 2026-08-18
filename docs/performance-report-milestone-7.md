# Performance report — Milestone 7

## Historical measurements

The local production build was checked with Lighthouse 12.8.2 using the
Playwright Chromium binary against the server-rendered home page. After Laravel
configuration, route, event and view caches plus the Redis home-content cache
were warmed, Lighthouse reported:

- Performance: 96
- Accessibility: 99
- Best practices: 96
- SEO: 100
- FCP: 1.5 s
- LCP: 2.1 s
- TBT: 100 ms
- CLS: 0

The supplied baseline report recorded 73 performance, 4.2 s FCP and 4.3 s LCP.
This result is historical and directional only. It is not comparable with the
later Lighthouse 13.3.0 Slow 4G initial product-page run, because the page,
browser version and cache state differ. The reproducible navigation and
delivery investigation is recorded in
[`performance-navigation-investigation.md`](performance-navigation-investigation.md).

## Asset budgets

Run `npm run build` then `npm run check:asset-budgets` in CI.

- public storefront CSS: 80 KiB raw per `storefront-*` asset;
- all other CSS: 180 KiB raw per asset;
- JavaScript: 300 KiB raw per asset.

The current production build emits 51.82 KiB storefront CSS (12.53 KiB gzip),
28.73 KiB storefront JavaScript (8.88 KiB gzip), and a 210.98 KiB admin entry
that loads route pages dynamically.

## Implemented controls

- Redis-first settings and homepage-content caching with precise invalidation.
- Storefront catalogue cards eager-load categories and ready images; product
  detail-only relations are excluded from list queries.
- Cart quotes load only variants actually selected by the cart.
- The sidebar cart primes and briefly reuses its authoritative quote.
- The customer storefront has its own Vite CSS entry; the admin SPA route
  components are lazy loaded.
- Below-the-fold images are lazy loaded; primary product media keeps eager LCP
  loading and explicit dimensions.
- Product image listing has an index for its public ready/primary/sort lookup.

## Release procedure

Run `scripts/production-optimize.sh` from the release directory after the
environment file and built Vite assets are present. It migrates, refreshes
Laravel's deployment-safe caches and asks queue workers to reload without
assuming a specific process manager.

## Remaining hosting controls

The local Lighthouse run still reports server compression and long-lived static
cache headers as server responsibilities. Enable Brotli or gzip and immutable
cache-control headers for hashed Vite assets in the production web server/CDN.
Do not cache authenticated admin responses or HTML pages without the existing
application cache rules.
