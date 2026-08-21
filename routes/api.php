<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\CategoryImageController;
use App\Http\Controllers\Api\Admin\CheckoutDraftController as AdminCheckoutDraftController;
use App\Http\Controllers\Api\Admin\CheckoutFieldController as AdminCheckoutFieldController;
use App\Http\Controllers\Api\Admin\ComplaintController;
use App\Http\Controllers\Api\Admin\CurrentUserController;
use App\Http\Controllers\Api\Admin\CustomerController;
use App\Http\Controllers\Api\Admin\FirstDeliveryConfigurationController;
use App\Http\Controllers\Api\Admin\FirstDeliveryDeliveryController;
use App\Http\Controllers\Api\Admin\FirstDeliveryLocalityController;
use App\Http\Controllers\Api\Admin\FirstDeliveryPickupController;
use App\Http\Controllers\Api\Admin\FirstDeliveryShipmentController;
use App\Http\Controllers\Api\Admin\HeroSlideController;
use App\Http\Controllers\Api\Admin\HomepageItemController;
use App\Http\Controllers\Api\Admin\HomepageSectionController;
use App\Http\Controllers\Api\Admin\InventoryController;
use App\Http\Controllers\Api\Admin\MetaCatalogImportController;
use App\Http\Controllers\Api\Admin\MetaConfigurationController;
use App\Http\Controllers\Api\Admin\MetaDiagnosticsController;
use App\Http\Controllers\Api\Admin\NavexConfigurationController;
use App\Http\Controllers\Api\Admin\NavexDeliveryController;
use App\Http\Controllers\Api\Admin\NavexShipmentController;
use App\Http\Controllers\Api\Admin\OperationalDashboardController;
use App\Http\Controllers\Api\Admin\OperationalHealthController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\PasswordController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ProductImageController;
use App\Http\Controllers\Api\Admin\PromoCodeController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\StaticPageController as AdminStaticPageController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\CartQuoteController;
use App\Http\Controllers\Api\CheckoutDraftController;
use App\Http\Controllers\Api\CheckoutFieldsController;
use App\Http\Controllers\Api\GuestOrderController;
use App\Http\Controllers\Api\MarketingConsentController;
use App\Http\Controllers\Api\MetaFunnelEventController;
use App\Http\Controllers\Api\MetaPixelConfigurationController;
use App\Http\Controllers\Api\PublicComplaintController;
use App\Http\Controllers\Api\PublicSearchController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\PersistMetaAttributionCookie;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live'])->middleware('throttle:30,1');
Route::get('/health/ready', [HealthController::class, 'ready'])->middleware('throttle:30,1');

