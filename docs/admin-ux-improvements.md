# Back-office UI/UX improvements

## Scope

The admin now uses a consistent operational interface across `/admin/products`, `/admin/categories`, `/admin/inventory`, and `/admin/orders`. Product create/edit uses semantic sections, a sticky save area, required-field markers, DT price inputs, publication badges, promotion and low-stock toggles, media states, and variant conversion confirmation.

Inventory provides **Inventaire** and **Historique des mouvements** views. Stock adjustments use an add/remove drawer with a positive quantity, projected result, mandatory reason, and variant selection where required. Orders and products use debounced search, compact automatic filters, table headers, status badges, loading and empty states.

## Affected routes and API

- Admin SPA: `/admin/products`, `/admin/categories`, `/admin/inventory`, `/admin/orders`.
- `GET /api/v1/admin/products` includes `active_variant_stock_quantity` for variant products.
- `GET /api/v1/admin/categories` includes `products_count` for protected deletion feedback.
- Variant conversion no longer requires a typed confirmation token. The transaction remains server-side.

No migration is required. Prices continue to be stored as integer millimes; the admin converts `49,000 DT` to `49000` before requests.

## Accessibility and responsive behavior

Controls retain labels, `required` attributes, focus rings, status announcements, and dialogs with semantic roles. Reduced-motion preferences disable nonessential transitions. Tables collapse to readable rows on narrow screens; the inventory adjustment drawer becomes full-width on mobile.

## Verification and acceptance

- New products default to **Brouillon** and edit state matches list state.
- Variant stock is the sum of active variants.
- Normal validation stays inline or in page alerts; destructive/conversion decisions use confirmation dialogs.
- Catalogue, order, inventory, and authorization regression coverage passes via `php artisan test`.
- Frontend passes `npm run typecheck`, `npm run lint`, and `npm run build`.
