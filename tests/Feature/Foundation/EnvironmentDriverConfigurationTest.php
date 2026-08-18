<?php

namespace Tests\Feature\Foundation;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EnvironmentDriverConfigurationTest extends TestCase
{
    public function test_operational_drivers_come_from_environment_configuration(): void
    {
        self::assertSame(env('CACHE_STORE'), config('cache.default'));
        self::assertSame(env('QUEUE_CONNECTION'), config('queue.default'));
        self::assertSame(env('SESSION_DRIVER'), config('session.driver'));
    }

    public function test_readiness_contract_is_minimal_and_safe(): void
    {
        $response = $this->getJson('/api/health/ready');
        self::assertContains($response->status(), [200, 503]);
        self::assertArrayHasKey('status', $response->json());
        self::assertArrayNotHasKey('trace', $response->json());
    }

    public function test_cache_failure_returns_safe_readiness_response(): void
    {
        $store = Mockery::mock();
        $store->shouldReceive('get')->andThrow(new \RuntimeException('cache backend detail'));
        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('store')->withNoArgs()->andReturn($store);
        config(['session.driver' => 'array']);
        $this->app->instance(CacheManager::class, $cache);
        Cache::swap($cache);
        $response = $this->getJson('/api/health/ready')->assertStatus(503)->assertJson(['status' => 'unavailable']);
        self::assertStringNotContainsString('cache backend detail', $response->getContent());
    }
}
