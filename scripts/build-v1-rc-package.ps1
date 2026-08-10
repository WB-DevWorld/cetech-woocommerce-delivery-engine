#Requires -Version 5.1
<#
.SYNOPSIS
    Build a WordPress-installable RC ZIP for CETECH WooCommerce Delivery Engine.

.DESCRIPTION
    Creates dist/cetech-woocommerce-delivery-engine-v{Version}.zip from a clean Git tree.
    Does not commit artifacts. Runs Composer in the staging copy only when vendor/ is missing.
#>
param(
    [string]$Version = '1.0.0-rc.1'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$PluginSlug     = 'cetech-woocommerce-delivery-engine'
$PluginMainFile = "$PluginSlug.php"
$RepoRoot       = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$DistDir        = Join-Path $RepoRoot 'dist'
$BuildRoot      = Join-Path $RepoRoot 'build'
$StageRoot      = Join-Path $BuildRoot "stage-$Version"
$StagePluginDir = Join-Path $StageRoot $PluginSlug
$ZipName        = "$PluginSlug-v$Version.zip"
$ZipPath        = Join-Path $DistDir $ZipName
$ShaPath        = Join-Path $DistDir "$ZipName.sha256"

function Write-Step([string]$Message) {
    Write-Host "==> $Message"
}

function Test-CommandExists([string]$Name) {
    return $null -ne (Get-Command $Name -ErrorAction SilentlyContinue)
}

function Invoke-Git {
    param([string[]]$GitArgs)
    $output = & git -C $RepoRoot @GitArgs 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "git $($GitArgs -join ' ') failed: $output"
    }
    return $output
}

function Ensure-CompressionAssembliesLoaded {
    foreach ($assemblyName in @('System.IO.Compression', 'System.IO.Compression.FileSystem')) {
        $alreadyLoaded = [System.AppDomain]::CurrentDomain.GetAssemblies() |
            Where-Object { $_.GetName().Name -eq $assemblyName }

        if (-not $alreadyLoaded) {
            Add-Type -AssemblyName $assemblyName
        }
    }
}

Write-Step "Verifying Git repository at $RepoRoot"
if (-not (Test-Path (Join-Path $RepoRoot '.git'))) {
    throw 'Not a Git repository.'
}

Write-Step 'Checking Git working tree is clean'
$Status = Invoke-Git @('status', '--porcelain')
if ($Status) {
    throw "Git working tree is not clean:`n$Status"
}

$Branch = (Invoke-Git @('branch', '--show-current')).Trim()
Write-Host "Branch: $Branch"

$MainFilePath = Join-Path $RepoRoot $PluginMainFile
if (-not (Test-Path $MainFilePath)) {
    throw "Missing root plugin file: $PluginMainFile"
}

if (Test-CommandExists 'composer') {
    Write-Step 'Running composer dump-autoload -o in repository (vendor/ is gitignored)'
    Push-Location $RepoRoot
    try {
        & composer dump-autoload -o
        if ($LASTEXITCODE -ne 0) {
            throw 'composer dump-autoload -o failed'
        }
    }
    finally {
        Pop-Location
    }
}
else {
    Write-Warning 'Composer not found in PATH. Staging copy will run composer install if vendor/ is missing.'
}

Write-Step 'Preparing staging directories'
if (Test-Path $StageRoot) {
    Remove-Item -LiteralPath $StageRoot -Recurse -Force
}
New-Item -ItemType Directory -Path $StagePluginDir -Force | Out-Null
New-Item -ItemType Directory -Path $DistDir -Force | Out-Null

$ExcludeDirNames = @(
    '.git', '.github', 'dist', 'build', 'node_modules', 'tests', 'coverage',
    '.vscode', '.idea', '.cursor', 'vendor'
)

$ExcludeFilePatterns = @(
    '*.zip', '*.log', '.env', '.env.local', '.DS_Store', 'Thumbs.db', 'desktop.ini',
    'phpunit.xml', 'phpunit-baseline.txt'
)

Write-Step 'Copying plugin files into staging folder'
Get-ChildItem -LiteralPath $RepoRoot -Force | ForEach-Object {
    $name = $_.Name

    if ($ExcludeDirNames -contains $name) {
        return
    }

    if ($_.PSIsContainer) {
        Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $StagePluginDir $name) -Recurse -Force
        return
    }

    foreach ($pattern in $ExcludeFilePatterns) {
        if ($name -like $pattern) {
            return
        }
    }

    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $StagePluginDir $name) -Force
}

