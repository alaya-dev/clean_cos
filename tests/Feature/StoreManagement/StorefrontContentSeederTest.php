<?php

namespace Tests\Feature\StoreManagement;

use App\Domain\Content\Models\HeroSlide;
use App\Domain\Content\Models\HomepageSection;
use App\Domain\Content\Models\StaticPage;
use Database\Seeders\StorefrontContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontContentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_seeder_is_safe_with_model_events_and_populates_admin_content(): void
    {
        $this->seed(StorefrontContentSeeder::class);

        $this->assertSame(2, HeroSlide::query()->count());
        $this->assertTrue(HeroSlide::query()->whereNull('public_id')->doesntExist());
        $this->assertGreaterThanOrEqual(3, HomepageSection::query()->count());
        $this->assertTrue(HomepageSection::query()->whereNull('public_id')->doesntExist());
        $this->assertSame(7, StaticPage::query()->count());
    }
}
