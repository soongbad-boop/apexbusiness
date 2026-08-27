<#
.SYNOPSIS
  Packages the Apex Business Consultancy project into a clean production zip ready for direct cPanel File Manager upload.
#>

$ErrorActionPreference = "Stop"
$rootDir = (Get-Item $PSScriptRoot).Parent.FullName
$zipName = "apex-production-deploy.zip"
$outputPath = Join-Path $rootDir $zipName

Write-Host "Creating production deploy zip: $zipName..." -ForegroundColor Cyan

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
    ".env",
    "apex-production-deploy.zip"
)

# Temporary staging folder
$stagingDir = Join-Path $env:TEMP ("apex_deploy_" + [System.Guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $stagingDir | Out-Null

try {
    # Copy files to staging
    Get-ChildItem -Path $rootDir -Exclude $exclude | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $stagingDir -Recurse -Force
    }

    # Clean up excluded patterns from staging if any copied
    if (Test-Path "$stagingDir\.env") { Remove-Item "$stagingDir\.env" -Force }
    if (Test-Path "$stagingDir\.git") { Remove-Item "$stagingDir\.git" -Recurse -Force }

    # Remove existing zip if exists
    if (Test-Path $outputPath) { Remove-Item $outputPath -Force }

    # Compress staging folder to zip
    Compress-Archive -Path "$stagingDir\*" -DestinationPath $outputPath -CompressionLevel Optimal

    $fileSizeMB = [math]::Round(((Get-Item $outputPath).Length / 1MB), 2)
    Write-Host "✓ Successfully packaged production zip: $outputPath ($fileSizeMB MB)" -ForegroundColor Green
    Write-Host "✓ Upload this zip to cPanel File Manager -> Extract into public_html" -ForegroundColor Yellow
}
finally {
    if (Test-Path $stagingDir) {
        Remove-Item $stagingDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}
