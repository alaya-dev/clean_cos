# Navigation performance investigation — 2026-07-25

## Scope and comparability

The older `96` Lighthouse result is not comparable with the reported product-page
result. It used Lighthouse 12.8.2 against a warmed homepage; the reported run
uses Lighthouse 13.3.0, Moto G Power emulation, Slow 4G and an initial product
page load. Navigation timings below are separate browser measurements and must
not be inferred from a Lighthouse score.

The pre-change commit cannot be checked out safely in this workspace because
the performance work is an uncommitted working-tree change. Instead, the
investigation measured the currently served production build before and after a
short-lived document-prefetch experiment. That experiment regressed navigation
on the local single-process server and was removed.

## Current production build and delivery facts

- Storefront manifest entry: `resources/css/storefront.css` →
  `build/assets/storefront-RM9A9z7d.css`.
- Raw CSS response/body: 69,001 bytes; Vite gzip estimate: 14.94 KiB.
- The product HTML uses only that storefront CSS entry; it does not load
  `resources/css/app.css`.
- `php artisan serve` responded with `HTTP/1.1`, no compression and no cache
  lifetime for Vite CSS or processed WebP images. It is not production-like for
  delivery performance.
- The product `h1` is present in the original Blade response and no product
  title or ancestor applies an opacity, visibility, display, transform, or
  entrance-delay rule. The reported render delay is therefore attributable to
  server response plus render-blocking CSS transfer, not delayed hydration or
  an H1 animation.

## Warm local server measurements

`curl` was run ten times per route after Laravel config, route, view and event
caches were rebuilt. The first response is included in the recorded samples;
the medians below describe the warm steady-state requests:

| Route | Median TTFB | Observed p95 including first request |
|---|---:|---:|
| `/` | 699 ms | 1,967 ms |
| `/produits` | 741 ms | 1,116 ms |
| `/produits/lait-corps-beurre-karite` | 713 ms | 1,235 ms |
| `/panier` | 545 ms | 806 ms |

These values miss the project target of sub-200 ms warm public TTFB. The local
PHP built-in server is a single process and serializes page, asset and
background-prefetch requests; a production PHP-FPM/Apache or PHP-FPM/Nginx
deployment with OPcache is required to validate the target.

## Browser navigation measurements

Five browser runs, same Playwright Chromium context and cached assets:

| Navigation | Median first visible content | Median DOMContentLoaded | Median warm TTFB |
|---|---:|---:|---:|
| Homepage → catalogue | 1,439 ms | 1,400 ms | 702 ms |
| Catalogue → product | 648 ms | 1,240 ms | 607 ms |
| Product → another product | 711 ms | 1,312 ms | 666 ms |
| Product → cart drawer | 122 ms | n/a | n/a |

`click-to-navigation-start` is browser scheduling at click time (effectively
immediate); the table records the next observable application milestones.
The first run of each set was materially slower (cold PHP worker/application
state), so it is retained in raw notes but not used as the median.

The headless browser run did not confirm bfcache restoration. Storefront code
does not register `beforeunload` or `unload`; bfcache must be rechecked in a
real Chrome/Firefox/Safari session with DevTools' Back/forward cache panel,
because the local harness and PHP server do not reproduce normal browser
process behavior.

## Changes retained

- Cart quote requests remain absent for an empty cart. A non-empty cart is
  revalidated only during an idle period, not 450 ms into the critical path.
- The drawer opens before quote revalidation and displays locally stored item
  name/image/quantity immediately when available. Quote totals remain server
  authoritative and replace this snapshot after the response.
- Marketing-consent state is applied from a local cache immediately and the
  server refresh runs when idle. On a first visit the explicit-consent banner
  is visible immediately; Meta remains disabled until explicit server-backed
  consent.
- Dashboard requests are cancelled when the dashboard route unmounts.
- After an idle dashboard period, only the likely next admin route chunks
  (`products` and `orders`) are imported, subject to Save-Data/2G checks.
- Apache static delivery rules set immutable one-year caching for hashed Vite
  assets, one-week caching for processed WebP/AVIF, and gzip when the relevant
  modules are enabled. They deliberately exclude application HTML, checkout,
  admin and API responses.

## Reverted experiment

Hover/focus document prefetch was tested locally. It made homepage → catalogue
slower (3.15 s visible, 1.41 s TTFB in the measured attempt) because the
single-process `php artisan serve` instance serialized the prefetch with the
real navigation. It was removed. Reconsider it only after production-server
TTFB and concurrent-request behavior are measured.

## Initial request behavior

| Request | Current behavior |
|---|---|
| `/cart/quote` | Never on an empty cart; idle-only for a non-empty cart; immediate on explicit cart interaction/mutation. |
| `/public/marketing-consent` | Deferred until idle; cached state is used immediately. |
| `/meta/pixel` | Only after accepted marketing consent; Meta script is never loaded beforehand. |

## Deployment controls still required

For Apache, `public/.htaccess` now provides the static-file rules. For Nginx or
Docker/Nginx, mirror them with `location /build/assets/` immutable one-year
cache headers, `location /storage/` one-week cache headers for processed public
images, `gzip on` (or Brotli) for CSS/JS/SVG/JSON and HTTP/2 or HTTP/3 at the
TLS edge. Do not cache authenticated admin HTML, checkout HTML, signed
confirmation pages or private APIs.
