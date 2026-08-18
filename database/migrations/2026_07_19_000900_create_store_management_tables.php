<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 160)->unique();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('settings')->insert([
            'key' => 'checkout.schema_version',
            'value' => json_encode(1, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 80)->unique();
            $table->unsignedTinyInteger('discount_percentage');
            $table->unsignedInteger('usage_limit');
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedBigInteger('minimum_subtotal_millimes')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('promo_code_id')->nullable()->after('total_millimes')->constrained()->nullOnDelete();
            $table->json('promo_code_snapshot')->nullable()->after('promo_code_id');
        });
        Schema::table('order_checkout_values', function (Blueprint $table): void {
            $table->boolean('is_required_snapshot')->default(false)->after('type_snapshot');
        });
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('image_path', 500)->nullable()->after('description');
            $table->string('image_original_path', 500)->nullable()->after('image_path');
            $table->string('image_processing_status', 20)->nullable()->after('image_original_path');
            $table->unsignedInteger('image_width')->nullable()->after('image_processing_status');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');
        });

        Schema::create('hero_slides', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('admin_label', 200);
            $table->string('eyebrow', 160)->nullable();
            $table->string('heading', 240);
            $table->text('supporting_text')->nullable();
            $table->string('cta_label', 120)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->string('desktop_image_path', 500)->nullable();
            $table->string('mobile_image_path', 500)->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('homepage_sections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('type', 40);
            $table->string('eyebrow', 160)->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('filters_enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });
        Schema::create('homepage_section_products', function (Blueprint $table): void {
            $table->foreignId('homepage_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['homepage_section_id', 'product_id']);
            $table->index(['homepage_section_id', 'sort_order']);
        });

        Schema::create('visual_category_tiles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('label', 160);
            $table->string('desktop_image_path', 500)->nullable();
            $table->string('mobile_image_path', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('editorial_sections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('eyebrow', 160)->nullable();
            $table->string('heading', 240);
            $table->text('description')->nullable();
            $table->string('cta_label', 120)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->string('image_path', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('editorial_section_products', function (Blueprint $table): void {
            $table->foreignId('editorial_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['editorial_section_id', 'product_id']);
        });

        Schema::create('reassurance_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('icon', 40);
            $table->string('title', 160);
            $table->string('text', 300);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });
        Schema::create('social_gallery_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('image_path', 500)->nullable();
            $table->string('url', 500);
            $table->string('alt_text', 255);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });
        Schema::create('brand_contents', function (Blueprint $table): void {
            $table->id();
            $table->string('heading', 240);
            $table->longText('content');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('static_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('title', 200);
            $table->string('slug', 190)->unique();
            $table->longText('content');
            $table->boolean('is_active')->default(false);
            $table->string('seo_title', 255)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamps();
            $table->index('is_active');
        });

        Schema::create('complaints', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_reference')->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 180);
            $table->string('customer_phone', 40);
            $table->string('subject', 200);
            $table->text('description');
            $table->string('status', 30)->default('nouvelle');
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamp('consent_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['customer_phone', 'created_at']);
        });
        Schema::create('complaint_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
            $table->index(['complaint_id', 'created_at']);
        });
        Schema::create('complaint_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['complaint_id', 'created_at']);
        });

        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_percentage_check CHECK (discount_percentage BETWEEN 1 AND 100)');
        DB::statement('ALTER TABLE promo_codes ADD CONSTRAINT promo_codes_usage_check CHECK (usage_count <= usage_limit)');
        DB::statement("ALTER TABLE complaints ADD CONSTRAINT complaints_status_check CHECK (status IN ('nouvelle','en_cours','resolue'))");

        DB::table('homepage_sections')->insert([[
            'public_id' => (string) Str::ulid(), 'type' => 'new_products', 'eyebrow' => 'À découvrir',
            'title' => 'Les nouveaux rituels', 'description' => null, 'is_active' => true,
            'filters_enabled' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], [
            'public_id' => (string) Str::ulid(), 'type' => 'best_sellers', 'eyebrow' => 'À ne pas manquer',
            'title' => 'Les essentiels Passion', 'description' => 'Une sélection gérée depuis le back-office.', 'is_active' => true,
            'filters_enabled' => false, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        $this->seedStaticPages();
    }

    private function seedStaticPages(): void
    {
        $pages = [
            ['about', 'À propos', 'a-propos'],
            ['contact', 'Contact', 'contact'],
            ['terms', 'Conditions générales', 'conditions-generales'],
            ['privacy', 'Confidentialité', 'confidentialite'],
            ['delivery', 'Livraison', 'livraison'],
            ['returns_complaints', 'Retours et réclamations', 'retours-et-reclamations'],
            ['faq', 'FAQ', 'faq'],
        ];
        foreach ($pages as [$key, $title, $slug]) {
            DB::table('static_pages')->insert([
                'key' => $key, 'title' => $title, 'slug' => $slug, 'content' => '',
                'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_status_history');
        Schema::dropIfExists('complaint_notes');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('static_pages');
        Schema::dropIfExists('brand_contents');
        Schema::dropIfExists('social_gallery_items');
        Schema::dropIfExists('reassurance_items');
        Schema::dropIfExists('editorial_section_products');
        Schema::dropIfExists('editorial_sections');
        Schema::dropIfExists('visual_category_tiles');
        Schema::dropIfExists('homepage_section_products');
        Schema::dropIfExists('homepage_sections');
        Schema::dropIfExists('hero_slides');
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn(['image_path', 'image_original_path', 'image_processing_status', 'image_width', 'image_height']);
        });
        Schema::table('order_checkout_values', fn (Blueprint $table) => $table->dropColumn('is_required_snapshot'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn('promo_code_snapshot');
        });
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('settings');
    }
};