$WoodmartPath = Join-Path $StagePluginDir 'docs/woodmart'
if (Test-Path $WoodmartPath) {
    Write-Step 'Removing docs/woodmart from staging (local compatibility copy only)'
    Remove-Item -LiteralPath $WoodmartPath -Recurse -Force
}

$VendorAutoload = Join-Path $StagePluginDir 'vendor/autoload.php'
if (-not (Test-Path (Join-Path $StagePluginDir 'composer.json'))) {
    throw 'composer.json missing in staging copy.'
}
if (-not (Test-CommandExists 'composer')) {
    throw 'vendor/autoload.php is required but Composer is not available. Install Composer and retry.'
}

# Always rebuild production vendor in staging (never ship repo/dev vendor with PHPUnit).
if (Test-Path (Join-Path $StagePluginDir 'vendor')) {
    Remove-Item -LiteralPath (Join-Path $StagePluginDir 'vendor') -Recurse -Force
}

Write-Step 'Running composer install in staging copy only (no dev, optimized autoload)'
Push-Location $StagePluginDir
try {
    & composer install --no-dev --optimize-autoloader --no-interaction
    if ($LASTEXITCODE -ne 0) {
        throw 'composer install failed in staging copy'
    }
}
finally {
    Pop-Location
}

if (-not (Test-Path $VendorAutoload)) {
    throw 'vendor/autoload.php still missing after composer install.'
}

$VerifyScript = Join-Path $RepoRoot 'scripts/verify-production-package-autoload.php'
if (Test-Path $VerifyScript) {
    Write-Step 'Running production package autoload/boot verification'
    & php $VerifyScript $StagePluginDir
    if ($LASTEXITCODE -ne 0) {
        throw 'Production package verification failed.'
    }
}

Write-Step 'Validating staged structure'
$RequiredPaths = @(
    (Join-Path $StagePluginDir $PluginMainFile),
    (Join-Path $StagePluginDir 'uninstall.php'),
    (Join-Path $StagePluginDir 'src'),
    (Join-Path $StagePluginDir 'database'),
    $VendorAutoload
)

foreach ($path in $RequiredPaths) {
    if (-not (Test-Path $path)) {
        throw "Required staged path missing: $path"
    }
}

function Get-ZipEntrySample {
    param(
        [string[]]$Entries,
        [int]$Limit = 20
    )

    if (-not $Entries -or $Entries.Count -eq 0) {
        return '(no entries)'
    }

    return (($Entries | Select-Object -First $Limit) -join ', ')
}

function New-ForwardSlashPluginZip {
    param(
        [string]$DestinationZipPath,
        [string]$SourcePluginDir,
        [string]$Slug
    )

    Ensure-CompressionAssembliesLoaded

    if (Test-Path $DestinationZipPath) {
        Remove-Item -LiteralPath $DestinationZipPath -Force
    }

    $sourceRoot = (Resolve-Path -LiteralPath $SourcePluginDir).Path
    if (-not $sourceRoot.EndsWith('\')) {
        $sourceRoot += '\'
    }

    $archive = [System.IO.Compression.ZipFile]::Open(
        $DestinationZipPath,
        [System.IO.Compression.ZipArchiveMode]::Create
    )

    try {
        Get-ChildItem -LiteralPath $SourcePluginDir -Recurse -File | ForEach-Object {
            $relativePath = $_.FullName.Substring($sourceRoot.Length) -replace '\\', '/'
            $entryName    = "$Slug/$relativePath"

            if ($entryName -like '*\*') {
                throw "Refusing to create ZIP entry with backslashes: $entryName"
            }

            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $_.FullName,
                $entryName,
                [System.IO.Compression.CompressionLevel]::Optimal
            )
        }
    }
    finally {
        $archive.Dispose()
    }
}

function Write-Sha256ChecksumFile {
    param(
        [string]$FilePath,
        [string]$ChecksumFileName
    )

    $hash = Get-FileHash -LiteralPath $FilePath -Algorithm SHA256
    $line = "$($hash.Hash.ToLower())  $ChecksumFileName"
    Set-Content -LiteralPath (Join-Path (Split-Path -Parent $FilePath) "$ChecksumFileName.sha256") -Value $line -NoNewline -Encoding ASCII
}

