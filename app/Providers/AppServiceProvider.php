<?php

namespace App\Providers;

use App\Domain\Catalog\Models\Category;
use App\Domain\Checkout\Services\ShippingCalculator;
use App\Domain\Content\Models\StaticPage;
use App\Domain\Settings\Services\StoreSettings;
use App\Policies\AuditLogPolicy;
use App\Policies\BackOfficeUserPolicy;
use App\Policies\CatalogPolicy;
use App\Policies\ComplaintPolicy;
use App\Policies\StoreManagementPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('media-upload', function (Request $request): Limit {
            return Limit::perMinute(20)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('complaints', function (Request $request): array {
            $phone = preg_replace('/\D+/', '', (string) $request->input('customer_phone')) ?? '';

            return [
                Limit::perHour(3)->by('ip:'.($request->ip() ?? 'unknown')),
                Limit::perDay(5)->by('phone:'.hash_hmac('sha256', $phone, (string) config('app.key'))),
            ];
        });
        RateLimiter::for('checkout-orders', function (Request $request): Limit {
            $phone = preg_replace('/\D+/', '', (string) $request->input('customer.phone')) ?? '';
            $identity = hash_hmac('sha256', ($request->ip() ?? 'unknown').'|'.$phone, (string) config('app.key'));

            return Limit::perMinute(8)->by('checkout:'.$identity);
        });
        RateLimiter::for('checkout-drafts', function (Request $request): Limit {
            return Limit::perMinute(60)->by('checkout-draft:'.($request->ip() ?? 'unknown'));
        });
        RateLimiter::for('meta-connection-test', function (Request $request): Limit {
            return Limit::perMinute(5)->by('meta-test:'.(string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('admin-order-changes', function (Request $request): Limit {
            return Limit::perMinute(120)->by('admin-order-changes:'.(string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        Gate::define('catalog.manage', [CatalogPolicy::class, 'manage']);
        Gate::define('users.manage', [BackOfficeUserPolicy::class, 'manage']);
        Gate::define('audit.view', [AuditLogPolicy::class, 'viewAny']);
        Gate::define('store.manage', [StoreManagementPolicy::class, 'manage']);
        Gate::define('complaints.manage', [ComplaintPolicy::class, 'manage']);

        View::composer('components.layouts.storefront', function ($view): void {
            $context = Cache::remember('pc:cache:storefront:layout', now()->addMinutes(10), function (): array {
                $settings = app(StoreSettings::class);
                $shipping = app(ShippingCalculator::class);

                return [
                    'storeContext' => [
                        'phone' => $settings->get('store.phone'), 'email' => $settings->get('store.email'),
                        'address' => $settings->get('store.address'), 'social_links' => $settings->get('store.social_links'),
                        'announcement_text' => $settings->get('store.announcement_text'), 'footer_statement' => $settings->get('store.footer_statement'),
                        'shipping_announcement' => $shipping->announcement(),
                    ],
                    'navigationCategories' => Category::query()->where('is_active', true)->orderBy('sort_order')->get(['name', 'slug']),
                    'footerPages' => StaticPage::query()->where('is_active', true)->orderBy('id')->get(['title', 'slug']),
                ];
            });
            $view->with($context);
        });
    }
}
