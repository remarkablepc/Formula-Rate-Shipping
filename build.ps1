[CmdletBinding()]
param(
    [switch]$NoBump,
    [switch]$BumpMajor, # +1.0.0 (Major refactor / breaking change)
    [switch]$BumpMinor, # +0.1.0 (Feature addition / enhancement)
    [switch]$BumpPatch, # +0.0.1 (Bug fix / patch - default when bumping)
    [string]$ReleaseType, # 'Patch', 'Minor', 'Feature', 'Major'
    [string]$Type,        # Alias for ReleaseType
    [switch]$NoZip,
    [string]$VersionOverride,
    [switch]$FlatOnly,
    [string]$CommitMsg,
    [switch]$NoCommit,
    [switch]$DryRun,
    [string]$ChangelogNote,
    [string]$WpPluginDir = "C:\xampp\htdocs\wp\wp-content\plugins\formula-rate-shipping",
    [switch]$NoSync
)

# Normalize ReleaseType / Type parameter if supplied
$ChosenType = $ReleaseType
if (-not $ChosenType) { $ChosenType = $Type }
if ($ChosenType) {
    if ($ChosenType -eq 'Feature' -or $ChosenType -eq 'Minor') {
        $BumpMinor = $true
    } elseif ($ChosenType -eq 'Major') {
        $BumpMajor = $true
    } elseif ($ChosenType -eq 'Patch') {
        $BumpPatch = $true
    }
}

$ErrorActionPreference = "Stop"
$ScriptDir = $PSScriptRoot
if (-not $ScriptDir) {
    $ScriptDir = Get-Location
}

Set-Location $ScriptDir

$PhpFile = Join-Path $ScriptDir "formula-rate-shipping.php"
$ReadmeMdFile = Join-Path $ScriptDir "README.md"
$ReadmeTxtFile = Join-Path $ScriptDir "readme.txt"
$ChangelogFile = Join-Path $ScriptDir "CHANGELOG.md"
$PluginSlug = "formula-rate-shipping"

if (-not (Test-Path $PhpFile)) {
    Write-Error "Could not find $PhpFile"
    exit 1
}

# 1. Read current version from formula-rate-shipping.php (robust regex supporting SemVer X.Y or X.Y.Z)
$PhpContent = Get-Content -Path $PhpFile -Raw -Encoding UTF8
if ($PhpContent -match '(?i)\*\s*Version:\s*([0-9]+(?:\.[0-9]+)+)[\t ]*(?:#|\/\/|\/\*|$)') {
    $CurrentVersion = $Matches[1]
} elseif ($PhpContent -match '(?i)\*\s*Version:\s*([0-9]+(?:\.[0-9]+)+)') {
    $CurrentVersion = $Matches[1]
} else {
    Write-Error "Could not parse current Version header from $PhpFile"
    exit 1
}

# Parse standard SemVer (X.Y.Z)
$VerParts = $CurrentVersion.Split('.')
$Major = if ($VerParts.Length -ge 1) { [int]$VerParts[0] } else { 0 }
$Minor = if ($VerParts.Length -ge 2) { [int]$VerParts[1] } else { 62 }
$Patch = if ($VerParts.Length -ge 3) { [int]$VerParts[2] } else { 0 }

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host " Formula Rate Shipping Build Script" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "Current Plugin Version: $CurrentVersion (SemVer: $Major.$Minor.$Patch)" -ForegroundColor Yellow

# 2. Determine new version based on SemVer rules
if ($VersionOverride) {
    $NewVersion = $VersionOverride
    Write-Host "Using Version Override: $NewVersion" -ForegroundColor Green
} elseif ($NoBump) {
    $NewVersion = "$Major.$Minor.$Patch"
    Write-Host "Version bump skipped (-NoBump flag set)." -ForegroundColor DarkYellow
} elseif ($BumpMajor) {
    $NewVersion = "$($Major + 1).0.0"
    Write-Host "Bumping MAJOR version (+1.0.0): $CurrentVersion -> $NewVersion" -ForegroundColor Green
} elseif ($BumpMinor) {
    $NewVersion = "$Major.$($Minor + 1).0"
    Write-Host "Bumping MINOR version (+0.1.0): $CurrentVersion -> $NewVersion" -ForegroundColor Green
} else {
    # Default bump: Patch (+0.0.1)
    $NewVersion = "$Major.$Minor.$($Patch + 1)"
    Write-Host "Bumping PATCH version (+0.0.1): $CurrentVersion -> $NewVersion" -ForegroundColor Green
}