function Test-PluginZipStructure {
    param(
        [string]$ZipPathToInspect,
        [string]$Slug,
        [string]$MainFile
    )

    Ensure-CompressionAssembliesLoaded

    $archive = [System.IO.Compression.ZipFile]::OpenRead($ZipPathToInspect)
    try {
        $rawEntries = @($archive.Entries | ForEach-Object { $_.FullName })
        $backslashEntries = @($rawEntries | Where-Object { $_ -like '*\*' })

        if ($backslashEntries.Count -gt 0) {
            $entrySample = Get-ZipEntrySample -Entries $rawEntries
            throw "ZIP contains backslash entry names. Entry sample: $entrySample"
        }

        $entries = @($rawEntries | ForEach-Object { $_ -replace '\\', '/' })
        $topLevel = @(
            $entries |
                ForEach-Object {
                    if ($_ -match '^([^/]+)/') { $Matches[1] }
                } |
                Where-Object { $_ -ne '' } |
                Select-Object -Unique
        )

        if ($topLevel.Count -ne 1 -or $topLevel[0] -ne $Slug) {
            $entrySample = Get-ZipEntrySample -Entries $entries
            throw "Unexpected ZIP top-level folder structure. Expected single folder '$Slug', found: $($topLevel -join ', '). Entry sample: $entrySample"
        }

        $zipMain           = "$Slug/$MainFile"
        $zipSrcPrefix      = "$Slug/src/"
        $zipDatabasePrefix = "$Slug/database/"
        $zipVendorAutoload = "$Slug/vendor/autoload.php"

        if (-not ($entries -contains $zipMain)) {
            $entrySample = Get-ZipEntrySample -Entries $entries
            throw "ZIP missing required entry: $zipMain. Entry sample: $entrySample"
        }

        if (-not ($entries | Where-Object { $_ -like "$zipSrcPrefix*" } | Select-Object -First 1)) {
            $entrySample = Get-ZipEntrySample -Entries $entries
            throw "ZIP missing required entries under: $zipSrcPrefix. Entry sample: $entrySample"
        }

        if (-not ($entries | Where-Object { $_ -like "$zipDatabasePrefix*" } | Select-Object -First 1)) {
            $entrySample = Get-ZipEntrySample -Entries $entries
            throw "ZIP missing required entries under: $zipDatabasePrefix. Entry sample: $entrySample"
        }

        if (-not ($entries -contains $zipVendorAutoload)) {
            $entrySample = Get-ZipEntrySample -Entries $entries
            throw "ZIP missing required Composer autoload entry: $zipVendorAutoload. Entry sample: $entrySample"
        }
    }
    finally {
        $archive.Dispose()
    }
}

Write-Step "Creating ZIP: $ZipPath"
Ensure-CompressionAssembliesLoaded
New-ForwardSlashPluginZip -DestinationZipPath $ZipPath -SourcePluginDir $StagePluginDir -Slug $PluginSlug

Write-Step 'Generating SHA256 checksum'
Write-Sha256ChecksumFile -FilePath $ZipPath -ChecksumFileName $ZipName

$SlugZipName = "$PluginSlug.zip"
$SlugZipPath = Join-Path $DistDir $SlugZipName
Copy-Item -LiteralPath $ZipPath -Destination $SlugZipPath -Force
Write-Sha256ChecksumFile -FilePath $SlugZipPath -ChecksumFileName $SlugZipName

Write-Step 'Inspecting ZIP structure'
Test-PluginZipStructure -ZipPathToInspect $ZipPath -Slug $PluginSlug -MainFile $PluginMainFile

Write-Host 'ZIP entry paths use forward slashes only'
Write-Host 'ZIP top-level folder OK:' $PluginSlug
Write-Host 'ZIP contains root plugin file OK'
Write-Host 'ZIP contains src/ entries OK'
Write-Host 'ZIP contains database/ entries OK'
Write-Host 'ZIP contains vendor/autoload.php OK'

Write-Step 'Cleaning staging directory'
if (-not $StageRoot.StartsWith($BuildRoot, [StringComparison]::OrdinalIgnoreCase)) {
    throw "Refusing to delete staging path outside build/: $StageRoot"
}
Remove-Item -LiteralPath $StageRoot -Recurse -Force

Write-Host ''
Write-Host 'Build complete.'
Write-Host "ZIP:      $ZipPath"
Write-Host "SHA256:   $ShaPath"
Write-Host "ZIP:      $SlugZipPath"
Write-Host "SHA256:   $(Join-Path $DistDir "$SlugZipName.sha256")"
Write-Host ''
Write-Host 'Artifacts are gitignored. Do not commit ZIP files.'
Write-Host 'Run staging smoke tests before tagging v1.0.0-rc.1.'
