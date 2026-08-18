# Navex delivery integration

## Scope and safety boundary

This integration sends a confirmed order to Navex and records the resulting delivery lifecycle. It does not replace checkout, order payment logic, inventory, or the existing order state machine.

Navex credentials are entered only by a Super Admin through **Livraison Navex**. Laravel encrypts them at rest. API responses, audit records, diagnostics, and the Vue application expose only whether each credential is configured.

No Navex request is made during automated tests. HTTP clients are faked.

## Official endpoint matrix

The configured base URL defaults to `https://app.navex.tn`. Only hosts in `NAVEX_ALLOWED_HOSTS` and HTTPS are accepted.

| Operation | Method and route | Credential used | Form fields |
|---|---|---|---|
| Create shipment | `POST /api/{creation credential}/v1/post.php` | creation | `prix`, `nom`, `gouvernerat`, `ville`, `adresse`, `tel`, `tel2`, `designation`, `nb_article`, `msg`, `echange`, `article`, `nb_echange`, `ouvrir`, `sender_name`, `sender_location`, `sender_gouvernorat` |
| Track one | `POST /api/{tracking credential}/v1/post.php` | tracking | `code`, `include_date=1`, `include_prix=1`, `include_echange=1` |
| Track batch | `POST /api/{tracking credential}/v1/post.php` | tracking | `codes` joined by `, ` |
| Pending reconciliation | `POST /api/{tracking credential}/v1/post.php` | tracking | `getattente=1` |
| Delete shipment | `POST /api/{deletion credential}/v1/post.php` | deletion | `delete_code` |

The official reference examples place the credential in the URL path. The integration deliberately does not invent an `Authorization` header or undocumented request fields.

## Data mapping

The checkout requires a Tunisian governorate. The order stores it as `orders.customer_governorate`; it is never inferred from the city.

| Navex field | Authoritative source |
|---|---|
| `prix` | committed `orders.total_millimes`, converted to TND with three decimals |
| `nom`, `tel`, `ville`, `adresse`, `gouvernerat` | committed order delivery snapshot; every Navex-bound free-text copy removes emoji, pictographs, variation selectors, and invisible formatting characters while preserving Arabic, French accents, numbers, punctuation, and ordinary address text. Local order/configuration data is never changed. |
| `designation` | the canonical order designation, formatted as `quantity "product name" ("variant")`, separated by ` // `; the variant part is omitted for simple products, and the encrypted request snapshot retains the exact payload sent to Navex |
| `nb_article` | committed total line quantity |
| sender fields and `ouvrir` | active Navex configuration |

The shipment request snapshot is encrypted after the order transaction commits. It is not exposed through the API.

## Lifecycle

1. A confirmed order can be queued manually when Navex mode is `manual`.
2. When the mode is `automatic`, every change to `confirmee` (individual or bulk action) uses the same durable local shipment creation path. Its outbound creation job is dispatched only after the order transaction commits; an existing shipment is always reused and never duplicated.
3. The `CreateNavexShipmentJob` performs the asynchronous Navex HTTP request on the `integrations` queue. After a successful creation with a tracking code, it immediately queues one tracking read on the same queue so the raw provider state is available without waiting for the scheduler.
4. A successful creation accepts a barcode supplied in `code`, `code_barre`, `tracking_code`, or the documented 12-digit `status_message` form. The latter is accepted only when it is exactly 12 digits, so prose such as `Product Added.` can never become a tracking code.
5. If Navex replies successfully without a safe tracking code, Laravel keeps the shipment accepted and reconciles against the documented pending endpoint; a timeout or uncertain result is never resent automatically.
6. Only one unambiguous pending parcel with the local immutable designation and exact committed amount can attach a tracking code. No matching parcel returns the same local shipment to a guarded retry path; multiple matches require a Super Admin’s manual review.
7. The scheduler batches non-terminal tracking-code shipments. It also recovers an older accepted creation acknowledgement when its recorded safe message is exactly a 12-digit barcode, then performs normal tracking. This never sends a second parcel.

