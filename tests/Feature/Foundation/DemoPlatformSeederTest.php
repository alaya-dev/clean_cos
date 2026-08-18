<?php

namespace Tests\Feature\Foundation;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Commerce\Models\Order;
use App\Domain\Complaints\Models\Complaint;
use App\Domain\Content\Models\HomepageSection;
use App\Domain\MetaTracking\Models\MetaEvent;
use Database\Seeders\DemoPlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoPlatformSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_idempotent_operational_demo_dataset(): void
    {
        $this->seed(DemoPlatformSeeder::class);
        $this->seed(DemoPlatformSeeder::class);

        $this->assertSame(100, Order::query()->count());
        $this->assertSame(100, Order::query()->has('items')->count());
        $this->assertGreaterThan(0, HomepageSection::query()->count());
        $this->assertGreaterThan(0, Complaint::query()->count());
        $this->assertGreaterThan(0, MetaEvent::query()->count());
        $this->assertGreaterThan(0, AuditLog::query()->count());
    }
}
