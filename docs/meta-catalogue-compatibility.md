# Meta catalogue compatibility

## Invariant

`products.meta_catalog_id` is the external opaque Meta Content ID. It is the
single catalogue identity for the parent storefront product. Product variants
remain commerce-only records for options, SKU, price, stock, cart, order, and
inventory behavior; they do not have a Meta catalogue mapping.

When a selected variant is present, the resolver still resolves the parent
`Product` and returns only `products.meta_catalog_id`. The same value is used
for Pixel `content_ids[]`, CAPI `contents[].id`, and new
`order_items.meta_catalog_id_snapshot` values. Laravel primary keys, variant
IDs, public IDs, SKUs, slugs, and names are never substituted.

A missing parent mapping omits catalogue-specific fields while the normal
event, cart, checkout, order, inventory, and Purchase outbox flow continues.

Order lines store `meta_catalog_id_snapshot` at commit time so later catalogue
changes cannot rewrite historical Purchase payloads. Existing snapshots are
not rewritten by the variant-mapping removal migration.

The migration first reconciles legacy variant mappings. A parent with one
unambiguous variant mapping can inherit it when its parent mapping is empty;
matching parent/variant values are retained. Multiple variant values or a
parent/variant conflict abort with a report before any update or column drop.
After a successful reconciliation, the legacy variant column is removed.

There is no current `product_variants.meta_catalog_id` column, model field,
admin input, validation rule, import field, or variant diagnostic. Imports
assign catalogue IDs only to parent products. Variant context may still be
passed to the resolver by cart, checkout, and funnel code so commerce
selection remains unchanged; the resolver deliberately ignores it for Meta
identity.

## Administration and imports

Active Administrators and Super Admins can run the catalogue import. Existing
non-empty mappings require explicit confirmation before replacement and are
audited. The import entry point is visible from the back-office **Produits**
page and always starts with a dry-run. CSV/XLSX imports recognise `meta_catalog_id`, `name`, `price`,
`description`, and `category` (or `category_slug`). They are intentionally
partial-update imports: after a product is matched by catalogue ID or a unique
name, only non-empty supplied columns are changed. A file containing only a
name, description, and category is therefore valid for an existing product;
its price and catalogue mapping remain untouched. Creating a new product still
requires a name, price, and category, while a catalogue ID is optional. An
unknown category name or slug is created as an active category with a unique
slug during the reviewed commit. The
dry-run report detects duplicate IDs and conflicts before a commit; existing
numeric-looking values such as `100` remain exactly `100`.

## Diagnostics

Meta diagnostics expose only aggregate catalogue counts and mapping states;
they never expose full event payloads, tokens, or customer data.

## Inspected integration points

- Catalogue persistence and administration: `Product`, `ProductVariant`,
  `ProductController`, `CreateProductAction`, and
  `ReplaceProductVariantsAction`.
- Authoritative commerce values: `CartQuoteService`,
  `CreateGuestOrderAction`, and immutable `OrderItem` snapshots.
- Event creation and delivery: `MetaFunnelEventController`,
  `MetaEventFactory`, the Meta outbox job, `MetaConversionsClient`, and the
  storefront Pixel bridge in `resources/js/storefront/main.ts`.
- Operational visibility: `MetaDiagnosticsController` and the existing admin
  diagnostics view.

The catalogue import endpoints are `POST
/api/v1/admin/meta/catalogue/import/dry-run` (CSV/XLSX validation report) and
`POST /api/v1/admin/meta/catalogue/import/commit` (a previously reviewed
report). Both are available through the existing catalogue-management API
authorization and audit boundary.

## Not performed in this task

- No production Meta catalogue was modified.
- No production product dataset was modified.
- No test dataset was connected to the production catalogue.
- Converty was not modified.
- Active advertisements were not modified.
- Live Pixel or Conversions API testing was not performed.
