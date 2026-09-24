<#
.SYNOPSIS
  Packages the Apex Business Consultancy project into a clean production zip ready for direct cPanel File Manager upload.
#>

$ErrorActionPreference = "Stop"
$rootDir = (Get-Item $PSScriptRoot).Parent.FullName
$zipName = "apex-production-deploy.zip"
$outputPath = Join-Path $rootDir $zipName

Write-Host "Creating production deploy zip: $zipName..." -ForegroundColor Cyan

& composer install --no-dev --prefer-dist --optimize-autoloader --working-dir=$rootDir
if ($LASTEXITCODE -ne 0) { throw "Composer dependency installation failed." }

# Files/folders to exclude from zip
$exclude = @(
    ".git",
    ".github",
    ".idea",
    ".vscode",
    "node_modules",
    "tests",
    "*.zip",
    "*.tar.gz",
    ".env*",
    ".gitignore",
    ".gitkeep",
    "apex-production-deploy.zip"
)

$criticalFiles = @(
    ".htaccess",
    "index.html",
    "app.js",
    "app\bootstrap.php",
    "config\config.php",
    "dashboard.php",
    "dashboard.html",
    "login.php",
    "public\api\auth\csrf.php",
    "public\api\auth\login.php",
    "public\api\inquiries.php",
    "public\api\settings.php",
    "public\api\posts.php",
    "composer.lock",
    "vendor\autoload.php"
)

# Temporary staging folder
$stagingDir = Join-Path $env:TEMP ("apex_deploy_" + [System.Guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $stagingDir | Out-Null

try {
    # Copy files to staging
    Get-ChildItem -Path $rootDir -Force -Exclude $exclude | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $stagingDir -Recurse -Force
    }

    # Clean up excluded patterns from staging if any copied
    Get-ChildItem $stagingDir -Force -Filter ".env*" | Remove-Item -Force
    if (Test-Path "$stagingDir\.gitignore") { Remove-Item "$stagingDir\.gitignore" -Force }
    Get-ChildItem $stagingDir -Force -Recurse -Filter ".gitkeep" | Remove-Item -Force
    if (Test-Path "$stagingDir\.git") { Remove-Item "$stagingDir\.git" -Recurse -Force }
    if (Test-Path "$stagingDir\.github") { Remove-Item "$stagingDir\.github" -Recurse -Force }
    if (Test-Path "$stagingDir\.vscode") { Remove-Item "$stagingDir\.vscode" -Recurse -Force }
    if (Test-Path "$stagingDir\.idea") { Remove-Item "$stagingDir\.idea" -Recurse -Force }
    if (Test-Path "$stagingDir\node_modules") { Remove-Item "$stagingDir\node_modules" -Recurse -Force }
    if (Test-Path "$stagingDir\tests") { Remove-Item "$stagingDir\tests" -Recurse -Force }
    if (Test-Path "$stagingDir\storage\logs") { Get-ChildItem "$stagingDir\storage\logs" -Force | Where-Object { $_.Name -ne ".htaccess" } | Remove-Item -Recurse -Force }
    if (Test-Path "$stagingDir\storage\rate_limits") { Get-ChildItem "$stagingDir\storage\rate_limits" -Force | Where-Object { $_.Name -ne ".htaccess" } | Remove-Item -Recurse -Force }

    foreach ($file in $criticalFiles) {
        if (-not (Test-Path (Join-Path $stagingDir $file))) {
            throw "Critical deployment file missing from package staging area: $file"
        }
    }

    # Remove existing zip if exists
    if (Test-Path $outputPath) { Remove-Item $outputPath -Force }

    # Compress staging folder to zip
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory($stagingDir, $outputPath, [System.IO.Compression.CompressionLevel]::Optimal, $false)

    $zip = [System.IO.Compression.ZipFile]::OpenRead($outputPath)
    try {
        $zipEntries = $zip.Entries.FullName -replace '/', '\'
        foreach ($file in $criticalFiles) {
            if ($zipEntries -notcontains $file) {
                throw "Critical deployment file missing from package zip: $file"
            }
        }
        if ($zipEntries | Where-Object { $_ -like ".env*" }) {
            throw "Refusing to create package containing .env files."
        }
        if ($zipEntries | Where-Object { $_ -match "(^|\\)\.git" }) {
            throw "Refusing to create package containing Git control files."
        }
    }
    finally {
        $zip.Dispose()
    }

    $fileSizeMB = [math]::Round(((Get-Item $outputPath).Length / 1MB), 2)
    Write-Host "✓ Successfully packaged production zip: $outputPath ($fileSizeMB MB)" -ForegroundColor Green
    Write-Host "✓ Upload this zip to cPanel File Manager -> Extract into public_html" -ForegroundColor Yellow
}
finally {
    if (Test-Path $stagingDir) {
        Remove-Item $stagingDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}
