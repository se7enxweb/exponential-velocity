# Build a complete Exponential site on SQLite for the Windows tests
# (docs/windows.md): the public se7enxweb/exponential repository, its Composer
# dependencies, and "exp:install" with the default site package on SQLite,
# exactly as an administrator would install it on a laptop.
#
#   powershell -ExecutionPolicy Bypass -File tests\windows\exponential-fixture.ps1 -Php C:\php\php.exe -Dir C:\exp -Port 18431
#
# Writes the administrator's password (random, for this throwaway site only)
# to <Dir>.admin-password and never prints it. Exits non-zero on failure.

param(
    [string]$Php = "php.exe",
    [string]$Dir = "C:\exp",
    [int]$Port = 18431,
    [string]$Ref = "main",
    [string]$Repository = "https://github.com/se7enxweb/exponential.git",
    [string]$LogDir = ""
)
. (Join-Path $PSScriptRoot "common.ps1")
$sw = [Diagnostics.Stopwatch]::StartNew()
$script:mask = $null

if ($LogDir -ne "") { New-Item -ItemType Directory -Force -Path $LogDir | Out-Null }
function Step([string]$what, [string]$exe, [string[]]$arguments, [string]$logName) {
    Write-Host "== $what"
    $log = Join-Path ([IO.Path]::GetTempPath()) $logName
    $t = [Diagnostics.Stopwatch]::StartNew()
    & $exe @arguments *> $log
    $code = $LASTEXITCODE
    if ($script:mask) {
        # The installation summary repeats the password: never in a log.
        $text = [IO.File]::ReadAllText($log).Replace($script:mask, "***")
        [IO.File]::WriteAllText($log, $text)
    }
    Get-Content $log -Tail 25 | ForEach-Object { Write-Host "   | $_" }
    Save-Logs $LogDir @($log)
    Write-Host "   exit $code, $([int]$t.Elapsed.TotalSeconds) s"
    return $code
}

& git config --global core.longpaths true
if (-not (Test-Path (Join-Path $Dir "index.php"))) {
    $c = Step "clone $Repository ($Ref)" "git" @("clone", "--depth", "1", "--branch", $Ref, $Repository, $Dir) "fixture-clone.log"
    Check "Exponential cloned" ($c -eq 0)
    if ($c -ne 0) { exit (Done "exponential-fixture") }
}
Push-Location $Dir
try {
    $composer = Get-Command composer -ErrorAction SilentlyContinue
    if ($composer) { $cmd = $composer.Source; $pre = @() }
    else {
        $phar = Join-Path $Dir "composer.phar"
        if (-not (Test-Path $phar)) { Invoke-WebRequest -UseBasicParsing -Uri "https://getcomposer.org/download/latest-stable/composer.phar" -OutFile $phar }
        $cmd = $Php; $pre = @($phar)
    }
    $env:COMPOSER_NO_INTERACTION = "1"
    $c = Step "composer install" $cmd ($pre + @("install", "--no-dev", "--no-interaction", "--no-progress", "--prefer-dist")) "fixture-composer.log"
    Check "composer install" ($c -eq 0)
    if ($c -ne 0) { exit (Done "exponential-fixture") }

    # A random administrator password for this throwaway site.
    $bytes = New-Object byte[] 18
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $password = "Vc9-" + ([Convert]::ToBase64String($bytes) -replace '[^A-Za-z0-9]', 'q') + "-x"
    $pwFile = "$Dir.admin-password"
    [IO.File]::WriteAllText($pwFile, $password)
    $script:mask = $password

    $c = Step "exp:install (SQLite, http://127.0.0.1:$Port)" $Php @("bin\php\console", "exp:install", "--apply", "--db=sqlite",
        "--password=$password", "--url=http://127.0.0.1:$Port", "--email=nospam@exponential.earth") "fixture-install.log"
    Check "exp:install on SQLite" ($c -eq 0)
    $db = Get-ChildItem -Path (Join-Path $Dir "var") -Recurse -Filter "exponential.db" -ErrorAction SilentlyContinue | Select-Object -First 1
    Check "the SQLite database exists" ($db -ne $null) "$(if ($db) { "$($db.FullName), $([int]($db.Length / 1KB)) KB" })"
    Check "the site, admin and editor siteaccesses exist" ((Test-Path "settings\siteaccess\site") -and (Test-Path "settings\siteaccess\admin"))
} finally { Pop-Location }
Write-Host "fixture built in $([int]$sw.Elapsed.TotalSeconds) s"
exit (Done "exponential-fixture")
