# Builds dist/blue-lens-analytics.zip: one top-level blue-lens-analytics/ folder, forward-slash entry names,
# repository-only files left out. Works in Windows PowerShell 5.1 and PowerShell 7+.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem

$root    = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$dist    = Join-Path $root 'dist'
$zipPath = Join-Path $dist 'blue-lens-analytics.zip'
$exclude = @('docs', 'bin', 'dist', '.git', '.github', '.gitignore', '.gitattributes', 'README.md', 'CHANGELOG.md',
             'tests', '.wordpress-org', '.distignore', '.eslintrc.json', 'node_modules', 'vendor', 'phpunit.xml.dist', 'phpcs.xml.dist', 'phpstan.neon.dist', 'composer.lock', 'package-lock.json', '.phpunit.result.cache')

New-Item -ItemType Directory -Force $dist | Out-Null
if (Test-Path $zipPath) { [System.IO.File]::Delete($zipPath) }

$sep = [IO.Path]::DirectorySeparatorChar
function Get-Relative([string]$full) { $full.Substring($root.Length).TrimStart('\', '/') }
function Get-EntryName([string]$full) { 'blue-lens-analytics/' + ((Get-Relative $full) -split [regex]::Escape($sep) -join '/') }
function Test-Shipped([string]$full) { $first = ((Get-Relative $full) -split '[\\/]')[0]; -not ($exclude -contains $first) }

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
try {
	Get-ChildItem $root -Recurse -Directory -Force | Where-Object { Test-Shipped $_.FullName } | Sort-Object FullName | ForEach-Object {
		[void]$zip.CreateEntry((Get-EntryName $_.FullName) + '/')
	}
	Get-ChildItem $root -Recurse -File -Force | Where-Object { Test-Shipped $_.FullName } | Sort-Object FullName | ForEach-Object {
		[void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, (Get-EntryName $_.FullName), [System.IO.Compression.CompressionLevel]::Optimal)
	}
} finally {
	$zip.Dispose()
}

$check = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$count = $check.Entries.Count
$check.Dispose()
"Built $zipPath ($count entries, $((Get-Item $zipPath).Length) bytes)"
