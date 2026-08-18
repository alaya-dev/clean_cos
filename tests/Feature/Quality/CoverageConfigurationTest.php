<?php

namespace Tests\Feature\Quality;

use Tests\TestCase;

class CoverageConfigurationTest extends TestCase
{
    public function test_backend_and_frontend_coverage_commands_are_configured(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('test:coverage', $composer['scripts']);
        self::assertArrayHasKey('test:coverage', $package['scripts']);
        self::assertFileExists(base_path('vitest.config.ts'));
        self::assertStringContainsString('thresholds', file_get_contents(base_path('vitest.config.ts')));
    }
}
