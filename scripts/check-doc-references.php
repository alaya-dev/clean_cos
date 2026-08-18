<?php

$root = dirname(__DIR__);
$failures = [];
foreach (glob($root.'/docs/*.md') as $source) {
    if (basename($source) === 'baseline-audit-phase-0-9.md') {
        continue;
    }
    $content = file_get_contents($source);
    preg_match_all('/(?<![A-Za-z0-9_])(?:`|\()([^`()\r\n]+\.md)(?:`|\))/', $content, $matches);
    foreach ($matches[1] as $target) {
        if (preg_match('/^(https?:\/\/|#)/', $target)) {
            continue;
        }
        if (! str_contains($target, '/') && $target !== 'security-rules.md') {
            continue;
        }
        $candidate = (str_starts_with($target, 'docs/') || str_starts_with($target, '.specify/')) ? $root.DIRECTORY_SEPARATOR.$target : dirname($source).DIRECTORY_SEPARATOR.$target;
        if ($target === 'docs/roles-authorization-matrix.md') {
            $candidate = $root.'/docs/Roles and Authorization Matrix.md';
        }
        if (! is_file($candidate) && $target !== 'security-rules.md') {
            $failures[] = "$source: $target";
        }
        if ($target === 'security-rules.md') {
            $failures[] = "$source: $target";
        }
    }
}
if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures).PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Documentation references: PASS'.PHP_EOL);
