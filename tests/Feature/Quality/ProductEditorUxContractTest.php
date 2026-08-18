<?php

namespace Tests\Feature\Quality;

use Tests\TestCase;

class ProductEditorUxContractTest extends TestCase
{
    public function test_storefront_catalogue_appends_next_pages_without_admin_pagination_changes(): void
    {
        $script = file_get_contents(resource_path('js/storefront/main.ts'));

        self::assertStringContainsString('[data-catalogue-more]', $script);
        self::assertStringContainsString('catalogueMoreLoading', $script);
        self::assertStringContainsString('catalogueGrid.append(card)', $script);
        self::assertStringContainsString('catalogueMore.dataset.nextUrl', $script);
    }

    public function test_storefront_brand_mark_is_larger_on_desktop_without_changing_mobile_sizing(): void
    {
        $layout = file_get_contents(resource_path('views/components/layouts/storefront.blade.php'));

        self::assertStringContainsString("asset('logo1.webp')", $layout);
        self::assertStringContainsString('class="brand-lockup"', $layout);
        self::assertStringContainsString('.store-header .brand-lockup{width:4rem;height:4rem}', file_get_contents(resource_path('css/storefront.css')));
        self::assertStringContainsString('.store-header .brand-lockup{width:3.2rem;height:3.2rem}', file_get_contents(resource_path('css/storefront.css')));
        self::assertStringContainsString('@media(min-width:900px)', $layout);
    }

    public function test_storefront_catalogue_uses_the_approved_desktop_toolbar_and_grid_contract(): void
    {
        $catalogue = file_get_contents(resource_path('views/storefront/products/index.blade.php'));
        $styles = file_get_contents(resource_path('css/storefront.css'));

        self::assertStringContainsString('class="catalogue-toolbar"', $catalogue);
        self::assertStringContainsString('Voir plus de produits', $catalogue);
        self::assertStringContainsString('.catalogue-page .product-grid{grid-template-columns:repeat(4', $styles);
        self::assertStringContainsString('.catalogue-card-enter.is-visible', $styles);
    }

    public function test_product_editor_keeps_images_and_variants_in_a_draft_until_main_save(): void
    {
        $editor = file_get_contents(resource_path('js/admin/product-editor.ts'));

        self::assertStringContainsString('products/editor-save', $editor);
        self::assertStringNotContainsString('/variant-mode', $editor);
        self::assertStringNotContainsString('/images/${', $editor);
        self::assertStringContainsString('const destination = router.resolve(safeReturnTo.value)', $editor);
        self::assertStringContainsString('await router.push({', $editor);
        self::assertStringContainsString('safeReturnTo', $editor);
        self::assertStringContainsString('is_active: true', $editor);
        self::assertStringContainsString('gallery: galleryPayload()', $editor);
        self::assertStringContainsString('MAX_PRODUCT_IMAGE_BYTES = 2 * 1024 * 1024', $editor);
        self::assertStringContainsString('Le serveur bloque ce fichier avant son traitement.', $editor);
        self::assertStringContainsString('productMediaPreview', $editor);
        self::assertStringContainsString('productMediaPreview', file_get_contents(resource_path('js/admin/products.ts')));
        self::assertStringContainsString('Image enregistrée — traitement en cours.', $editor);
        self::assertStringContainsString('Traitement impossible. Retirez puis ajoutez de nouveau l’image.', $editor);
        self::assertStringContainsString("'gallery' => ['nullable', 'array', 'max:150']", file_get_contents(app_path('Http/Controllers/Api/Admin/ProductController.php')));
        self::assertStringContainsString('max_file_uploads=200', file_get_contents(base_path('docker/php/production.ini')));
        self::assertStringContainsString('client_max_body_size 256m', file_get_contents(base_path('docker/nginx/default.conf')));
        self::assertStringContainsString('errorFromResponse', $editor);
        self::assertStringContainsString("'max:250'", file_get_contents(app_path('Http/Controllers/Api/Admin/ProductController.php')));
    }

