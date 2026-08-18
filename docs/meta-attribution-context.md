# Meta attribution context

## Purpose

Meta CAPI delivery is asynchronous.  Attribution is therefore captured during
the originating storefront HTTP request, encrypted in `meta_events`, and
reused unchanged by every queued delivery attempt.  Queue workers never read a
live request, session, browser cookie, or header.

The integration uses Laravel's HTTP client rather than the Meta PHP Business
SDK. We evaluated Meta's official standalone
`facebook/capi-param-builder-php` package (1.3.1). It can extract request
context and return cookies to set, but it also appends its own language
appendices and generates `_fbp` when absent. That would change values we
currently preserve verbatim and would require another consent-aware adapter.
We therefore keep the smaller custom resolver and add only the required
server-side `_fbc` persistence. The resolver is covered by HTTP-fake tests;
the package is not a runtime dependency.

## Captured data

When tracking is enabled and marketing consent is valid, the encrypted snapshot
may contain:

- `_fbc`, unchanged when present and valid;
- `_fbp`, unchanged when present and valid;
- an `fbc` constructed from a valid `fbclid` only when `_fbc` is absent;
- the request IP after Laravel trusted-proxy resolution;
- the request user agent;
- a SHA-256 hash of a valid Tunisian checkout phone number for Purchase.

The event source URL retains scheme, host, optional port, and path. Query
parameters are deliberately omitted so campaign or customer data cannot leak
into diagnostics or the stored event record.

No `fbc` is invented for organic or direct traffic.  `fbc`, `fbp`, IP, user
agent, and source URL are never hashed.  No email or external ID is invented:
the current guest checkout does not collect a verified email or stable customer
identifier.

`_fbp` and `_fbc` are passed through Laravel's cookie encryption middleware
unchanged. When a valid `fbclid` arrives and the visitor already has current
marketing consent plus an active Meta configuration, the server also sets a
90-day `_fbc` fallback cookie. If consent has not been decided yet, only the
sanitized click identifier is held briefly in the necessary server session;
accepting consent later converts it to the cookie, while refusal clears it.
This covers a delayed or blocked browser Pixel without creating a marketing
cookie for an undecided or refusing visitor. Existing `_fbc` and `_fbp` values
always win; `_fbp` remains browser-owned and is not fabricated by this
application.

## Phone matching data

The checkout phone is normalized once before the Purchase event is queued.
An eight-digit Tunisian national number becomes `216` followed by those eight
digits; `+216` and `00216` formats normalize to the same value.  Only the
SHA-256 result is stored in the encrypted snapshot and serialized as
`user_data.ph`.  Invalid or missing values are omitted.  Raw phones and hashes
are excluded from application logs, diagnostics, audit payloads, and API
responses.

## Event coverage

| Event | Server event | `ph` | `fbc` / `fbp` | IP / user agent | Source URL |
|---|---:|---:|---:|---:|---:|
| PageView | Yes | No — checkout phone unavailable | When available | When available | Yes |
| ViewContent | Yes | No — checkout phone unavailable | When available | When available | Yes |
| Search | Yes | No — checkout phone unavailable | When available | When available | Yes |
| AddToCart | Yes | No — checkout phone unavailable | When available | When available | Yes |
| InitiateCheckout | Yes | No — form is not yet submitted | When available | When available | Yes |
| Purchase | Yes | Yes, when valid | When available | When available | Yes |

`InitiateCheckout` is emitted after the checkout page has obtained an
authoritative cart quote.  It is not an old-site-only event.

## Trusted proxies

For a direct Nginx-to-PHP-FPM VPS, preserve the real client address in the
FastCGI request and do not trust arbitrary client-supplied forwarded headers.
If a CDN or load balancer is introduced, configure Laravel to trust only that
provider's documented proxy IP ranges before relying on `X-Forwarded-For`.
Never configure `*` for an internet-facing deployment.  Verify a real request
reports a public visitor IP rather than `127.0.0.1`, a private VPS address, or
the proxy address before enabling Production mode.

## Meta Test Events verification

Use a clean browser profile, grant the configured consent, arrive once through
a real Meta-ad URL containing `fbclid`, navigate away from that URL, then view
a product, add it to the cart, open checkout, and place one synthetic test
order. In authorized diagnostics, verify the same `fbc` is present on the
Purchase snapshot and that browser/server event IDs match when the browser
Pixel is observed. In Meta Test Events, inspect the received server event
parameters without copying customer data into tickets. After launch, use
Events Manager coverage reports to assess actual `fbc`/`fbp` coverage; code
tests cannot prove Event Match Quality.