Route::prefix('v1/public')->middleware('web')->group(function (): void {
    Route::get('/marketing-consent', [MarketingConsentController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/marketing-consent', [MarketingConsentController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/meta/pixel', MetaPixelConfigurationController::class)->middleware('throttle:60,1');
    Route::post('/meta/events', MetaFunnelEventController::class);
    Route::post('/meta/events/{event}/browser-attempt', [MetaFunnelEventController::class, 'browserAttempt']);
});

Route::prefix('v1/public')->group(function (): void {
    Route::get('/search/suggestions', PublicSearchController::class)->middleware('throttle:60,1');
    Route::post('/cart/quote', CartQuoteController::class)->middleware('throttle:60,1');
    Route::get('/checkout-fields', CheckoutFieldsController::class)->middleware('throttle:30,1');
    // The checkout is stateless, but Purchase eligibility must read the signed
    // marketing-consent receipt. Decrypt only cookies here; do not add the
    // whole web group (and its session/CSRF behaviour) to this public API.
    Route::post('/orders', GuestOrderController::class)->middleware([EncryptCookies::class, PersistMetaAttributionCookie::class, 'throttle:checkout-orders']);
    Route::post('/checkout-drafts', [CheckoutDraftController::class, 'store'])->middleware([EncryptCookies::class, PersistMetaAttributionCookie::class, 'throttle:checkout-drafts']);
    Route::patch('/checkout-drafts/{token}', [CheckoutDraftController::class, 'update'])->middleware([EncryptCookies::class, PersistMetaAttributionCookie::class, 'throttle:checkout-drafts']);
    Route::post('/complaints', PublicComplaintController::class)->middleware('throttle:complaints');
});

Route::prefix('v1/admin')->middleware(['web', 'auth', 'can:catalog.manage'])->group(function (): void {
    Route::get('dashboard', OperationalDashboardController::class);
    Route::get('operational-health', OperationalHealthController::class);
    Route::post('categories/reorder', [CategoryController::class, 'reorder']);
    Route::post('categories/bulk-status', [CategoryController::class, 'bulkStatus']);
    Route::get('categories/{category}/product-order', [CategoryController::class, 'productOrder']);
    Route::put('categories/{category}/product-order', [CategoryController::class, 'updateProductOrder']);
    Route::apiResource('categories', CategoryController::class);
    Route::post('categories/{category}/image', [CategoryImageController::class, 'store'])->middleware('throttle:media-upload');
    Route::delete('categories/{category}/image', [CategoryImageController::class, 'destroy']);
    Route::post('products/editor-save', [ProductController::class, 'saveEditor'])->middleware('throttle:media-upload');
    Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::get('products/{product}/media-status', [ProductController::class, 'mediaStatus']);
    Route::post('products/{product}/status', [ProductController::class, 'status']);
    Route::post('products/bulk-status', [ProductController::class, 'bulkStatus']);
    Route::post('products/bulk-set-stock', [ProductController::class, 'bulkSetStock']);
    Route::post('products/bulk-set-promotion', [ProductController::class, 'bulkSetPromotion']);
    Route::post('products/bulk-archive', [ProductController::class, 'bulkArchive']);
    Route::post('products/bulk-restore', [ProductController::class, 'bulkRestore']);
    Route::post('products/bulk-force-delete', [ProductController::class, 'bulkForceDelete']);
    Route::post('products/{product}/variant-mode', [ProductController::class, 'variantMode']);
    Route::put('products/{product}/variants', [ProductController::class, 'replaceVariants']);
    Route::post('products/{product}/images', [ProductImageController::class, 'store'])->middleware('throttle:media-upload');
    Route::post('meta/catalogue/import/dry-run', [MetaCatalogImportController::class, 'dryRun']);
    Route::post('meta/catalogue/import/commit', [MetaCatalogImportController::class, 'commit']);
    Route::get('inventory/movements', [InventoryController::class, 'index']);
    Route::post('products/{product}/inventory-adjustments', [InventoryController::class, 'adjust']);
    Route::post('inventory/bulk-adjustments', [InventoryController::class, 'bulkAdjust']);
    Route::post('products/{product}/images/reorder', [ProductImageController::class, 'reorder']);
    Route::patch('products/{product}/images/{image:public_id}', [ProductImageController::class, 'update']);
    Route::delete('products/{product}/images/{image:public_id}', [ProductImageController::class, 'destroy']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/export', [OrderController::class, 'export']);
    Route::get('orders/changes', [OrderController::class, 'changes'])->middleware('throttle:admin-order-changes');
    Route::get('orders/available-products', [OrderController::class, 'availableProducts']);
    Route::get('orders/{order}/customer-history', [OrderController::class, 'customerHistory'])->middleware('throttle:60,1');
    Route::get('orders/{order}/available-products', [OrderController::class, 'availableProducts']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::patch('orders/{order}', [OrderController::class, 'update']);
    Route::put('orders/{order}/items', [OrderController::class, 'updateItems']);
    Route::patch('orders/{order}/total', [OrderController::class, 'updateTotal']);
    Route::post('orders/{order}/transitions', [OrderController::class, 'transition']);
    Route::post('orders/{order}/notes', [OrderController::class, 'storeNote']);
    Route::post('orders/{order}/navex/send', [NavexShipmentController::class, 'send'])->middleware('throttle:10,1');
    Route::post('orders/{order}/navex/synchronize', [NavexShipmentController::class, 'synchronize'])->middleware('throttle:10,1');
    Route::post('orders/{order}/navex/reconcile', [NavexShipmentController::class, 'reconcile'])->middleware('throttle:5,1');
    Route::post('orders/{order}/navex/retry', [NavexShipmentController::class, 'retry'])->middleware('throttle:5,1');
    Route::post('orders/{order}/navex/cancel', [NavexShipmentController::class, 'cancel'])->middleware('throttle:5,1');
    Route::post('orders/{order}/first-delivery/send', [FirstDeliveryShipmentController::class, 'send'])->middleware('throttle:10,1');
    Route::post('orders/{order}/first-delivery/synchronize', [FirstDeliveryShipmentController::class, 'synchronize'])->middleware('throttle:10,1');
    Route::post('orders/{order}/first-delivery/retry', [FirstDeliveryShipmentController::class, 'retry'])->middleware('throttle:5,1');
    Route::post('orders/{order}/first-delivery/cancel', [FirstDeliveryShipmentController::class, 'cancel'])->middleware('throttle:5,1');
    Route::get('first-delivery/deliveries', [FirstDeliveryDeliveryController::class, 'index']);
    Route::get('first-delivery/localities', FirstDeliveryLocalityController::class)->middleware('throttle:60,1');
    Route::get('first-delivery/pickups', [FirstDeliveryPickupController::class, 'index']);
    Route::post('first-delivery/pickups', [FirstDeliveryPickupController::class, 'store'])->middleware('throttle:10,1');
    Route::post('first-delivery/pickups/{pickup}/retry', [FirstDeliveryPickupController::class, 'retry'])->middleware('throttle:5,1');
    Route::post('first-delivery/pickups/{pickup}/refresh-print', [FirstDeliveryPickupController::class, 'refreshPrint'])->middleware('throttle:10,1');
    Route::get('navex/deliveries', [NavexDeliveryController::class, 'index']);
    Route::post('orders/bulk-archive', [OrderController::class, 'bulkArchive']);
    Route::post('orders/bulk-restore', [OrderController::class, 'bulkRestore']);
    Route::delete('orders/bulk', [OrderController::class, 'bulkDestroy']);
    Route::post('orders/bulk-transition', [OrderController::class, 'bulkTransition']);
    Route::get('customers/lookup', [CustomerController::class, 'lookup'])->middleware('throttle:60,1');
    Route::get('checkout-drafts', [AdminCheckoutDraftController::class, 'index']);
    Route::get('checkout-drafts/{token}', [AdminCheckoutDraftController::class, 'show']);
    Route::delete('checkout-drafts/{token}', [AdminCheckoutDraftController::class, 'destroy']);
    Route::post('checkout-drafts/{token}/convert', [AdminCheckoutDraftController::class, 'convert']);
});

Route::prefix('v1/admin')->middleware(['web', 'auth', 'can:complaints.manage'])->group(function (): void {
    Route::get('complaints', [ComplaintController::class, 'index']);
    Route::get('complaints/{complaint}', [ComplaintController::class, 'show']);
    Route::post('complaints/{complaint}/transitions', [ComplaintController::class, 'transition']);
    Route::post('complaints/{complaint}/notes', [ComplaintController::class, 'note']);
    Route::delete('complaints/{complaint}', [ComplaintController::class, 'destroy']);
    Route::get('complaints/{complaint}/attachment', [ComplaintController::class, 'attachment']);
});

Route::prefix('v1/admin')->middleware(['web', 'auth', 'can:store.manage'])->group(function (): void {
    Route::get('meta/configuration', [MetaConfigurationController::class, 'show']);
    Route::post('meta/configuration', [MetaConfigurationController::class, 'store'])->middleware('throttle:20,1');
    Route::post('meta/configuration/{configuration}/test', [MetaConfigurationController::class, 'test'])->middleware('throttle:meta-connection-test');
    Route::post('meta/configuration/{configuration}/activate', [MetaConfigurationController::class, 'activate'])->middleware('throttle:10,1');
    Route::delete('meta/configuration/token', [MetaConfigurationController::class, 'removeToken'])->middleware('throttle:5,1');
    Route::get('navex/configuration', [NavexConfigurationController::class, 'show']);
    Route::post('navex/configuration', [NavexConfigurationController::class, 'store'])->middleware('throttle:20,1');
    Route::post('navex/configuration/{configuration}/test', [NavexConfigurationController::class, 'test'])->middleware('throttle:10,1');
    Route::delete('navex/configuration/credentials/{credential}', [NavexConfigurationController::class, 'removeCredential'])->middleware('throttle:5,1');
    Route::get('first-delivery/configuration', [FirstDeliveryConfigurationController::class, 'show']);
    Route::post('first-delivery/configuration', [FirstDeliveryConfigurationController::class, 'store'])->middleware('throttle:20,1');
    Route::post('first-delivery/configuration/{configuration}/test', [FirstDeliveryConfigurationController::class, 'test'])->middleware('throttle:10,1');
    Route::delete('first-delivery/configuration/token', [FirstDeliveryConfigurationController::class, 'removeToken'])->middleware('throttle:5,1');
    Route::get('meta/diagnostics', [MetaDiagnosticsController::class, 'index']);
    Route::get('meta/diagnostics/{event}', [MetaDiagnosticsController::class, 'show']);
    Route::post('meta/diagnostics/{event}/retry', [MetaDiagnosticsController::class, 'retry'])->middleware('throttle:5,15');
    Route::get('promo-codes', [PromoCodeController::class, 'index']);
    Route::post('promo-codes', [PromoCodeController::class, 'store']);
    Route::patch('promo-codes/{promoCode}', [PromoCodeController::class, 'update']);
    Route::post('promo-codes/{promoCode}/status', [PromoCodeController::class, 'status']);
    Route::delete('promo-codes/{promoCode}', [PromoCodeController::class, 'destroy']);
    Route::get('checkout-fields', [AdminCheckoutFieldController::class, 'index']);
    Route::post('checkout-fields', [AdminCheckoutFieldController::class, 'store']);
    Route::patch('checkout-fields/{checkoutField}', [AdminCheckoutFieldController::class, 'update']);
    Route::delete('checkout-fields/{checkoutField}', [AdminCheckoutFieldController::class, 'destroy']);
    Route::post('checkout-fields/reorder', [AdminCheckoutFieldController::class, 'reorder']);
    Route::get('settings/shipping', [SettingsController::class, 'shipping']);
    Route::patch('settings/shipping', [SettingsController::class, 'updateShipping']);
    Route::get('settings/store', [SettingsController::class, 'store']);
    Route::patch('settings/store', [SettingsController::class, 'updateStore']);
    Route::patch('settings/checkout', [SettingsController::class, 'updateCheckout']);
    Route::get('content/homepage-sections', [HomepageSectionController::class, 'index']);
    Route::post('content/homepage-sections', [HomepageSectionController::class, 'store']);
    Route::patch('content/homepage-sections/{homepageSection}', [HomepageSectionController::class, 'update']);
    Route::delete('content/homepage-sections/{homepageSection}', [HomepageSectionController::class, 'destroy']);
    Route::post('content/homepage-sections/reorder', [HomepageSectionController::class, 'reorder']);
    Route::get('content/banners', [HeroSlideController::class, 'index']);
    Route::post('content/banners/reorder', [HeroSlideController::class, 'reorder']);
    Route::post('content/banners', [HeroSlideController::class, 'store']);
    Route::post('content/banners/{heroSlide}', [HeroSlideController::class, 'update']);
    Route::delete('content/banners/{heroSlide}', [HeroSlideController::class, 'destroy']);
    Route::get('content/items/{contentType}', [HomepageItemController::class, 'index']);
    Route::post('content/items/{contentType}/reorder', [HomepageItemController::class, 'reorder']);
    Route::post('content/items/{contentType}', [HomepageItemController::class, 'store']);
    Route::post('content/items/{contentType}/{contentItem}', [HomepageItemController::class, 'update']);
    Route::delete('content/items/{contentType}/{contentItem}', [HomepageItemController::class, 'destroy']);
    Route::get('content/pages', [AdminStaticPageController::class, 'index']);
    Route::get('content/pages/{staticPage}', [AdminStaticPageController::class, 'show']);
    Route::patch('content/pages/{staticPage}', [AdminStaticPageController::class, 'update']);
});

Route::prefix('v1/admin')->middleware(['web', 'auth'])->group(function (): void {
    Route::get('me', [CurrentUserController::class, 'show']);
    Route::post('me/password', [PasswordController::class, 'update']);
});

Route::prefix('v1/admin')->middleware(['web', 'auth', 'can:users.manage'])->group(function (): void {
    Route::apiResource('users', UserController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
});

Route::prefix('v1/admin')->middleware(['web', 'auth', 'can:users.manage'])->group(function (): void {
    Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);
});