    public function test_admin_shell_does_not_embed_a_style_tag_in_its_vue_template(): void
    {
        $shell = file_get_contents(resource_path('js/admin/main.ts'));
        $styles = file_get_contents(resource_path('css/app.css'));

        self::assertStringNotContainsString('template: `<style>.admin-brand', $shell);
        self::assertStringContainsString('.admin-brand-logo', $styles);
    }

    public function test_product_lists_use_bounded_pagination_instead_of_a_hidden_hundred_row_limit(): void
    {
        $products = file_get_contents(resource_path('js/admin/products.ts'));
        $categories = file_get_contents(resource_path('js/admin/categories.ts'));

        self::assertStringContainsString("per_page: '25'", $products);
        self::assertStringContainsString('page.last_page > 1', $products);
        self::assertStringContainsString("per_page: '25'", $categories);
        self::assertStringContainsString('category-hierarchy', $categories);
    }

    public function test_operational_lists_share_the_clear_row_and_summary_pattern(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        foreach (['inventory.ts', 'complaints.ts', 'checkout-fields.ts', 'static-pages.ts'] as $file) {
            $view = file_get_contents(resource_path('js/admin/'.$file));
            self::assertStringContainsString('admin-list-page', $view);
            self::assertStringContainsString('list-summary-strip', $view);
            self::assertStringContainsString('admin-entity-list', $view);
        }

        $categories = file_get_contents(resource_path('js/admin/categories.ts'));
        self::assertStringContainsString('category-hierarchy', $categories);
        self::assertStringContainsString('list-summary-strip', $categories);

        $products = file_get_contents(resource_path('js/admin/products.ts'));
        self::assertStringContainsString('list-summary-strip', $products);
        self::assertStringContainsString('admin-entity-list', $products);
        self::assertStringContainsString('.admin-icon-action', $styles);
        self::assertStringContainsString('.list-instruction', $styles);
    }

    public function test_product_list_uses_direct_catalog_removal_and_product_order_shortcut(): void
    {
        $products = file_get_contents(resource_path('js/admin/products.ts'));
        $orders = file_get_contents(resource_path('js/admin/orders.ts'));

        self::assertStringContainsString('Supprimer ce produit du catalogue', $products);
        self::assertStringContainsString("'DELETE'", $products);
        self::assertStringContainsString('adminApi<T>(path, method, body)', $products);
        self::assertStringContainsString('product_public_id: product.public_id', $products);
        self::assertStringContainsString('product_public_id', $orders);
        self::assertStringContainsString('Commandes contenant le produit', $orders);
    }

    public function test_orders_offer_direct_view_and_delete_actions_and_delivery_has_one_sidebar_entry(): void
    {
        $orders = file_get_contents(resource_path('js/admin/orders.ts'));
        $orderController = file_get_contents(app_path('Http/Controllers/Api/Admin/OrderController.php'));
        $shell = file_get_contents(resource_path('js/admin/main.ts'));
        $shipping = file_get_contents(resource_path('js/admin/shipping-settings.ts'));
        $navex = file_get_contents(resource_path('js/admin/navex.ts'));

        self::assertStringContainsString('order-row-actions', $orders);
        self::assertStringContainsString('orderItemSummary', $orders);
        self::assertStringContainsString('order-purchased-items', $orders);
        self::assertStringContainsString("setAttribute('product_names'", $orderController);
        self::assertStringContainsString('aria-label="Voir la commande"', $orders);
        self::assertStringContainsString('archiveOrder(order)', $orders);
        self::assertStringContainsString('destroyOrder(order)', $orders);
        self::assertStringContainsString("filters.archived === \\'1\\'", $orders);
        self::assertSame(0, substr_count($shell, "to: '/navex'"));
        self::assertStringContainsString("to: '/shipping', label: 'Livraison'", $shell);
        self::assertStringContainsString('delivery-section-tabs', $shipping);
        self::assertStringContainsString('delivery-section-tabs', $navex);
    }