# Determine commit message early for DryRun summary & git commit
$ResolvedCommitMsg = $CommitMsg
if (-not $ResolvedCommitMsg) {
    if ($env:COMMIT_MSG) {
        $ResolvedCommitMsg = $env:COMMIT_MSG
    } elseif (Test-Path "commit.txt") {
        $ResolvedCommitMsg = (Get-Content -Path "commit.txt" -Raw).Trim()
    } elseif ($BumpMajor) {
        $ResolvedCommitMsg = "Major release v${NewVersion}: major refactor / architectural updates"
    } elseif ($BumpMinor) {
        $ResolvedCommitMsg = "Feature release v${NewVersion}: new feature addition"
    } else {
        $ResolvedCommitMsg = "Patch release v${NewVersion}: bug fixes and minor updates"
    }
}

# Determine changelog release note
if (-not $ChangelogNote) {
    if ($BumpMajor) {
        $ChangelogNote = "Major refactor / breaking changes."
    } elseif ($BumpMinor) {
        $ChangelogNote = "New feature additions."
    } else {
        $ChangelogNote = "Bug fixes and minor updates."
    }
}

$TodayDate = Get-Date -Format "yyyy-MM-dd"

# ---------------------------------------------------------
# DRY RUN MODE HANDLER
# ---------------------------------------------------------
if ($DryRun) {
    Write-Host "`n==========================================" -ForegroundColor Yellow
    Write-Host " DRY RUN SUMMARY (No files or git modified)" -ForegroundColor Yellow
    Write-Host "==========================================" -ForegroundColor Yellow
    Write-Host "Current Version: $CurrentVersion" -ForegroundColor White
    Write-Host "New Version:     $NewVersion" -ForegroundColor White

    Write-Host "`nFiles that WOULD be updated:" -ForegroundColor Cyan
    if ($PhpFile -and (Test-Path $PhpFile)) { Write-Host "  - formula-rate-shipping.php" -ForegroundColor White }
    if (Test-Path $ReadmeMdFile) { Write-Host "  - README.md" -ForegroundColor White }
    if (Test-Path $ReadmeTxtFile) { Write-Host "  - readme.txt" -ForegroundColor White }
    if (Test-Path $ChangelogFile) { Write-Host "  - CHANGELOG.md (would append entry)" -ForegroundColor White } else { Write-Host "  - CHANGELOG.md (would create new file)" -ForegroundColor White }

    Write-Host "`nLocal WordPress Sync Target:" -ForegroundColor Cyan
    if (-not $NoSync -and (Test-Path (Split-Path $WpPluginDir -Parent))) {
        Write-Host "  - $WpPluginDir" -ForegroundColor White
    } else {
        Write-Host "  - (Local WP sync skipped or directory not found)" -ForegroundColor DarkYellow
    }

    Write-Host "`nZIPs that WOULD be created:" -ForegroundColor Cyan
    if (-not $NoZip) {
        if (-not $FlatOnly) { Write-Host "  - $PluginSlug-v$NewVersion.zip" -ForegroundColor White }
        Write-Host "  - $PluginSlug.zip (latest)" -ForegroundColor White
        Write-Host "  - $PluginSlug-flat.zip" -ForegroundColor White
    } else {
        Write-Host "  - (ZIP creation skipped via -NoZip)" -ForegroundColor DarkYellow
    }

    Write-Host "`nCommit message that WOULD be used:" -ForegroundColor Cyan
    if (-not $NoCommit) {
        Write-Host "  - '$ResolvedCommitMsg'" -ForegroundColor White
        Write-Host "Git Tag that WOULD be created:" -ForegroundColor Cyan
        Write-Host "  - v$NewVersion" -ForegroundColor White
    } else {
        Write-Host "  - (Git commit skipped via -NoCommit)" -ForegroundColor DarkYellow
    }

    Write-Host "`nChangelog entry that WOULD be added:" -ForegroundColor Cyan
    Write-Host "  ## v$NewVersion - $TodayDate" -ForegroundColor White
    Write-Host "  - $ChangelogNote" -ForegroundColor White

    Write-Host "==========================================" -ForegroundColor Yellow
    Write-Host " Dry Run Complete! No changes applied." -ForegroundColor Yellow
    Write-Host "==========================================" -ForegroundColor Yellow
    exit 0
}

