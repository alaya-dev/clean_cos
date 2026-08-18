# Meta reliability verification

## What this integration guarantees

This application is designed to make Meta conversion delivery **more durable and
auditable** than a browser-only setup. It cannot promise that Meta will count
every visitor: consent rules, ad blockers, browser privacy controls, network
failure and Meta's own processing can affect what Meta ultimately reports.

The application never bypasses Brave Shields, ad blockers or a visitor's
privacy choices. When browser Pixel JavaScript is blocked, a consented,
server-known event can still be delivered through CAPI; it is recorded as a
server-only delivery, not as a successful browser Pixel delivery.

| Claim | Implementation evidence | Measurable automated test |
|---|---|---|
| Purchase exists only after a committed order transaction | `CreateGuestOrderAction` writes the order, items, stock movements and Purchase outbox row in one transaction; dispatch is registered with `DB::afterCommit`. | `MetaPurchaseTest::test_a_consented_committed_checkout_creates_one_purchase_with_safe_authoritative_values` |
| Durable MySQL outbox before delivery | `meta_events` is a MySQL model; queue jobs contain only its public ID. | `MetaPurchaseTest` and `MetaReliabilityContractTest::test_two_independent_consented_visitors_create_separate_durable_outbox_events` |
| Stable event IDs across retries | `meta_events.event_id` is generated once and `SendMetaEventJob` reuses that row. | `MetaReliabilityContractTest::test_repeated_delivery_attempts_keep_the_persisted_event_identifier` |
| Pixel/CAPI use matching name and ID | The browser receives the stored event from `MetaFunnelEventController`; it sends `fbq(..., { eventID })`. CAPI serializes the same stored row. | `MetaFunnelEventTest::test_consented_add_to_cart_uses_server_authoritative_product_value_and_queues_delivery`, `MetaConversionsClientTest::test_it_sends_only_to_the_fixed_meta_host_with_the_persisted_event_identity` |
| Parent catalogue Content ID only | `MetaCatalogIdentifierResolver` always returns `products.meta_catalog_id`, even for a selected variant. | `MetaCatalogCompatibilityTest::test_resolver_always_uses_parent_product_mapping_for_selected_variants` |
| Temporary failure retry / Retry-After | `MetaConversionsClient` classifies 429 and 5xx as temporary; `SendMetaEventJob` honors bounded Retry-After plus jitter. | `MetaConversionsClientTest::test_rate_limits_are_temporary_and_expose_only_safe_retry_metadata` |
| Worker restart does not lose eligible events | Persistent pending events are reconciled by the scheduled `meta:requeue-pending` command; atomic claiming avoids duplicate requeue. | `RequeuePendingMetaEventsTest::test_it_requeues_a_stranded_pending_outbox_event_once` |
| Checkout remains independent from Meta | Meta is queued after commit; no remote Meta call happens in checkout. | `MetaPurchaseTest::test_a_consented_committed_checkout_creates_one_purchase_with_safe_authoritative_values` |
| Safe Super Admin diagnostics | Diagnostics are paginated and expose only safe attempt fields; the token and encrypted user data are hidden. | `MetaConfigurationTest::test_admin_is_denied_and_token_is_never_returned_or_audited`, `OperationalVisibilityTest` |
| Browser and server failures are separate | Browser state and CAPI state are independent persisted fields. | `MetaConversionsClientTest::test_browser_non_observation_does_not_stop_server_delivery` |
| Graph endpoint is configurable | The endpoint reads `META_GRAPH_API_VERSION` through `config/meta.php`; default is `v25.0`. | `MetaConversionsClientTest::test_it_sends_only_to_the_fixed_meta_host_with_the_persisted_event_identity` |
| Disabled, Test and Production modes | Versioned configuration activates Test after a successful test; Production requires a recent successful test; disabled sends nothing. | `MetaConfigurationTest::test_disabled_mode_activates_without_password_or_network_request`, `MetaConfigurationTest::test_successful_test_uses_proposed_configuration_and_activates_test_mode` |
| CAPI token encrypted at rest | Only `capi_access_token_encrypted` is persisted and it is hidden from models and API output. | `MetaConfigurationTest::test_admin_is_denied_and_token_is_never_returned_or_audited` |
| Attribution snapshot is queue-safe | `_fbc`, `_fbp`, IP, user agent and the Purchase phone hash are encrypted with the outbox event before dispatch; retries reuse that immutable snapshot. | `MetaAttributionContextTest` |
| Retention and health monitoring | Retention pruning protects unresolved/retrying events; operational health observes scheduler, queue, disk and pruning status. | `PruneMetaTrackingDataTest`, `OperationalVisibilityTest` |

## Multiple visitor test

`MetaReliabilityContractTest::test_two_independent_consented_visitors_create_separate_durable_outbox_events`
simulates two distinct consenting visitors. It verifies two separate MySQL
outbox records, two separate event IDs, two queued dispatch jobs and distinct
encrypted server-side user-agent values. It proves the application does not
collapse independent visitors or crash while handling them.

It is not a claim that an external Meta dataset was tested with two real people.
That real-world verification must be performed in Meta Events Manager with the
test Pixel, two normal browser profiles and a functioning queue worker.

## Run the verification

```sh
php artisan test tests/Feature/MetaTracking/MetaReliabilityContractTest.php \
  tests/Feature/MetaTracking/MetaPurchaseTest.php \
  tests/Feature/MetaTracking/MetaCatalogCompatibilityTest.php \
  tests/Feature/MetaTracking/MetaConversionsClientTest.php \
  tests/Feature/MetaTracking/MetaAttributionContextTest.php \
  tests/Feature/MetaTracking/MetaConfigurationTest.php \
  tests/Feature/MetaTracking/OperationalVisibilityTest.php \
  tests/Feature/MetaTracking/RequeuePendingMetaEventsTest.php \
  tests/Feature/MetaTracking/PruneMetaTrackingDataTest.php
```

For a real non-production Meta test, use a test configuration, keep the Meta
worker running, open two clean browser profiles, grant consent, perform a
product view, AddToCart and checkout, then compare event IDs in the authorized
diagnostics with Meta Test Events. Do not use production credentials or an
active campaign for this verification.