    public function test_order_detail_explains_status_progression_and_navex_cancellation_before_deletion(): void
    {
        $detail = file_get_contents(resource_path('js/admin/order-detail.ts'));
        $navex = file_get_contents(resource_path('js/admin/navex.ts'));

        self::assertStringContainsString('Parcours habituel', $detail);
        self::assertStringNotContainsString('Correction manuelle', $detail);
        self::assertStringContainsString('Mettre à jour le statut', $detail);
        self::assertStringNotContainsString('manual_override', $detail);
        self::assertStringContainsString('bulkManualStatus', file_get_contents(resource_path('js/admin/orders.ts')));
        self::assertStringContainsString('orderLink(order)', file_get_contents(resource_path('js/admin/orders.ts')));
        self::assertStringContainsString('backToOrders', $detail);
        self::assertStringContainsString('Nouvelle → Tentative 1 → Tentative 2 → Tentative 3 → Confirmée', $detail);
        self::assertStringContainsString('Vous pouvez choisir directement une autre étape.', $detail);
        self::assertStringContainsString('Annuler le colis Navex', $detail);
        self::assertStringContainsString('Pourquoi synchroniser ?', $detail);
        self::assertStringContainsString('ne crée pas et ne renvoie pas de second colis', $detail);
        self::assertStringContainsString('class="navex-status"', $detail);
        self::assertStringContainsString('Désignation de la commande', $detail);
        self::assertStringContainsString("en_attente_navex: 'En attente chez Navex'", $navex);
        self::assertStringContainsString('admin-combobox', $detail);
        self::assertStringContainsString('governorateQuery', $detail);
        self::assertStringContainsString('<main class="order-detail-main">', $detail);
        $styles = file_get_contents(resource_path('css/app.css'));
        self::assertStringContainsString('.order-detail-page .order-detail-main{grid-column:1;width:auto!important;max-width:none!important;margin:0!important', $styles);
        self::assertStringContainsString('.order-product-search-options', $styles);
        self::assertStringContainsString('.order-inline-replacement', $styles);
        self::assertStringContainsString('.order-detail-page .navex-status', $styles);
        self::assertStringContainsString('role="combobox"', $detail);
        self::assertStringContainsString('order-product-search-options', $detail);
        self::assertStringContainsString('replacementSearch', $detail);
        self::assertStringContainsString('closeReplacementSearch', $detail);
        self::assertStringContainsString('Remplacer ce produit', $detail);
        self::assertStringContainsString('Ajouter un produit', $detail);
        self::assertStringContainsString('Choisir une variante', $detail);
        self::assertStringContainsString('chooseAddProductVariant', $detail);
        self::assertStringContainsString('order-add-variant-picker', $styles);
        self::assertStringNotContainsString('v-model="productToAdd"', $detail);
    }

    public function test_products_view_closes_its_root_template_section(): void
    {
        $products = file_get_contents(resource_path('js/admin/products.ts'));

        self::assertStringContainsString('</template></section>`,', $products);
    }

    public function test_catalogue_lists_render_available_product_and_category_images_with_a_compact_fallback(): void
    {
        $products = file_get_contents(resource_path('js/admin/products.ts'));
        $categories = file_get_contents(resource_path('js/admin/categories.ts'));
        $styles = file_get_contents(resource_path('css/app.css'));

        self::assertStringContainsString('product.images?.[0]?.public_url', $products);
        self::assertStringContainsString('admin-product-thumb', $products);
        self::assertStringContainsString('image_url?: string | null', $categories);
        self::assertStringContainsString('admin-category-identity>span:not(:has(.admin-category-thumb))::before', $styles);
    }

    public function test_categories_offer_direct_catalog_removal_with_a_product_history_warning(): void
    {
        $categories = file_get_contents(resource_path('js/admin/categories.ts'));

        self::assertStringContainsString('seront retirés du catalogue', $categories);
        self::assertStringContainsString('Les commandes passées resteront intactes.', $categories);
        self::assertStringContainsString('@click="remove(subcategory)"', $categories);
    }