# 3. Update formula-rate-shipping.php if version changed
$Utf8NoBom = New-Object System.Text.UTF8Encoding $false
if ($NewVersion -ne $CurrentVersion -or $VersionOverride) {
    $PhpContent = $PhpContent -replace '(?i)(\*\s*Version:\s*)[0-9]+(?:\.[0-9]+)+', ('${1}' + $NewVersion)
    $PhpContent = $PhpContent -replace "('version'\s*=>\s*')[0-9]+(?:\.[0-9]+)+('\s*,)", ('${1}' + $NewVersion + '${2}')
    $PhpContent = $PhpContent -replace "(define\(\s*'FORMULA_RATE_SHIPPING_VERSION'\s*,\s*')[0-9]+(?:\.[0-9]+)+('\s*\))", ('${1}' + $NewVersion + '${2}')
    [System.IO.File]::WriteAllText($PhpFile, $PhpContent, $Utf8NoBom)
    Write-Host "[OK] Updated $PhpFile to version $NewVersion" -ForegroundColor Green
}

# 4. Update README.md if version changed
if (Test-Path $ReadmeMdFile) {
    $ReadmeMdContent = Get-Content -Path $ReadmeMdFile -Raw -Encoding UTF8
    $ReadmeMdContent = $ReadmeMdContent -replace '(?i)(# Formula Rate Shipping for WooCommerce \(v)[0-9]+(?:\.[0-9]+)+(\))', ('${1}' + $NewVersion + '${2}')
    $ReadmeMdContent = $ReadmeMdContent -replace '(?i)(\*\*Version\s+)[0-9]+(?:\.[0-9]+)+', ('${1}' + $NewVersion)
    $ReadmeMdContent = $ReadmeMdContent -replace '(?i)(formula-rate-shipping-v)[0-9]+(?:\.[0-9]+)+(\.zip)', ('${1}' + $NewVersion + '${2}')
    $ReadmeMdContent = $ReadmeMdContent -replace '(?i)(badge\/version-)[0-9]+(?:\.[0-9]+)+(-green\.svg)', ('${1}' + $NewVersion + '${2}')
    $ReadmeMdContent = $ReadmeMdContent -replace '(?i)(releases\/download\/v)[0-9]+(?:\.[0-9]+)+(\/)', ('${1}' + $NewVersion + '${2}')
    $ReadmeMdContent = $ReadmeMdContent -replace '(?i)(formula-rate-shipping\.zip\s*\(v)[0-9]+(?:\.[0-9]+)+(\))', ('${1}' + $NewVersion + '${2}')
    [System.IO.File]::WriteAllText($ReadmeMdFile, $ReadmeMdContent, $Utf8NoBom)
    Write-Host "[OK] Updated $ReadmeMdFile to version $NewVersion" -ForegroundColor Green
}

# 5. Update readme.txt if version changed
if (Test-Path $ReadmeTxtFile) {
    $ReadmeContent = Get-Content -Path $ReadmeTxtFile -Raw -Encoding UTF8
    $ReadmeContent = $ReadmeContent -replace '(?i)(\*\*Version\s+)[0-9]+(?:\.[0-9]+)+', ('${1}' + $NewVersion)
    $ReadmeContent = $ReadmeContent -replace '(?i)(Stable tag:\s*)[0-9]+(?:\.[0-9]+)+', ('${1}' + $NewVersion)
    $ReadmeContent = $ReadmeContent -replace '(?i)(formula-rate-shipping-v)[0-9]+(?:\.[0-9]+)+(\.zip)', ('${1}' + $NewVersion + '${2}')
    [System.IO.File]::WriteAllText($ReadmeTxtFile, $ReadmeContent, $Utf8NoBom)
    Write-Host "[OK] Updated $ReadmeTxtFile to version $NewVersion" -ForegroundColor Green
}

# 6. Update CHANGELOG.md
$NewChangelogEntry = "## v${NewVersion} - ${TodayDate}`n- ${ChangelogNote}`n"

if (Test-Path $ChangelogFile) {
    $ExistingChangelog = Get-Content -Path $ChangelogFile -Raw -Encoding UTF8
    if ($ExistingChangelog -notmatch [regex]::Escape("## v${NewVersion}")) {
        $UpdatedChangelog = $NewChangelogEntry + "`n" + $ExistingChangelog.TrimStart()
        [System.IO.File]::WriteAllText($ChangelogFile, $UpdatedChangelog, $Utf8NoBom)
        Write-Host "[OK] Appended release notes to $ChangelogFile" -ForegroundColor Green
    }
} else {
    $Header = "# Changelog`n`n"
    $UpdatedChangelog = $Header + $NewChangelogEntry
    [System.IO.File]::WriteAllText($ChangelogFile, $UpdatedChangelog, $Utf8NoBom)
    Write-Host "[OK] Created $ChangelogFile with initial release notes" -ForegroundColor Green
}

# 7. PHP Syntax Validation (php -l)
Write-Host "`n--- PHP Syntax Validation ---" -ForegroundColor Cyan

