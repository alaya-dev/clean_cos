<?php

namespace Tests\Feature\Documentation;

use Tests\TestCase;

class ReferenceIntegrityTest extends TestCase
{
    public function test_reference_checker_and_authoritative_security_document_are_present(): void
    {
        self::assertFileExists(base_path('scripts/check-doc-references.php'));
        self::assertFileExists(base_path('scripts/check-doc-references.ps1'));
        self::assertFileExists(base_path('docs/security.md'));
        self::assertStringNotContainsString('security-rules.md', file_get_contents(base_path('docs/implementation-plan.md')));
        self::assertStringContainsString('Declared source reading order', file_get_contents(base_path('docs/implementation-plan.md')));
        self::assertStringContainsString('historical audit', file_get_contents(base_path('docs/baseline-audit-phase-0-9.md')));
    }
}
