<?php

namespace Database\Seeders;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductOptionGroup;
use App\Domain\Catalog\Models\ProductOptionValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Checkout\Models\CheckoutIdempotencyRecord;
use App\Domain\Checkout\Support\TunisianGovernorates;
use App\Domain\Commerce\Models\CheckoutField;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderNote;
use App\Domain\Complaints\Models\Complaint;
use App\Domain\Content\Models\BrandContent;
use App\Domain\Content\Models\EditorialSection;
use App\Domain\Content\Models\HeroSlide;
use App\Domain\Content\Models\HomepageSection;
use App\Domain\Content\Models\ReassuranceItem;
use App\Domain\Content\Models\SocialGalleryItem;
use App\Domain\Content\Models\StaticPage;
use App\Domain\Content\Models\VisualCategoryTile;
use App\Domain\MetaTracking\Models\MarketingConsent;
use App\Domain\MetaTracking\Models\MetaConfiguration;
use App\Domain\MetaTracking\Models\MetaEvent;
use App\Domain\MetaTracking\Models\MetaEventAttempt;
use App\Domain\Orders\Models\InventoryRestorationMarker;
use App\Domain\Promotions\Models\PromoCode;
use App\Domain\Settings\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoPlatformSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PassionCatalogSeeder::class);

        $superAdmin = User::query()->updateOrCreate(
            ['email' => 'saberbenmbarek87@gmail.com'],
            ['name' => 'Saber Ben Mbarek', 'password' => Hash::make('saberbenmbarek87@gmail.com'), 'role' => 'super_admin', 'is_active' => true, 'force_password_change' => false, 'auth_version' => 1],
        );
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin.demo@ToutDispo.test'],
            ['name' => 'Administration démo', 'password' => Hash::make('password'), 'role' => 'admin', 'is_active' => true, 'force_password_change' => false, 'auth_version' => 1],
        );

        $this->seedSettings($superAdmin);
        $fields = $this->seedCheckoutFields();
        $products = $this->seedCatalogueRelations();
        $this->seedContent($products);
        $promoCode = $this->seedPromoCode();
        $orders = $this->seedOrders($products, $fields, $promoCode, $admin);
        $this->seedComplaints($orders, $admin);
        $this->seedMeta($orders, $superAdmin);
        $this->seedAudit($superAdmin);
    }

    private function seedSettings(User $superAdmin): void
    {
        $settings = [
            'store.contact' => ['phone' => '+216 20 000 000', 'email' => 'bonjour@ToutDispo.test', 'address' => 'Tunis, Tunisie', 'whatsapp' => '+21620000000', 'social_links' => ['instagram' => 'https://www.instagram.com/', 'facebook' => 'https://www.facebook.com/']],
            'store.announcement' => ['message' => 'Livraison offerte selon les conditions de la boutique.'],
            'store.footer' => ['brand_statement' => 'Des rituels de beauté choisis avec soin.', 'reassurance_statements' => ['Paiement à la livraison', 'Service client à votre écoute']],
            'shipping.settings' => ['fixed_fee_millimes' => 8_000, 'free_threshold_enabled' => true, 'free_threshold_millimes' => 120_000],
        ];

        foreach ($settings as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $superAdmin->id]);
        }
    }

    /** @return array<string, CheckoutField> */
    private function seedCheckoutFields(): array
    {
        $definitions = [
            ['full_name', 'Nom et prénom', 'text', true, true, 0],
            ['phone', 'Téléphone', 'text', true, true, 1],
            ['city', 'Ville', 'text', true, true, 2],
            ['governorate', 'Gouvernorat', 'select', true, true, 3],
            ['address', 'Adresse', 'textarea', true, true, 4],
            ['delivery_note', 'Indication de livraison', 'textarea', false, false, 5],
        ];

        $fields = [];
        foreach ($definitions as [$key, $label, $type, $required, $system, $sortOrder]) {
            $fields[$key] = CheckoutField::query()->updateOrCreate(
                ['key' => $key],
                ['label' => $label, 'type' => $type, 'options' => $key === 'governorate' ? TunisianGovernorates::ALL : null, 'is_required' => $required, 'is_active' => true, 'is_system' => $system, 'sort_order' => $sortOrder],
            );
        }

        return $fields;
    }

    /** @return Collection<int, Product> */
    private function seedCatalogueRelations()
    {
        $products = Product::query()->orderBy('id')->get();
        foreach ($products as $position => $product) {
            $product->update(['meta_catalog_id' => (string) (100 + $position)]);
            ProductImage::query()->firstOrCreate(
                ['product_id' => $product->id, 'sort_order' => 0],
                ['product_variant_id' => null, 'path' => null, 'original_path' => null, 'renditions' => null, 'alt_text' => $product->name, 'width' => null, 'height' => null, 'is_primary' => true, 'processing_status' => 'pending'],
            );
        }

        $variantProduct = $products->firstWhere('slug', 'palette-regard-terre-cuite');
        if ($variantProduct !== null) {
            $variantProduct->update(['has_variants' => true, 'stock_quantity' => null]);
            $group = ProductOptionGroup::query()->firstOrCreate(['product_id' => $variantProduct->id, 'name' => 'Teinte'], ['sort_order' => 0]);
            $values = collect(['Terre cuite', 'Rose poudré'])->mapWithKeys(fn (string $value, int $sortOrder) => [$value => ProductOptionValue::query()->firstOrCreate(['product_option_group_id' => $group->id, 'value' => $value], ['sort_order' => $sortOrder])]);
            foreach ($values as $value => $optionValue) {
                $variant = ProductVariant::query()->firstOrCreate(['product_id' => $variantProduct->id, 'combination_key' => 'teinte:'.$value], ['sku' => 'PC-PALETTE-'.strtoupper(str_replace(' ', '-', $value)), 'stock_quantity' => 14, 'low_stock_threshold' => 4, 'is_active' => true]);
                $variant->values()->syncWithoutDetaching([$optionValue->id]);
            }
        }

        foreach (Category::query()->orderBy('sort_order')->get() as $category) {
            DB::table('url_redirects')->updateOrInsert(['from_path' => '/ancienne-categorie/'.$category->slug], ['to_path' => '/categories/'.$category->slug, 'updated_at' => now(), 'created_at' => now()]);
        }

        return $products;
    }

    /** @param Collection<int, Product> $products */
    private function seedContent($products): void
    {
        foreach ([
            ['Rituel du matin', 'À découvrir', 'Des gestes simples pour commencer la journée', 'Découvrez la sélection du moment.', 'Découvrir', '/produits', 0],
            ['Soin du soir', 'Le rituel Passion', 'Prendre soin de soi, naturellement', 'Une sélection douce pour votre routine du soir.', 'Voir les soins', '/produits', 1],
        ] as [$label, $eyebrow, $heading, $text, $ctaLabel, $ctaUrl, $sortOrder]) {
            HeroSlide::query()->updateOrCreate(['admin_label' => $label], ['eyebrow' => $eyebrow, 'heading' => $heading, 'supporting_text' => $text, 'cta_label' => $ctaLabel, 'cta_url' => $ctaUrl, 'desktop_image_path' => null, 'mobile_image_path' => null, 'is_active' => true, 'sort_order' => $sortOrder]);
        }

        $sections = [
            ['new_products', 'À découvrir', 'Les nouveaux rituels', 'Les nouveautés de la boutique.', true, 0],
            ['best_sellers', 'Les essentiels', 'Les meilleures ventes', 'Les produits les plus appréciés.', false, 1],
            ['curated', 'Sélection Passion', 'Nos incontournables', 'Une sélection préparée avec soin.', false, 2],
        ];
        foreach ($sections as [$type, $eyebrow, $title, $description, $filtersEnabled, $sortOrder]) {
            $section = HomepageSection::query()->updateOrCreate(['type' => $type, 'title' => $title], ['eyebrow' => $eyebrow, 'description' => $description, 'is_active' => true, 'filters_enabled' => $filtersEnabled, 'sort_order' => $sortOrder]);
            $section->products()->sync($products->take(4)->values()->mapWithKeys(fn (Product $product, int $position) => [$product->id => ['sort_order' => $position]])->all());
        }

        foreach (Category::query()->orderBy('sort_order')->take(3)->get() as $sortOrder => $category) {
            VisualCategoryTile::query()->updateOrCreate(['category_id' => $category->id], ['label' => $category->name, 'desktop_image_path' => null, 'mobile_image_path' => null, 'is_active' => true, 'sort_order' => $sortOrder]);
        }

        $editorial = EditorialSection::query()->updateOrCreate(['heading' => 'Les gestes qui font du bien'], ['eyebrow' => 'Rituel', 'description' => 'Une routine courte, simple et agréable à adopter.', 'cta_label' => 'Découvrir la sélection', 'cta_url' => '/produits', 'image_path' => null, 'is_active' => true]);
        $editorial->products()->sync($products->take(3)->values()->mapWithKeys(fn (Product $product, int $position) => [$product->id => ['sort_order' => $position]])->all());

        foreach ([['livraison_rapide', 'Livraison soignée', 'Votre commande est préparée avec attention.'], ['paiement_livraison', 'Paiement à la livraison', 'Réglez votre commande à la réception.'], ['ingredients_naturels', 'Sélection Clean’Cos', 'Des produits choisis pour vos rituels.'], ['teste_dermatologiquement', 'Testé dermatologiquement', 'Des formules sélectionnées avec attention.']] as $sortOrder => [$iconKey, $title, $text]) {
            ReassuranceItem::query()->updateOrCreate(['title' => $title], ['icon' => $iconKey, 'icon_key' => $iconKey, 'text' => $text, 'is_active' => true, 'sort_order' => $sortOrder]);
        }
        foreach (range(1, 4) as $sortOrder) {
            SocialGalleryItem::query()->updateOrCreate(['url' => 'https://www.instagram.com/passioncosmeticdemo'.$sortOrder], ['image_path' => null, 'alt_text' => 'Inspiration beauté ToutDispo '.$sortOrder, 'is_active' => true, 'sort_order' => $sortOrder]);
        }
        BrandContent::query()->updateOrCreate(['heading' => 'ToutDispo'], ['content' => '<p>Des rituels de beauté sélectionnés avec soin pour accompagner chaque journée.</p>', 'is_active' => true]);
        foreach ([['about', 'À propos'], ['contact', 'Contact'], ['terms', 'Conditions générales'], ['privacy', 'Confidentialité'], ['delivery', 'Livraison'], ['returns_complaints', 'Retours et réclamations'], ['faq', 'FAQ']] as [$key, $title]) {
            StaticPage::query()->where('key', $key)->update(['title' => $title, 'content' => '<h2>'.$title.'</h2><p>Informations de démonstration pour la boutique ToutDispo.</p>', 'is_active' => true, 'seo_title' => $title.' | ToutDispo', 'seo_description' => 'Informations '.$title.' de ToutDispo.']);
        }
    }

    private function seedPromoCode(): PromoCode
    {
        $promoCode = PromoCode::query()->updateOrCreate(['code' => 'BIENVENUE10'], ['discount_percentage' => 10, 'usage_limit' => 500, 'minimum_subtotal_millimes' => 40_000, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addYear(), 'is_active' => true, 'archived_at' => null]);
        $promoCode->forceFill(['usage_count' => 24])->saveQuietly();

        return $promoCode;
    }

    /** @param Collection<int, Product> $products
     * @param  array<string, CheckoutField>  $fields
     * @return Collection<int, Order>
     */
    private function seedOrders($products, array $fields, PromoCode $promoCode, User $admin)
    {
        $statuses = ['nouvelle', 'confirmee', 'tentative_1', 'tentative_2', 'tentative_3', 'annulee'];
        $orders = collect();
        for ($number = 1; $number <= 100; $number++) {
            $product = $products[($number - 1) % $products->count()];
            $quantity = ($number % 3) + 1;
            $subtotal = $product->regular_price_millimes * $quantity;
            $usesPromo = $number % 5 === 0;
            $promoDiscount = $usesPromo ? intdiv($subtotal * 10, 100) : 0;
            $shipping = $subtotal - $promoDiscount >= 120_000 ? 0 : 8_000;
            $key = sprintf('00000000-0000-4000-8000-%012d', $number);
            $status = $statuses[($number - 1) % count($statuses)];
            $createdAt = now()->subDays($number % 45)->subHours($number % 12);
            $order = Order::query()->firstOrCreate(
                ['checkout_idempotency_key' => $key],
                ['status' => $status, 'customer_name' => 'Cliente démo '.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'customer_phone' => '20'.str_pad((string) $number, 6, '0', STR_PAD_LEFT), 'customer_city' => ['Tunis', 'Sfax', 'Sousse', 'Nabeul', 'Bizerte'][$number % 5], 'customer_governorate' => ['Tunis', 'Sfax', 'Sousse', 'Nabeul', 'Bizerte'][$number % 5], 'customer_address' => $number.' rue de démonstration', 'subtotal_millimes' => $subtotal, 'product_discount_millimes' => 0, 'promo_code_discount_millimes' => $promoDiscount, 'shipping_fee_millimes' => $shipping, 'total_millimes' => $subtotal - $promoDiscount + $shipping, 'promo_code_id' => $usesPromo ? $promoCode->id : null, 'promo_code_snapshot' => $usesPromo ? ['code' => $promoCode->code, 'discount_percentage' => 10] : null, 'lock_version' => 1, 'created_at' => $createdAt, 'updated_at' => $createdAt],
            );
            $order->items()->firstOrCreate(['product_id' => $product->id], ['product_variant_id' => null, 'product_name_snapshot' => $product->name, 'variant_snapshot' => null, 'regular_unit_price_millimes' => $product->regular_price_millimes, 'effective_unit_price_millimes' => $product->regular_price_millimes, 'quantity' => $quantity, 'line_total_millimes' => $subtotal, 'meta_catalog_id_snapshot' => $product->meta_catalog_id]);
            foreach (['full_name' => $order->customer_name, 'phone' => $order->customer_phone, 'city' => $order->customer_city, 'governorate' => $order->customer_governorate, 'address' => $order->customer_address] as $keyName => $value) {
                $field = $fields[$keyName];
                $order->checkoutValues()->firstOrCreate(['field_key_snapshot' => $keyName], ['checkout_field_id' => $field->id, 'label_snapshot' => $field->label, 'type_snapshot' => $field->type, 'is_required_snapshot' => $field->is_required, 'value' => $value]);
            }
            $order->statusHistory()->firstOrCreate(['to_status' => $status], ['from_status' => null, 'reason' => 'Commande démo', 'changed_by' => $admin->id, 'created_at' => $createdAt]);
            CheckoutIdempotencyRecord::query()->firstOrCreate(['order_id' => $order->id], ['idempotency_key' => $key, 'canonical_payload_hash' => hash('sha256', 'demo-order-'.$number), 'expires_at' => now()->addDays(30)]);
            if ($number % 8 === 0) {
                OrderNote::query()->firstOrCreate(['order_id' => $order->id, 'body' => 'Note interne de démonstration.'], ['user_id' => $admin->id, 'created_at' => $createdAt]);
            }
            if ($status === 'annulee') {
                $movement = InventoryMovement::query()->firstOrCreate(['product_id' => $product->id, 'reason' => 'Restock commande démo '.$number], ['actor_user_id' => $admin->id, 'type' => 'restock', 'quantity_delta' => $quantity, 'quantity_before' => max(0, $product->stock_quantity ?? 0), 'quantity_after' => ($product->stock_quantity ?? 0) + $quantity]);
                InventoryRestorationMarker::query()->firstOrCreate(['order_id' => $order->id, 'restoration_reason' => $status], ['inventory_movement_id' => $movement->id, 'created_at' => $createdAt]);
            }
            $orders->push($order);
        }

        return $orders;
    }

    /** @param Collection<int, Order> $orders */
    private function seedComplaints($orders, User $admin): void
    {
        foreach (range(1, 6) as $number) {
            $order = $orders[$number - 1];
            $status = ['nouvelle', 'en_cours', 'resolue'][($number - 1) % 3];
            $complaint = Complaint::query()->firstOrCreate(['customer_phone' => $order->customer_phone, 'subject' => 'Demande démo '.$number], ['order_id' => $order->id, 'customer_name' => $order->customer_name, 'description' => 'Message de démonstration pour le suivi de la réclamation.', 'status' => $status, 'consent_at' => now()->subDays($number), 'resolved_at' => $status === 'resolue' ? now()->subDay() : null]);
            $complaint->notes()->firstOrCreate(['body' => 'Note interne de démonstration.'], ['user_id' => $admin->id, 'created_at' => now()->subDays($number)]);
            $complaint->statusHistory()->firstOrCreate(['to_status' => $status], ['from_status' => null, 'changed_by' => $admin->id, 'created_at' => now()->subDays($number)]);
        }
    }

    /** @param Collection<int, Order> $orders */
    private function seedMeta($orders, User $superAdmin): void
    {
        MarketingConsent::query()->firstOrCreate(['policy_version' => 1, 'marketing_consent' => true], ['necessary_consent' => true, 'decided_at' => now()->subDays(2)]);
        $configuration = MetaConfiguration::query()->firstOrCreate(['configuration_version' => 1, 'state' => 'proposed'], ['tracking_enabled' => false, 'pixel_id' => null, 'test_mode' => true, 'test_event_code' => null, 'created_by' => $superAdmin->id]);
        foreach ($orders->take(12) as $position => $order) {
            $event = MetaEvent::query()->firstOrCreate(['event_id' => 'demo_purchase_'.$order->public_reference], ['event_name' => 'Purchase', 'order_id' => $order->id, 'meta_configuration_id' => $configuration->id, 'event_time' => $order->created_at, 'consent_policy_version' => 1, 'marketing_consent' => true, 'is_synthetic' => true, 'source_url' => '/commande/confirmation', 'context_summary' => ['catalogue_mapping' => 'complete'], 'payload_hash' => hash('sha256', 'demo-meta-'.$order->id), 'browser_state' => 'rendered', 'capi_state' => $position % 4 === 0 ? 'temporary_failure' : 'succeeded', 'retry_count' => $position % 4 === 0 ? 1 : 0]);
            MetaEventAttempt::query()->firstOrCreate(['meta_event_id' => $event->id, 'channel' => 'synthetic_test', 'attempt_number' => 1], ['outcome' => $event->capi_state === 'succeeded' ? 'succeeded' : 'temporary_failure', 'http_status' => $event->capi_state === 'succeeded' ? 200 : 503, 'error_classification' => $event->capi_state === 'succeeded' ? null : 'network', 'attempted_at' => $order->created_at]);
        }
    }

    private function seedAudit(User $superAdmin): void
    {
        foreach (['settings.updated', 'content.section.updated', 'order.status.changed', 'meta.configuration.created'] as $offset => $action) {
            AuditLog::query()->firstOrCreate(['action' => $action, 'auditable_id' => 'demo-'.$offset], ['actor_user_id' => $superAdmin->id, 'actor_role_snapshot' => 'super_admin', 'auditable_type' => 'demo', 'request_id' => 'seed-demo-'.$offset, 'before' => null, 'after' => ['seeded' => true], 'created_at' => now()->subMinutes($offset)]);
        }
    }
}
