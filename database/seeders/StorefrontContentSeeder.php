<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Models\BrandContent;
use App\Domain\Content\Models\EditorialSection;
use App\Domain\Content\Models\HeroSlide;
use App\Domain\Content\Models\HomepageSection;
use App\Domain\Content\Models\ReassuranceItem;
use App\Domain\Content\Models\SocialGalleryItem;
use App\Domain\Content\Models\StaticPage;
use App\Domain\Content\Models\VisualCategoryTile;
use App\Domain\Settings\Models\Setting;
use Illuminate\Database\Seeder;

class StorefrontContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettings();
        $this->seedHeroSlides();
        $this->seedHomepageSections();
        $this->seedEditorialContent();
        $this->seedReassuranceItems();
        $this->seedStaticPages();
    }

    private function seedSettings(): void
    {
        foreach ([
            'store.contact' => ['phone' => '+216 20 000 000', 'email' => 'bonjour@ToutDispo.test', 'address' => 'Tunis, Tunisie', 'whatsapp' => '+21620000000', 'social_links' => ['instagram' => 'https://www.instagram.com/', 'facebook' => 'https://www.facebook.com/']],
            'store.announcement' => ['message' => 'Livraison offerte selon les conditions de la boutique.'],
            'store.footer' => ['brand_statement' => 'Des rituels de beauté choisis avec soin.', 'reassurance_statements' => ['Paiement à la livraison', 'Service client à votre écoute']],
        ] as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    private function seedHeroSlides(): void
    {
        foreach ([
            ['Rituel du matin', 'À découvrir', 'Des gestes simples pour commencer la journée', 'Découvrez la sélection du moment.', 'Découvrir', 0],
            ['Soin du soir', 'Le rituel Passion', 'Prendre soin de soi, naturellement', 'Une sélection douce pour votre routine du soir.', 'Voir les soins', 1],
        ] as [$label, $eyebrow, $heading, $text, $ctaLabel, $sortOrder]) {
            HeroSlide::query()->updateOrCreate(['admin_label' => $label], ['eyebrow' => $eyebrow, 'heading' => $heading, 'supporting_text' => $text, 'cta_label' => $ctaLabel, 'cta_url' => '/produits', 'desktop_image_path' => null, 'mobile_image_path' => null, 'is_active' => true, 'sort_order' => $sortOrder]);
        }
    }

    private function seedHomepageSections(): void
    {
        foreach ([
            ['new_products', 'À découvrir', 'Les nouveaux rituels', 'Les nouveautés de la boutique.', true, 0],
            ['best_sellers', 'Les essentiels', 'Les meilleures ventes', 'Les produits les plus appréciés.', false, 1],
            ['curated', 'Sélection Passion', 'Nos incontournables', 'Une sélection préparée avec soin.', false, 2],
        ] as [$type, $eyebrow, $title, $description, $filtersEnabled, $sortOrder]) {
            HomepageSection::query()->updateOrCreate(['type' => $type, 'title' => $title], ['eyebrow' => $eyebrow, 'description' => $description, 'is_active' => true, 'filters_enabled' => $filtersEnabled, 'sort_order' => $sortOrder]);
        }

        Category::query()->orderBy('sort_order')->take(3)->get()->each(function (Category $category, int $sortOrder): void {
            VisualCategoryTile::query()->updateOrCreate(['category_id' => $category->id], ['label' => $category->name, 'desktop_image_path' => null, 'mobile_image_path' => null, 'is_active' => true, 'sort_order' => $sortOrder]);
        });
    }

    private function seedEditorialContent(): void
    {
        EditorialSection::query()->updateOrCreate(['heading' => 'Les gestes qui font du bien'], ['eyebrow' => 'Rituel', 'description' => 'Une routine courte, simple et agréable à adopter.', 'cta_label' => 'Découvrir la sélection', 'cta_url' => '/produits', 'image_path' => null, 'is_active' => true]);
        BrandContent::query()->updateOrCreate(['heading' => 'ToutDispo'], ['content' => '<p>Des rituels de beauté sélectionnés avec soin pour accompagner chaque journée.</p>', 'is_active' => true]);
        foreach (range(1, 4) as $sortOrder) {
            SocialGalleryItem::query()->updateOrCreate(['url' => 'https://www.instagram.com/passioncosmeticdemo'.$sortOrder], ['image_path' => null, 'alt_text' => 'Inspiration beauté ToutDispo '.$sortOrder, 'is_active' => true, 'sort_order' => $sortOrder]);
        }
    }

    private function seedReassuranceItems(): void
    {
        foreach ([['truck', 'Livraison soignée', 'Votre commande est préparée avec attention.'], ['shield', 'Paiement à la livraison', 'Réglez votre commande à la réception.'], ['heart', 'Sélection Passion', 'Des produits choisis pour vos rituels.'], ['message-circle', 'Service client', 'Une équipe disponible pour vous accompagner.']] as $sortOrder => [$icon, $title, $text]) {
            ReassuranceItem::query()->updateOrCreate(['title' => $title], ['icon' => $icon, 'text' => $text, 'is_active' => true, 'sort_order' => $sortOrder]);
        }
    }

    private function seedStaticPages(): void
    {
        foreach ([['about', 'À propos'], ['contact', 'Contact'], ['terms', 'Conditions générales'], ['privacy', 'Confidentialité'], ['delivery', 'Livraison'], ['returns_complaints', 'Retours et réclamations'], ['faq', 'FAQ']] as [$key, $title]) {
            StaticPage::query()->updateOrCreate(['key' => $key], ['title' => $title, 'slug' => $key === 'about' ? 'a-propos' : str_replace('_', '-', $key), 'content' => '<h2>'.$title.'</h2><p>Informations de démonstration pour la boutique ToutDispo.</p>', 'is_active' => true, 'seo_title' => $title.' | ToutDispo', 'seo_description' => 'Informations '.$title.' de ToutDispo.']);
        }
    }
}