$PhpExe = $null
$PhpCandidates = @(
    (Get-Command php -ErrorAction SilentlyContinue).Source,
    "C:\xampp\php\php.exe",
    "C:\xampp\php.exe",
    "C:\xampp8\php\php.exe",
    "C:\xampp74\php\php.exe",
    "C:\xampp83\php\php.exe",
    "C:\xampp82\php\php.exe",
    "C:\xampp81\php\php.exe",
    "C:\xampp80\php\php.exe",
    "C:\Program Files\PHP\php.exe",
    "C:\tools\php\php.exe",
    "C:\laragon\bin\php\php-8.3\php.exe",
    "C:\laragon\bin\php\php-8.2\php.exe",
    "C:\laragon\bin\php\php-8.1\php.exe",
    "C:\laragon\bin\php\php-8.0\php.exe"
)

foreach ($candidate in $PhpCandidates) {
    if ($candidate -and (Test-Path $candidate)) {
        $PhpExe = $candidate
        break
    }
}

if ($PhpExe) {
    Write-Host "[OK] Found PHP at: $PhpExe" -ForegroundColor Green

    $PhpFiles = Get-ChildItem -Path $ScriptDir -Recurse -Filter "*.php" | Select-Object -ExpandProperty FullName
    foreach ($FileToLint in $PhpFiles) {
        & $PhpExe -l $FileToLint
        if ($LASTEXITCODE -ne 0) {
            Write-Error "PHP syntax validation FAILED for $FileToLint"
            exit 1
        }
    }
    Write-Host "[OK] PHP syntax validation passed." -ForegroundColor Green
} else {
    Write-Host "[WARN] PHP not found on PATH or in common XAMPP/Laragon locations." -ForegroundColor Yellow
    Write-Host "[WARN] Skipping PHP syntax validation." -ForegroundColor Yellow
}

# 8. Local WordPress Plugin Deployment / Sync
if (-not $NoSync) {
    Write-Host "`n--- Local WordPress Plugin Sync ---" -ForegroundColor Cyan
    if (-not (Test-Path $WpPluginDir)) {
        try {
            New-Item -ItemType Directory -Path $WpPluginDir -Force | Out-Null
            Write-Host "[OK] Created local WP plugin target folder: $WpPluginDir" -ForegroundColor Green
        } catch {
            Write-Host "[WARN] Could not create ${WpPluginDir}: $_" -ForegroundColor Yellow
        }
    }

    if (Test-Path $WpPluginDir) {
        Copy-Item -Path $PhpFile -Destination $WpPluginDir -Force
        if (Test-Path $ReadmeMdFile) { Copy-Item -Path $ReadmeMdFile -Destination $WpPluginDir -Force }
        if (Test-Path $ReadmeTxtFile) { Copy-Item -Path $ReadmeTxtFile -Destination $WpPluginDir -Force }
        if (Test-Path $ChangelogFile) { Copy-Item -Path $ChangelogFile -Destination $WpPluginDir -Force }

        $PluginDirectories = @("includes", "admin", "assets", "languages")
        foreach ($DirectoryName in $PluginDirectories) {
            $SourceDirectory = Join-Path $ScriptDir $DirectoryName
            if (Test-Path $SourceDirectory) {
                $DestDirectory = Join-Path $WpPluginDir $DirectoryName
                Copy-Item -Path $SourceDirectory -Destination $WpPluginDir -Recurse -Force
            }
        }
        Write-Host "[OK] Synced plugin files to local WordPress install: $WpPluginDir" -ForegroundColor Green
    }
}

