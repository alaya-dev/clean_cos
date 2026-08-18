param(
    [string]$Root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
)

$ErrorActionPreference = 'Stop'
$failures = @()
Get-ChildItem -Path (Join-Path $Root 'docs') -Filter '*.md' -File | ForEach-Object {
    if ($_.Name -eq 'baseline-audit-phase-0-9.md') { return }
    $source = $_.FullName
    $content = Get-Content -Raw -LiteralPath $source
    foreach ($match in [regex]::Matches($content, '(?<![A-Za-z0-9_])(?:`|\()([^`()\r\n]+\.md)(?:`|\))')) {
        $target = $match.Groups[1].Value
        if ($target -match '^https?://' -or $target -match '^#') { continue }
        if (-not $target.Contains('/') -and $target -ne 'security-rules.md') { continue }
        $resolved = if ($target.StartsWith('docs/') -or $target.StartsWith('.specify/')) { Join-Path $Root $target } else { Join-Path $_.DirectoryName $target }
        if ($target -eq 'docs/roles-authorization-matrix.md') { $resolved = Join-Path $Root 'docs/Roles and Authorization Matrix.md' }
        if ($target -eq 'security-rules.md' -or -not (Test-Path -LiteralPath $resolved -PathType Leaf)) {
            $failures += "${source}: $target"
        }
    }
}
if ($failures.Count -gt 0) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}
Write-Output 'Documentation references: PASS'
