<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationHealthTest extends TestCase
{
    public function test_liveness_endpoint_returns_a_request_identifier(): void
    {
        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertJson(['status' => 'ok'])
            ->assertHeader('X-Request-Id');
    }

    public function test_storefront_shell_renders_in_french(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Clean’Cos')
            ->assertSee('lang="fr"', false);
    }
}
