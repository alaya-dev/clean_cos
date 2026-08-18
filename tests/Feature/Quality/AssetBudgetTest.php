<?php

namespace Tests\Feature\Quality;

use Tests\TestCase;

class AssetBudgetTest extends TestCase
{
    public function test_asset_budget_checker_exists_and_declares_limits(): void
    {
        $script = file_get_contents(base_path('scripts/check-asset-budgets.mjs'));
        self::assertStringContainsString('limits', $script);
        self::assertStringContainsString('process.exit(1)', $script);
    }

    public function test_asset_budget_checker_rejects_an_oversized_fixture(): void
    {
        $fixture = public_path('build/asset-budget-fixture.js');
        file_put_contents($fixture, str_repeat('x', 301 * 1024));
        exec('node scripts/check-asset-budgets.mjs', $output, $status);
        @unlink($fixture);
        self::assertSame(1, $status);
    }
}
