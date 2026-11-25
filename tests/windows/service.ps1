# Velocity as a Windows service, through packaging\windows\vcc.ps1
# (docs/windows.md). Needs an elevated PowerShell (the GitHub runners are).
#
# Part 1, a small site (service vc-ci):
#   doctor; install with the default virtual account NT SERVICE\vc-ci; the
#   Debian-style tree in %ProgramData%\vc; start; the site answers; the PHP
#   processes run as NT SERVICE\vc-ci, not SYSTEM; that account cannot write
#   into C:\Windows but can write the one directory granted to it; the event
#   log has the start; a certificate from the Windows certificate store
#   (LocalMachine\My) is imported and served over HTTPS; the service comes
#   back by itself after its server is killed (SCM recovery); site-disable /
#   site-enable; stop is graceful; uninstall removes the service, the event
#   source and the account's ACL entries.
# Part 2, with -ExpDir (service vc-exp): the Exponential fixture served by
# the service under its virtual account: public site, admin login page,
# administrator login to the dashboard; then stop and uninstall.
#
#   powershell -ExecutionPolicy Bypass -File tests\windows\service.ps1 -Php C:\php\php.exe [-ExpDir C:\exp -ExpPort 18431]

param(
    [string]$Php = "php.exe",
    [string]$ExpDir = "",
    [int]$ExpPort = 18431,
    [string]$LogDir = ""
)
. (Join-Path $PSScriptRoot "common.ps1")
$vcc = Join-Path $script:repo "packaging\windows\vcc.ps1"
$Php = (Get-Command $Php).Source
$pd = $env:ProgramData
$tree = Join-Path $pd "vc"

function Vcc([string[]]$a) {
    $out = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $vcc @a 2>&1 | Out-String
    $code = $LASTEXITCODE
    $out.Trim() -split "`r?`n" | ForEach-Object { Write-Host "    | $_" }
    return @{ Code = $code; Out = $out }
}

function Svc-Server([string]$name) {
    $w = Get-CimInstance Win32_Service -Filter "Name='$name'"
    if (-not $w -or $w.ProcessId -eq 0) { return $null }
    return @(Get-CimInstance Win32_Process -Filter "ParentProcessId = $($w.ProcessId)" | Where-Object { $_.Name -like "php*" }) | Select-Object -First 1
}

function Owner($proc) {
    if (-not $proc) { return "" }
    $o = Invoke-CimMethod -InputObject $proc -MethodName GetOwner
    return "$($o.Domain)\$($o.User)"
}

# ── Part 1 ───────────────────────────────────────────────────────────────
$name = "vc-ci"
$root = Join-Path $env:SystemDrive "vc-ci-site"
New-Item -ItemType Directory -Force -Path $root | Out-Null
Set-Content -Path (Join-Path $root "pid.php") -Value '<?php echo getmypid();' -Encoding ASCII
Set-Content -Path (Join-Path $root "probe.php") -Encoding ASCII -Value @'
<?php
$win = getenv('SystemRoot') . '\vc-acl-probe.txt';
$own = __DIR__ . '\var\probe.txt';
echo 'windows=', @file_put_contents($win, 'x') === false ? 'denied' : 'WROTE', ';';
echo 'granted=', @file_put_contents($own, 'x') === false ? 'denied' : 'wrote', ';';
echo 'user=', getenv('USERNAME'), ';';
'@
$port = FreePort; $sport = FreePort
while ($sport -eq $port) { $sport = FreePort }

