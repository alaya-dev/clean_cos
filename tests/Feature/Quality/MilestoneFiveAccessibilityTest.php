<?php

namespace Tests\Feature\Quality;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MilestoneFiveAccessibilityTest extends TestCase
{
    #[Test]
    public function homepage_motion_and_responsive_contract_is_encoded_in_production_assets(): void
    {
        $script = file_get_contents(resource_path('js/storefront/main.ts'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($script);
        $this->assertIsString($styles);
        $this->assertStringContainsString("event.key === 'ArrowLeft'", $script);
        $this->assertStringContainsString("event.key === 'ArrowRight'", $script);
        $this->assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)').matches", $script);
        $this->assertStringContainsString("hero.addEventListener('focusin', stop)", $script);
        $this->assertStringContainsString("hero.addEventListener('mouseenter', stop)", $script);
        $this->assertStringContainsString('8000', $script);
        $this->assertStringContainsString('grid-template-columns:repeat(2,minmax(0,1fr))', $styles);
        $this->assertStringContainsString('grid-template-columns:repeat(3,minmax(0,1fr))!important', $styles);
        $this->assertStringContainsString('grid-template-columns:repeat(4,minmax(0,1fr))!important', $styles);
        $this->assertStringContainsString('@media(prefers-reduced-motion:reduce)', $styles);
        $this->assertStringContainsString('scroll-snap-type:x mandatory', $styles);
        $this->assertStringContainsString('min-width:44px', $styles);
    }
}
