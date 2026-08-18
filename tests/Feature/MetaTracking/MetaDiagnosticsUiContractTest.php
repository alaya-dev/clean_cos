<?php

namespace Tests\Feature\MetaTracking;

use Tests\TestCase;

class MetaDiagnosticsUiContractTest extends TestCase
{
    public function test_event_detail_uses_a_focused_accessible_drawer_on_desktop_and_mobile(): void
    {
        $view = file_get_contents(resource_path('js/admin/meta-diagnostics.ts'));
        $styles = file_get_contents(resource_path('css/admin-meta.css'));

        self::assertStringContainsString('class="admin-overlay meta-detail-overlay"', $view);
        self::assertStringContainsString('ref="detailDialog"', $view);
        self::assertStringContainsString('@keydown.esc="closeDetail"', $view);
        self::assertStringContainsString('meta-detail__grid', $view);
        self::assertStringContainsString('meta-detail__facts', $view);
        self::assertStringContainsString('closeDetail', $view);
        self::assertStringContainsString('.meta-detail{display:flex;flex-direction:column', $styles);
        self::assertStringContainsString('.meta-detail__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))', $styles);
        self::assertStringContainsString('.meta-detail{width:100%;max-height:none;height:100dvh', $styles);
    }
}