# 9. Build ZIP packages
if (-not $NoZip) {
    Write-Host "`n--- ZIP Package Build ---" -ForegroundColor Cyan
    $ZipVersionName = "${PluginSlug}-v${NewVersion}.zip"
    $ZipLatestName = "${PluginSlug}.zip"
    $ZipFlatName = "${PluginSlug}-flat.zip"

    $ZipVersionPath = Join-Path $ScriptDir $ZipVersionName
    $ZipLatestPath = Join-Path $ScriptDir $ZipLatestName
    $ZipFlatPath = Join-Path $ScriptDir $ZipFlatName

    $TempBuildDir = Join-Path $ScriptDir "build_temp"
    $StagingDir = Join-Path $TempBuildDir $PluginSlug

    if (Test-Path $TempBuildDir) {
        Remove-Item -Path $TempBuildDir -Recurse -Force
    }

    New-Item -ItemType Directory -Path $StagingDir -Force | Out-Null

    Copy-Item -Path $PhpFile -Destination $StagingDir -Force
    if (Test-Path $ReadmeMdFile) { Copy-Item -Path $ReadmeMdFile -Destination $StagingDir -Force }
    if (Test-Path $ReadmeTxtFile) { Copy-Item -Path $ReadmeTxtFile -Destination $StagingDir -Force }

    $PluginDirectories = @("includes", "admin", "assets", "languages")
    foreach ($DirectoryName in $PluginDirectories) {
        $SourceDirectory = Join-Path $ScriptDir $DirectoryName
        if (Test-Path $SourceDirectory) {
            Copy-Item -Path $SourceDirectory -Destination $StagingDir -Recurse -Force
        }
    }

    if (-not $FlatOnly) {
        if (Test-Path $ZipVersionPath) { Remove-Item $ZipVersionPath -Force }
        if (Test-Path $ZipLatestPath) { Remove-Item $ZipLatestPath -Force }

        $env:PLUGIN_SLUG = $PluginSlug
        $env:STAGING_DIR = $StagingDir
        $env:ZIP_VERSION = $ZipVersionPath
        $env:ZIP_LATEST = $ZipLatestPath

        $PythonScriptVersioned = @'
import zipfile
import os
import shutil

plugin_slug = os.environ['PLUGIN_SLUG']
staging_dir = os.environ['STAGING_DIR']
zip_version = os.environ['ZIP_VERSION']
zip_latest = os.environ['ZIP_LATEST']

with zipfile.ZipFile(zip_version, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(staging_dir):
        for f in files:
            full_path = os.path.join(root, f)
            rel_path = os.path.relpath(full_path, staging_dir)
            arcname = plugin_slug + '/' + rel_path.replace(os.sep, '/')
            z.write(full_path, arcname=arcname)

shutil.copy2(zip_version, zip_latest)
print('[OK] Created ZIP: ' + zip_version)
print('[OK] Created ZIP: ' + zip_latest)
'@

        python -c $PythonScriptVersioned
        Remove-Item Env:PLUGIN_SLUG, Env:STAGING_DIR, Env:ZIP_VERSION, Env:ZIP_LATEST -ErrorAction SilentlyContinue
        if ($LASTEXITCODE -ne 0) {
            Write-Error "Python ZIP creation failed."
            exit 1
        }
    }

    if (Test-Path $ZipFlatPath) { Remove-Item $ZipFlatPath -Force }

    $env:STAGING_DIR = $StagingDir
    $env:ZIP_FLAT = $ZipFlatPath

    $PythonScriptFlat = @'
import zipfile
import os

staging_dir = os.environ['STAGING_DIR']
zip_flat = os.environ['ZIP_FLAT']

with zipfile.ZipFile(zip_flat, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(staging_dir):
        for f in files:
            full_path = os.path.join(root, f)
            rel_path = os.path.relpath(full_path, staging_dir)
            arcname = rel_path.replace(os.sep, '/')
            z.write(full_path, arcname=arcname)

print('[OK] Created ZIP: ' + zip_flat)
'@

    python -c $PythonScriptFlat
    Remove-Item Env:STAGING_DIR, Env:ZIP_FLAT -ErrorAction SilentlyContinue
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Python flat ZIP creation failed."
        exit 1
    }

    Remove-Item -Path $TempBuildDir -Recurse -Force
}

# 10. Automatic Git Commit & Version Tagging
if (-not $NoCommit) {
    Write-Host "`n--- Git Commit & Tagging ---" -ForegroundColor Cyan

    if (-not (Test-Path ".git")) {
        Write-Host "Initializing Git repository in $ScriptDir..." -ForegroundColor Yellow
        git init
    }

    $gitUser = git config user.name
    if (-not $gitUser) {
        git config user.name "Formula Rate Shipping Developer"
        git config user.email "dev@magnifymarketing.com"
    }

    if ($env:COMMIT_MSG) {
        Remove-Item Env:COMMIT_MSG -ErrorAction SilentlyContinue
    } elseif (Test-Path "commit.txt") {
        Remove-Item "commit.txt" -Force -ErrorAction SilentlyContinue
    }

    git add .
    git commit -m "$ResolvedCommitMsg"
    if ($LASTEXITCODE -eq 0) {
        Write-Host "[OK] Git commit successful: '$ResolvedCommitMsg'" -ForegroundColor Green
        git tag -f -a "v${NewVersion}" -m "Release v${NewVersion}"
        Write-Host "[OK] Created Git tag: v$NewVersion" -ForegroundColor Green
    } else {
        Write-Host "[NOTE] No uncommitted changes found or git commit completed." -ForegroundColor Yellow
    }
}

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host " Build Complete! Version: $NewVersion" -ForegroundColor Green
Write-Host "==========================================" -ForegroundColor Cyan