    public function test_storefront_explains_out_of_stock_and_uses_only_local_footer_icons(): void
    {
        $product = file_get_contents(resource_path('views/storefront/products/show.blade.php'));
        $layout = file_get_contents(resource_path('views/components/layouts/storefront.blade.php'));
        $script = file_get_contents(resource_path('js/storefront/main.ts'));

        self::assertStringContainsString('data-stock-status', $product);
        self::assertStringContainsString('button-stock-unavailable', $product);
        self::assertStringContainsString('Ce produit sera de nouveau disponible prochainement.', $product);
        self::assertStringContainsString('reconcileStoredCart', $script);
        self::assertStringContainsString("'instagram' => 'instagram.png'", $layout);
        self::assertStringContainsString('images/phone-call.png', $layout);
    }

    public function test_checkout_governorate_is_a_required_searchable_choice(): void
    {
        $script = file_get_contents(resource_path('js/storefront/main.ts'));
        $styles = file_get_contents(resource_path('css/storefront.css'));

        self::assertStringContainsString('data-governorate-combobox', $script);
        self::assertStringContainsString('role="combobox"', $script);
        self::assertStringContainsString('Veuillez sélectionner un gouvernorat dans la liste.', $script);
        self::assertStringContainsString('mountGovernorateCombobox', $script);
        self::assertStringContainsString('.checkout-field-hint', $styles);
        self::assertStringContainsString('data-governorate-value', $script);
    }

    public function test_product_editor_selects_use_one_scoped_chevron(): void
    {
        $styles = file_get_contents(resource_path('css/admin-product-editor.css'));

        self::assertStringContainsString('.product-editor .product-form select', $styles);
        self::assertStringContainsString('-webkit-appearance:none', $styles);
        self::assertStringContainsString('.product-editor .product-form select::-ms-expand', $styles);
        self::assertStringContainsString('background-repeat:no-repeat', $styles);
    }

    public function test_product_page_has_direct_checkout_without_cart_mutation_and_drawer_actions(): void
    {
        $script = file_get_contents(resource_path('js/storefront/main.ts'));
        $product = file_get_contents(resource_path('views/storefront/products/show.blade.php'));

        self::assertStringContainsString('data-buy-now', $product);
        self::assertStringContainsString('data-express-checkout', $product);
        self::assertStringContainsString("source: 'buy_now'", $script);
        self::assertStringContainsString('preserveCart: true', $script);
        self::assertStringContainsString("trackMetaEvent('InitiateCheckout'", $script);
        self::assertStringContainsString('data-cart-drawer-continue', $script);
    }

    public function test_storefront_keeps_active_out_of_stock_variants_navigable_and_places_express_checkout_after_product_layout(): void
    {
        $script = file_get_contents(resource_path('js/storefront/main.ts'));
        $product = file_get_contents(resource_path('views/storefront/products/show.blade.php'));
        $styles = file_get_contents(resource_path('css/storefront.css'));

        self::assertStringContainsString('const activeVariants', $script);
        self::assertStringContainsString('Rupture de stock', $script);
        self::assertStringContainsString("button.classList.toggle('is-out-of-stock'", $script);
        self::assertStringContainsString('data-product-detail', $product);
        self::assertStringContainsString('class="product-layout"', $product);
        self::assertStringContainsString('class="express-checkout-panel"', $product);
        self::assertStringContainsString('.product-page + .related-products', $styles);
        self::assertStringContainsString('.product-action-buttons{grid-template-columns:repeat(2', $styles);
    }

    public function test_content_management_supports_curated_sections_deletion_and_editorial_product_filters(): void
    {
        $content = file_get_contents(resource_path('js/admin/content.ts'));

        self::assertStringContainsString('deleteSection(section)', $content);
        self::assertStringContainsString("'DELETE',", $content);
        self::assertStringContainsString('editorialProductSearch', $content);
        self::assertStringContainsString('editorialProductCategory', $content);
        self::assertStringContainsString('visibleEditorialProducts', $content);
        self::assertStringContainsString('toggleEditorialProduct', $content);
        self::assertStringContainsString('clearEditorialFilters', $content);
        self::assertStringContainsString('loadSelectableProducts', $content);
        self::assertStringContainsString('firstPage.data.last_page', $content);
        self::assertStringContainsString('sort=name&page=${page}', $content);
        self::assertStringContainsString('deleteHero(hero)', $content);
    }