Every Navex request is recorded in `navex_shipment_attempts`. The shipment-level
counter is a cumulative diagnostic counter, while `attempt_number` is sequenced
per operation (`creation`, `tracking`, `batch_tracking`, `reconciliation`, or
`deletion`). Queue retry limits use the current queue job's attempts, not the
shipment's lifetime history. This keeps long-lived tracking shipments retryable
and prevents an old shipment history from exhausting a new operation.

Navex delivery tracking is deliberately independent from the local contact workflow (`nouvelle`, `confirmee`, `tentative_1`, `tentative_2`, `tentative_3`, `annulee`). A provider status never changes local stock or a local contact status automatically. A local cancellation restores stock only after any Navex shipment has been cancelled first.

## State mapping

| Internal state | Meaning |
|---|---|
| `non_envoyee` | no shipment is recorded |
| `en_attente_envoi` / `envoi_en_cours` | safely queued or currently sending |
| `resultat_incertain` | no resend, reconciliation required |
| `acceptee_navex` | Navex accepted the creation request and returned a tracking code; the operator-facing label is `En attente chez Navex` because the parcel now exists and awaits its next courier step |
| `en_attente_navex` | Navex later reports that the existing parcel is waiting for its next operational step |
| `en_cours_livraison` | provider tracking status |
| `livree_payee` / `retournee` / `annulee_navex` | terminal provider status |
| `erreur_synchronisation` | safe retry path after temporary tracking failure |
| `action_manuelle_requise` | an ambiguous or non-retryable creation, reconciliation, or deletion result that genuinely needs operator intervention |

Unknown provider statuses are retained as raw safe text and do not force a local order transition. A successful tracking response with an unmapped `etat` keeps the last meaningful internal provider state (or falls back to `acceptee_navex` when no meaningful state exists) and the admin displays `Navex : {raw status}`. A temporary or uncertain tracking refresh records its diagnostics and retry timing without replacing the last known provider state. Manual-action status remains reserved for genuinely ambiguous or non-retryable creation, reconciliation, and deletion outcomes.

## Operational configuration

```dotenv
NAVEX_API_BASE_URL=https://app.navex.tn
NAVEX_ALLOWED_HOSTS=app.navex.tn
NAVEX_CONNECT_TIMEOUT_SECONDS=5
NAVEX_TIMEOUT_SECONDS=20
NAVEX_SYNC_INTERVAL_MINUTES=15
NAVEX_SYNC_BATCH_SIZE=50
```

Run workers with the integration queue:

```bash
php artisan queue:work redis --queue=critical,meta,integrations,default,media,exports --sleep=1 --tries=5 --timeout=120
```

The scheduler invokes `navex:synchronize` every `NAVEX_SYNC_INTERVAL_MINUTES` and prevents overlapping runs. An authorized operator may also queue a single shipment synchronization from the order detail screen.

## Local order editing after Navex sending

Orders remain editable locally regardless of Navex status. Saving a local article, COD amount, delivery, or exchange change does not recreate, update, or cancel an existing Navex shipment. The dashboard keeps the tracking code and provider state unchanged; any change that must also appear in the already-transmitted parcel still requires Navex support because the supplied API does not document a safe update endpoint.

## Rollout and rollback

1. Deploy code and run migrations.
2. Run workers that include `integrations`.
3. Set up Navex configuration in **Désactivé** mode. Save sender details and all three credentials.
4. Use **Tester Navex**. It calls the tracking endpoint using a deliberately nonexistent correlation code; it verifies connectivity but does not create a parcel.
5. Enable `manual` mode first and send one confirmed test order. Verify the tracking code and status in the Navex dashboard.
6. Enable `automatic` only after the manual flow is verified.

Rollback is operationally safe: set Navex mode to `Désactivé`. Existing shipments and order data remain intact; no checkout is blocked. Do not delete a shipment solely to retry a result marked `resultat_incertain`.

## Known API ambiguity

The supplied Navex creation material and observed accepted responses can return a 12-digit barcode in `status_message`. The client accepts that field only when it is exactly 12 digits. Other successful messages remain barcode-pending and are reconciled conservatively; an ambiguous pending-list match is never guessed.

## Not performed by this change

- No live Navex credential was used.
- No live shipment, cancellation, or tracking request was made.
- No delivery pricing, checkout total, or stock rule was changed.
- No undocumented Navex endpoint, header, or payload field was introduced.
