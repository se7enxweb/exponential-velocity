# Install an official PHP build from windows.php.net for the Windows tests
# (docs/windows.md), for an architecture setup-php does not cover: x86
# (32-bit), and ARM64. windows.php.net publishes x64 and x86 builds; when it
# lists no native ARM64 build, -Arch arm64 installs the x64 build, which
# Windows 11 on ARM runs under its x64 emulation (Prism) -- the job says which
# it got. Writes a php.ini with the extensions Velocity and Exponential need.
#
#   powershell -ExecutionPolicy Bypass -File tests\windows\install-php.ps1 -Version 8.3 -Arch x86 -Dest C:\php32
#
# Prints the path of php.exe on its last line.

param(
    [string]$Version = "8.3",
    [ValidateSet("x64", "x86", "arm64")][string]$Arch = "x64",
    [string]$Dest = "C:\php-ci"
)
$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$rel = Invoke-RestMethod -Uri "https://windows.php.net/downloads/releases/releases.json"
$line = $rel.$Version
if (-not $line) { throw "windows.php.net lists no PHP $Version" }
$want = @("nts-vs*-$Arch")
if ($Arch -eq "arm64") { $want += "nts-vs*-x64" }
$build = $null
foreach ($w in $want) {
    $build = $line.PSObject.Properties | Where-Object { $_.Name -like $w } | Select-Object -First 1
    if ($build) { break }
}
if (-not $build) { throw "no NTS build for $Arch listed for PHP $Version" }
$zip = $build.Value.zip.path
$got = ($build.Name -split '-')[-1]
Write-Host "PHP $($line.version) $($build.Name) for $Arch$(if ($got -ne $Arch) { " (no native $Arch build: $got under emulation)" }): $zip"
Invoke-WebRequest -Uri "https://windows.php.net/downloads/releases/$zip" -OutFile "$env:TEMP\php-ci.zip"
Expand-Archive "$env:TEMP\php-ci.zip" -DestinationPath $Dest -Force
Copy-Item "$Dest\php.ini-development" "$Dest\php.ini" -Force
$exts = @("sockets", "mbstring", "openssl", "curl", "fileinfo", "sqlite3", "pdo_sqlite", "intl", "xsl", "gd", "zip", "exif")
# PHP for Windows ships no CA bundle: without one every HTTPS request PHP
# makes (Exponential fetching its site packages, ACME) fails verification.
Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" -OutFile "$Dest\extras\cacert.pem"
$ini = "`ncurl.cainfo=`"$Dest\extras\cacert.pem`"`nopenssl.cafile=`"$Dest\extras\cacert.pem`"`nextension_dir=$Dest\ext`n" + (($exts | Where-Object { Test-Path "$Dest\ext\php_$_.dll" } | ForEach-Object { "extension=$_" }) -join "`n") + "`nmemory_limit=512M`n"
Add-Content "$Dest\php.ini" $ini
& "$Dest\php.exe" -v
& "$Dest\php.exe" -m | Out-String | Write-Host
if ($env:GITHUB_PATH) { Add-Content $env:GITHUB_PATH $Dest }
Write-Output "$Dest\php.exe"