    public function test_checkout_error_navigation_and_phone_contract_are_shared(): void
    {
        $script = file_get_contents(resource_path('js/storefront/main.ts'));
        $request = file_get_contents(app_path('Http/Requests/Api/CreateGuestOrderRequest.php'));
        $phoneRule = file_get_contents(app_path('Domain/Checkout/Support/PhoneNumberRule.php'));
        self::assertStringContainsString('focusCheckoutError', $script);
        self::assertStringContainsString('aria-describedby', $script);
        self::assertStringContainsString('validatePhone', $script);
        self::assertStringContainsString('new PhoneNumberRule', $request);
        self::assertStringContainsString('strlen($digits) >= 8', $phoneRule);
    }

    public function test_complaints_and_inventory_have_safe_admin_actions_and_media_rows(): void
    {
        $complaints = file_get_contents(resource_path('js/admin/complaints.ts'));
        $inventory = file_get_contents(resource_path('js/admin/inventory.ts'));
        $routes = file_get_contents(base_path('routes/api.php'));
        self::assertStringContainsString("'DELETE'", $complaints);
        self::assertStringContainsString('confirmAction', $complaints);
        self::assertStringContainsString('inventory/bulk-adjustments', $inventory);
        self::assertStringContainsString('selectAllRef', $inventory);
        self::assertStringContainsString('product.images?.[0]?.public_url', $inventory);
        self::assertStringContainsString("Route::delete('complaints/{complaint}'", $routes);
        self::assertStringContainsString("Route::post('inventory/bulk-adjustments'", $routes);
    }

    public function test_product_list_state_is_carried_through_editor_navigation(): void
    {
        $products = file_get_contents(resource_path('js/admin/products.ts'));
        $editor = file_get_contents(resource_path('js/admin/product-editor.ts'));

        self::assertStringContainsString('useRouter', $products);
        self::assertStringContainsString('defaultProductFilters', $products);
        self::assertStringContainsString('applyFiltersFromRoute', $products);
        self::assertStringContainsString('routePage', $products);
        self::assertStringContainsString('listRouteQuery', $products);
        self::assertStringContainsString('syncListRoute', $products);
        self::assertStringContainsString('await router.replace(target)', $products);
        self::assertStringContainsString('watch(() => route.fullPath', $products);
        foreach (['search', 'category_id', 'is_active', 'has_variants', 'stock_state', 'is_promotional', 'sort', 'archived'] as $filter) {
            self::assertStringContainsString($filter, $products);
        }
        self::assertStringContainsString('listReturnTo', $products);
        self::assertStringContainsString('returnTo: listReturnTo', $products);
        self::assertStringContainsString('candidate === \'/products\' || candidate.startsWith(\'/products?\')', $editor);
        self::assertStringContainsString("!candidate.startsWith('//')", $editor);
        self::assertStringContainsString('safeReturnTo', $editor);
        self::assertStringContainsString(':to="safeReturnTo"', $editor);
    }

    public function test_product_and_order_mutations_refresh_api_state_without_document_reload(): void
    {
        $products = file_get_contents(resource_path('js/admin/products.ts'));
        $orders = file_get_contents(resource_path('js/admin/orders.ts'));

        self::assertStringNotContainsString('window.location.reload', $products);
        self::assertStringNotContainsString('window.location.reload', $orders);
        self::assertStringContainsString('await load()', $products);
        self::assertStringContainsString('await load()', $orders);
        self::assertStringContainsString('selected.value = []', $products);
        self::assertStringContainsString('selected.value = []', $orders);
    }

    public function test_checkout_field_editor_has_no_customer_preview_section(): void
    {
        $editor = file_get_contents(resource_path('js/admin/checkout-fields.ts'));
        $styles = file_get_contents(resource_path('css/admin-list-pages.css'));

        self::assertStringNotContainsString('previewFields', $editor);
        self::assertStringNotContainsString('settings-preview', $editor);
        self::assertStringNotContainsString('settings-preview', $styles);
        self::assertStringContainsString('checkout-field-editor', $editor);
        self::assertStringContainsString('sticky-save-bar', $editor);

    }
}
