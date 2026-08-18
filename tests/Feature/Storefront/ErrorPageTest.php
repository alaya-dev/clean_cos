<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_found_page_is_french_safe_and_not_indexable(): void
    {
        $this->get('/adresse-inconnue')
            ->assertNotFound()
            ->assertSee('Cette page est introuvable')
            ->assertSee('Retour à l’accueil')
            ->assertSee('noindex, nofollow', false)
            ->assertDontSee('stack trace', false);
    }

    public function test_fallback_error_templates_are_available_without_the_storefront_layout(): void
    {
        foreach ([403, 419, 429, 500, 503] as $status) {
            $this->assertTrue(view()->exists('errors.'.$status));
        }
    }
}