$r = Vcc @("doctor", "-Php", $Php)
Check "vcc doctor" ($r.Code -eq 0)
$r = Vcc @("install", "-DryRun", "-Name", $name, "-Site", "ci", "-Php", $Php, "-Root", $root, "-Port", "$port")
Check "vcc install -DryRun changes nothing" ($r.Code -eq 0 -and -not (Get-Service $name -ErrorAction SilentlyContinue))
$r = Vcc @("install", "-Name", $name, "-Site", "ci", "-Php", $Php, "-Root", $root, "-Port", "$port", "-Workers", "2", "-WritableDirs", "var", "-StartupType", "Manual")
Check "vcc install" ($r.Code -eq 0)
$w = Get-CimInstance Win32_Service -Filter "Name='$name'"
Check "the service exists, run by NT SERVICE\$name" ($w -and $w.StartName -eq "NT SERVICE\$name") "($($w.StartName))"
foreach ($f in @("vc.conf", "ports.conf", "envvars", "sites-available\ci.conf", "sites-enabled\ci.conf", "bin\VcService.exe")) {
    Check "tree: %ProgramData%\vc\$f" (Test-Path (Join-Path $tree $f))
}
foreach ($d in @("logs", "state", "run", "ssl", "conf-available", "mods-enabled")) { Check "tree: %ProgramData%\vc\$d\" (Test-Path (Join-Path $tree $d)) }
$acl = (Get-Acl $tree).Access | Where-Object { $_.IdentityReference -match 'Users$' -and -not $_.IsInherited }
Check "the tree is not writable by Users (inheritance from %ProgramData% cut)" (-not ((Get-Acl $tree).Access | Where-Object { $_.IdentityReference -match '\\Users$' -and ($_.FileSystemRights -match 'Write|Modify|FullControl|CreateFiles') }))

$r = Vcc @("start", "-Name", $name)
Check "vcc start" ($r.Code -eq 0)
$up = Wait-Port $port 90
Check "the site answers on $port" $up
$srv = Svc-Server $name
Check "the service host started the PHP server" ($srv -ne $null)
Check "...which runs as NT SERVICE\$name" ((Owner $srv) -eq "NT SERVICE\$name") "($(Owner $srv))"
$s = Http-Status "http://127.0.0.1:$port/pid.php"
Check "a page answers 200" ($s -eq 200) "(got $s)"
$workers = @(); if ($srv) { $workers = Child-Php $srv.ProcessId }
Check "...from portable workers" ($workers.Count -ge 1) "($($workers.Count))"
if ($workers.Count -ge 1) { Check "...also run as NT SERVICE\$name" ((Owner $workers[0]) -eq "NT SERVICE\$name") "($(Owner $workers[0]))" }
$probe = Http-Body "http://127.0.0.1:$port/probe.php"
Write-Host "    probe: $probe"
Check "the service account cannot write into C:\Windows" ($probe -match 'windows=denied')
Check "...and can write the directory granted to it" ($probe -match 'granted=wrote')
Remove-Item (Join-Path $env:SystemRoot "vc-acl-probe.txt") -Force -ErrorAction SilentlyContinue
$ev = @(Get-WinEvent -FilterHashtable @{ LogName = "Application"; ProviderName = $name } -MaxEvents 10 -ErrorAction SilentlyContinue)
Check "the event log has the service's start" (($ev | Where-Object { $_.Message -like "started*" }).Count -ge 1) "($($ev.Count) events)"
$r = Vcc @("status", "-Name", $name, "-Json")
Check "vcc status -Json reports it running" ($r.Out -match '"status":\s*"Running"')

# The Windows certificate store -> HTTPS.
$cert = New-SelfSignedCertificate -DnsName "localhost" -CertStoreLocation "Cert:\LocalMachine\My" -KeyExportPolicy Exportable `
    -KeyAlgorithm RSA -KeyLength 2048 -NotAfter (Get-Date).AddDays(7)
$r = Vcc @("cert-import", "-Name", $name, "-Site", "ci", "-Thumbprint", $cert.Thumbprint, "-HttpsPort", "$sport", "-Php", $Php)
Check "vcc cert-import from Cert:\LocalMachine\My" ($r.Code -eq 0)
$r = Vcc @("restart", "-Name", $name)
Check "vcc restart" ($r.Code -eq 0)
$h = $null
if (Wait-Port $sport 90) { for ($i = 0; $i -lt 20; $i++) { $h = Https-Get $sport "/pid.php"; if ($h.Status -eq 200) { break }; Start-Sleep -Milliseconds 500 } }
Check "HTTPS answers 200 under the service account" ($h -and $h.Status -eq 200) "($(if ($h) { "$($h.Line) $($h.Error)" }))"
Check "...with the certificate from the store" ($h -and $h.Thumbprint -eq $cert.Thumbprint) "($(if ($h) { $h.Thumbprint }))"
Remove-Item "Cert:\LocalMachine\My\$($cert.Thumbprint)" -ErrorAction SilentlyContinue

# Recovery: kill the server; the SCM restarts the service.
$srv = Svc-Server $name
$oldHost = (Get-CimInstance Win32_Service -Filter "Name='$name'").ProcessId
if ($srv) { Stop-Process -Id $srv.ProcessId -Force }
$back = $false
for ($i = 0; $i -lt 60; $i++) {
    Start-Sleep -Seconds 1
    $w = Get-CimInstance Win32_Service -Filter "Name='$name'"
    if ($w.State -eq "Running" -and $w.ProcessId -ne $oldHost -and (Http-Status "http://127.0.0.1:$port/pid.php") -eq 200) { $back = $true; break }
}
Check "after its server was killed the service came back by itself" $back
$ev = @(Get-WinEvent -FilterHashtable @{ LogName = "Application"; ProviderName = $name } -MaxEvents 20 -ErrorAction SilentlyContinue)
Check "...and the event log says why" (($ev | Where-Object { $_.Message -like "*exited on its own*" }).Count -ge 1)

# Sites.
$r = Vcc @("site-disable", "ci", "-Php", $Php)
Check "vcc site-disable" ($r.Code -eq 0 -and -not (Test-Path (Join-Path $tree "sites-enabled\ci.conf")))
$r = Vcc @("site-enable", "ci", "-Php", $Php)
Check "vcc site-enable" ($r.Code -eq 0 -and (Test-Path (Join-Path $tree "sites-enabled\ci.conf")))
$r = Vcc @("site-list")
Check "vcc site-list shows it enabled" ($r.Out -match '\*\s+ci')

# Stop and uninstall.
$srv = Svc-Server $name
$kids = @(); if ($srv) { $kids = @($srv.ProcessId) + @((Child-Php $srv.ProcessId) | ForEach-Object { $_.ProcessId }) }
$r = Vcc @("stop", "-Name", $name)
Check "vcc stop" ($r.Code -eq 0)
Start-Sleep -Seconds 1
$left = @($kids | Where-Object { Get-Process -Id $_ -ErrorAction SilentlyContinue })
Check "...the server and every worker are gone" ($left.Count -eq 0) "(left: $($left -join ','))"
Check "...the ports are free" ((Port-Free $port) -and (Port-Free $sport))
$slog = Join-Path $tree "logs\$name-server.log"
Check "...gracefully (the server's own log says so)" ((Get-Content $slog -Raw -ErrorAction SilentlyContinue) -like "*Graceful shutdown*")
$r = Vcc @("logs", "-Name", $name, "-Tail", "15")
Save-Logs $LogDir @($slog)
$sid = ((& sc.exe showsid $name) -join "`n") -replace '(?s).*SERVICE SID:\s*(S-[0-9-]+).*', '$1'
$r = Vcc @("uninstall", "-Name", $name)
Check "vcc uninstall" ($r.Code -eq 0)
Check "...the service is gone" (-not (Get-Service $name -ErrorAction SilentlyContinue))
Check "...the event source is gone" (-not [Diagnostics.EventLog]::SourceExists($name))
$still = (Get-Acl $root).Access | Where-Object { $_.IdentityReference -match [regex]::Escape($name) -or $_.IdentityReference -eq $sid }
Check "...and the account's ACL entries" (-not $still)

# ── Part 2: the Exponential fixture as a service ─────────────────────────
if ($ExpDir -ne "") {
    $name = "vc-exp"
    $r = Vcc @("install", "-Name", $name, "-Site", "exp", "-Php", $Php, "-Root", $ExpDir, "-Port", "$ExpPort", "-Workers", "2",
        "-Preset", "exponential", "-WritableDirs", "var", "-StartupType", "Manual")
    Check "[exp] vcc install (preset exponential, var writable)" ($r.Code -eq 0)
    $r = Vcc @("start", "-Name", $name)
    Check "[exp] vcc start" ($r.Code -eq 0)
    $up = Wait-Port $ExpPort 240
    Check "[exp] the site answers on $ExpPort" $up
    $srv = Svc-Server $name
    Check "[exp] ...served as NT SERVICE\$name" ((Owner $srv) -eq "NT SERVICE\$name") "($(Owner $srv))"
    $u = "http://127.0.0.1:$ExpPort"
    foreach ($p in @("/", "/site/", "/admin/user/login")) {
        $s = Http-Status "$u$p"
        Check "[exp] $p answers 200" ($s -eq 200) "(got $s)"
    }
    $pwFile = "$ExpDir.admin-password"
    if (Test-Path $pwFile) {
        $jar = Join-Path ([IO.Path]::GetTempPath()) "vc-exp-svc-cookies.txt"
        $null = Http-Status "$u/admin/user/login" @("-c", $jar, "-b", $jar)
        $form = Join-Path ([IO.Path]::GetTempPath()) "vc-exp-svc.form"
        [IO.File]::WriteAllText($form, "Login=admin&Password=" + [Uri]::EscapeDataString([IO.File]::ReadAllText($pwFile).Trim()) + "&LoginButton=Login&RedirectURI=")
        $code = Http-Status "$u/admin/user/login" @("-c", $jar, "-b", $jar, "--data-binary", "@$form")
        Remove-Item $form -Force
        $dash = Http-Body "$u/admin/content/dashboard" @("-c", $jar, "-b", $jar)
        Check "[exp] the administrator logs in to the dashboard" (($code -eq 302 -or $code -eq 303) -and $dash -match '(?i)logout') "(POST $code)"
    }
    $r = Vcc @("stop", "-Name", $name)
    Check "[exp] vcc stop" ($r.Code -eq 0)
    Save-Logs $LogDir @((Join-Path $tree "logs\$name-server.log"))
    $r = Vcc @("uninstall", "-Name", $name)
    Check "[exp] vcc uninstall" ($r.Code -eq 0)
}

Remove-Item $root -Recurse -Force -ErrorAction SilentlyContinue
exit (Done "service")
