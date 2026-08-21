# First Delivery integration

## Scope and security boundary

First Delivery is an additive delivery provider alongside Navex. It creates and tracks a provider shipment for a confirmed order; it never changes checkout totals, stock, Meta events, or the local order status workflow.

A Super Admin enters the token only in **Livraison → First Delivery**. Laravel encrypts the token at rest with the application key. API responses expose only `token_configured` and a fixed mask. The browser, URLs, audit payloads, application logs, shipment attempts, and encrypted request snapshots never receive the clear token.

Only the official HTTPS host `www.firstdeliverygroup.com` is accepted. Redirects are disabled and TLS verification remains enabled. Automated tests fake every outbound HTTP request.

## Provider endpoints

The fixed default base URL is `https://www.firstdeliverygroup.com/api/v2`.

| Operation | Method | Path | JSON body |
|---|---|---|---|
| Synchronize localities / connection test | GET | `/localities` | none |
| Create one order | POST | `/create` | `Client` and `Produit` objects |
| Read one status | POST | `/etat` | `{"barCode":"..."}` |
| Cancel pending orders | POST | `/cancel-orders` | `{"barCodes":["..."]}` |
| Create pickup manifest | POST | `/pickup` | `{"barCodes":["..."]}` |
| Refresh pickup receipt | POST | `/request-print/{pickupId}` | none |

Every request uses `Authorization: Bearer {token}`, `Accept: application/json`, and JSON encoding. The token is never placed in the path or query string.

The implementation follows the provider reference at <https://www.firstdeliverygroup.com/api/v2/documentation>. A First Delivery `locality_id` is mandatory; configuration testing downloads and upserts the provider catalogue before orders can be sent.

## Modes and provider selection

- `disabled`: no new First Delivery shipment can be queued.
- `manual`: an operator sends an eligible confirmed order from its detail page.
- `automatic`: changing an eligible order to `confirmee` creates the durable local shipment and dispatches the outbound job after the transaction commits.
- Navex and First Delivery are mutually exclusive for one order. An active shipment at one provider blocks the other provider.
- Existing Navex automatic behavior keeps priority when both providers are accidentally configured as automatic. First Delivery records no second shipment.

A unique constraint on `first_delivery_shipments.order_id`, a locked order row, and an integration cache lock provide layered duplicate protection. A connection failure during creation becomes `resultat_incertain`; it is not sent again automatically because the provider may already have created the order.

## Authoritative payload mapping

| First Delivery field | Local source |
|---|---|
| `Client.nom`, phone, city, governorate, address | committed order customer snapshot |
| `Client.locality_id` | selected synchronized locality on the order |
| `Produit.prix` | committed server-side `orders.total_millimes`, converted to DT |
| `Produit.designation` | canonical server-side order designation |
| `Produit.nombreArticle` | sum of committed item quantities |
| `Produit.article` | unique committed product names |
| `Produit.nombreEchange` | committed exchange count, or zero |
| fragile/open flags | fixed safe values `non` |

The complete creation payload is encrypted in `request_snapshot_encrypted` before the queue job runs. It is hidden from API serialization.

## Lifecycle and statuses

1. The order must be `confirmee`, have a synchronized locality, have a total between 0 and 999 DT, and have no active Navex shipment.
2. Queueing creates exactly one local shipment in `en_attente_envoi`.
3. `CreateFirstDeliveryShipmentJob` calls `POST /create`.
4. A successful response must include an exact 12-digit `barCode`. A print link is retained only when it is an HTTPS URL on the official provider host.
5. A successful creation dispatches an initial status synchronization. The scheduler later queues active shipments in small batches.
6. `POST /etat` updates only the provider shipment status. It never changes the local order status or stock.
7. Cancellation is available only when the latest provider state code is `0` (En attente). Local cancellation remains blocked until the provider cancellation succeeds.

Provider codes 0, 1, 2, 3, 5, 6, 7, 8, 11, 20, 30, 31, 100–104, and 201–204 map to explicit local delivery statuses. Unknown non-empty states are preserved as safe raw text without inventing a local order transition.

## Pickup manifests

An authenticated order operator can select between 1 and 100 First Delivery shipments whose verified provider state is `0` (`En attente`). Every selected shipment must have a valid 12-digit barcode, no current provider error, and no previous pickup manifest.

CleanCos first persists a local manifest and immutable barcode/order-reference item snapshots, then dispatches `CreateFirstDeliveryPickupJob` on the `integrations` queue. The job calls `POST /pickup`, stores the returned `pickupId` and retains the receipt link only when it is HTTPS on the official provider host. A timeout or ambiguous response becomes `uncertain_result` and is never retried automatically because First Delivery does not expose an idempotency key.

The manifest UI polls only the local asynchronous state. After confirmed creation, the existing rate-limited shipment synchronization jobs verify each selected barcode with `/etat` at two-second intervals. First Delivery exposes no public manifest-status, edit, cancellation, item-removal, or rollback endpoint; CleanCos therefore does not invent those operations.

The returned receipt can be opened directly. When it is absent or expired, **Préparer l’impression** queues `POST /request-print/{pickupId}` and stores a newly validated official link.

## Operations

Run the integrations worker continuously:

```bash
php artisan queue:work --queue=integrations,default --tries=3
```

Run Laravel's scheduler continuously in production, or locally while testing:

```bash
php artisan schedule:work
```

Manual synchronization and recovery:

```bash
php artisan first-delivery:synchronize --limit=20
php artisan first-delivery:synchronize --limit=20 --include-old
```

Relevant optional timing settings:

```dotenv
FIRST_DELIVERY_CONNECT_TIMEOUT_SECONDS=5
FIRST_DELIVERY_TIMEOUT_SECONDS=20
FIRST_DELIVERY_SYNC_INTERVAL_MINUTES=15
FIRST_DELIVERY_SYNC_BATCH_SIZE=20
```

The token is intentionally not an environment variable. It is managed through the Super Admin dashboard.

## Operator verification flow

1. Apply migrations and start the integrations queue worker plus scheduler.
2. Sign in as Super Admin and open **Livraison → First Delivery**.
3. Choose `Manuel`, paste the dashboard token, and save.
4. Click **Tester et synchroniser les localités**; verify a connected result and a non-zero locality count.
5. Open a confirmed order below 999 DT with no Navex shipment.
6. Choose and save a First Delivery locality under the delivery address.
7. Click **Envoyer à First Delivery** and let the integrations worker process the job.
8. Refresh the order and verify the 12-digit barcode plus **Imprimer le bordereau**.
9. Click **Synchroniser** and verify the provider state and timestamp.
10. For a provider state `En attente`, test cancellation and wait for provider confirmation.
11. Switch to `Automatique`, confirm a new eligible order, and verify exactly one shipment is queued.
12. Return to **Livraison → First Delivery**, select one or more verified `En attente` shipments, and click **Créer le manifeste**.
13. Wait for the worker to display **Manifeste créé**, verify the provider pickup number, and click **Imprimer**.
14. Verify that the selected shipments become pickup-related and continue to synchronize individually.

Troubleshooting starts with the safe `last_error`, shipment status history, and `first_delivery_shipment_attempts`. Never log or paste the token into a ticket, command, test fixture, or application log.
