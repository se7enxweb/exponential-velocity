# Windows smoke test for the portable worker pool (docs/windows.md).
#
# Not yet run on Windows: written for the first run on a Windows 11 desktop and
# a Windows Server machine. Starts a server from this source tree on a free port
# with two portable workers, then checks:
#   - pages are answered, by worker processes that are not the server;
#   - workers are reused between requests;
#   - a worker ended with Stop-Process is replaced and requests go on;
#   - a PHP fatal error answers 500 and the pool goes on;
#   - --stop (request file) ends the server and every worker, gracefully;
#   - the port is free afterwards (no worker kept the listener).
#
#   powershell -ExecutionPolicy Bypass -File tests\windows\smoke.ps1 -Php C:\php\php.exe
#
# Prints PASS/FAIL per check, exits 1 if any failed.

param(
    [string]$Php = "php.exe",
    [int]$Workers = 2,
    [int]$StartSeconds = 60,
    [string]$LogDir = ""
)

# Not "Stop": a native program writing to standard error must not end the test.
$ErrorActionPreference = "Continue"
$repo = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$script:pass = 0
$script:fail = 0

function Check([string]$what, [bool]$ok, [string]$detail = "") {
    if ($ok) { $script:pass++; Write-Host "  PASS  $what" }
    else { $script:fail++; Write-Host "  FAIL  $what $detail" }
}

function FreePort {
    $l = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    $l.Start(); $p = $l.LocalEndpoint.Port; $l.Stop(); return $p
}

function Get-Page([int]$port, [string]$path) {
    try {
        $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 20 -Uri "http://127.0.0.1:$port$path"
        return @{ Status = [int]$r.StatusCode; Body = [string]$r.Content }
    } catch [System.Net.WebException] {
        $resp = $_.Exception.Response
        if ($resp) { return @{ Status = [int]$resp.StatusCode; Body = "" } }
        return @{ Status = 0; Body = $_.Exception.Message }
    } catch {
        # PowerShell 7 throws HttpResponseException
        $code = 0
        if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode }
        return @{ Status = $code; Body = $_.Exception.Message }
    }
}

$base = Join-Path ([System.IO.Path]::GetTempPath()) ("vc-smoke-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
$web = Join-Path $base "web"
New-Item -ItemType Directory -Force -Path $web | Out-Null
Set-Content -Path (Join-Path $web "pid.php") -Value '<?php echo getmypid();' -Encoding ASCII
Set-Content -Path (Join-Path $web "fatal.php") -Value '<?php undefined_function_smoke();' -Encoding ASCII
Set-Content -Path (Join-Path $base "conf.json") -Value '{"Q":{"web":{"cache":{"enabled":false}},"webserver":{"poolMode":"portable"}}}' -Encoding ASCII
$pidFile = Join-Path $base "vc.pid"
$log = Join-Path $base "server.log"
$port = FreePort

Write-Host "PHP:  $(& $Php -v | Select-Object -First 1)"
Write-Host "Tree: $repo"
Write-Host "Port: $port   Temp: $base"

function Quote([string]$a) { if ($a -match '[\s"]') { return '"' + ($a -replace '"', '\"') + '"' } return $a }
$srvArgs = @("$repo\sbin\qbixserver.php", "--root=$web", "--port=$port", "--workers=$Workers",
          "--config=$base\conf.json", "--pid=$pidFile", "--pool-mode=portable")
$server = Start-Process -FilePath $Php -ArgumentList ($srvArgs | ForEach-Object { Quote $_ }) -PassThru -NoNewWindow `
    -RedirectStandardOutput $log -RedirectStandardError "$log.err"

$up = $false
for ($i = 0; $i -lt $StartSeconds * 2; $i++) {
    try { $c = [System.Net.Sockets.TcpClient]::new("127.0.0.1", $port); $c.Close(); $up = $true; break }
    catch { Start-Sleep -Milliseconds 500 }
    if ($server.HasExited) { break }
}
Check "server listens on port $port" $up
if (-not $up) {
    Get-Content $log, "$log.err" -ErrorAction SilentlyContinue | Select-Object -Last 40
    if ($LogDir -ne "") { New-Item -ItemType Directory -Force -Path $LogDir | Out-Null; Copy-Item $log, "$log.err" -Destination $LogDir -ErrorAction SilentlyContinue }
    if (-not $server.HasExited) { Stop-Process -Id $server.Id -Force }
    exit 1
}

$pids = @{}
for ($i = 0; $i -lt 6; $i++) {
    $r = Get-Page $port "/pid.php"
    if ($r.Status -eq 200) { $pids[[int]$r.Body] = $true } else { $pids[-1] = $true }
}
Check "six requests answered" (-not $pids.ContainsKey(-1))
Check "not by the server process" (-not $pids.ContainsKey($server.Id))
Check "workers reused (at most $Workers pids)" ($pids.Count -le $Workers) "($($pids.Keys -join ', '))"

$victim = [int]($pids.Keys | Where-Object { $_ -gt 0 } | Select-Object -First 1)
$proc = Get-CimInstance Win32_Process -Filter "ProcessId = $victim" -ErrorAction SilentlyContinue
Check "a worker is a php process running qbixserver.php" ($proc -and $proc.CommandLine -like "*qbixserver.php*")
Check "...whose parent is the server" ($proc -and $proc.ParentProcessId -eq $server.Id)

Stop-Process -Id $victim -Force
Start-Sleep -Seconds 2
$ok = $true; $again = @()
for ($i = 0; $i -lt 6; $i++) {
    $r = Get-Page $port "/pid.php"
    if ($r.Status -ne 200) { $ok = $false } else { $again += [int]$r.Body }
}
Check "requests answered after a worker was killed" $ok
Check "...never by the killed one" (-not ($again -contains $victim))
$children = @(Get-CimInstance Win32_Process -Filter "ParentProcessId = $($server.Id)")
Check "...pool back to $Workers workers" ($children.Count -ge $Workers) "($($children.Count))"

$r = Get-Page $port "/fatal.php"
Check "a fatal error answers 500" ($r.Status -eq 500) "(got $($r.Status))"
$r = Get-Page $port "/pid.php"
Check "...and the pool goes on" ($r.Status -eq 200)

$workerPids = @($children | ForEach-Object { $_.ProcessId })
$stopProc = Start-Process -FilePath $Php -ArgumentList (@("$repo\sbin\qbixserver.php", "--stop", "--pid=$pidFile") | ForEach-Object { Quote $_ }) `
    -PassThru -NoNewWindow -Wait -RedirectStandardOutput "$base\stop.out" -RedirectStandardError "$base\stop.err"
Write-Host "  --stop exit $($stopProc.ExitCode): $((Get-Content "$base\stop.out", "$base\stop.err" -ErrorAction SilentlyContinue) -join ' ')"
$gone = $server.WaitForExit(20000)
Check "--stop ends the server" $gone
Start-Sleep -Seconds 1
$left = @($workerPids | Where-Object { Get-Process -Id $_ -ErrorAction SilentlyContinue })
Check "...and every worker" ($left.Count -eq 0) "(left: $($left -join ', '))"
$text = (Get-Content $log, "$log.err" -Raw -ErrorAction SilentlyContinue) -join "`n"
Check "...gracefully" ($text -like "*Graceful shutdown (stop requested)*")

$free = $true
try { $l = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $port); $l.Start(); $l.Stop() }
catch { $free = $false }
Check "the port is free afterwards" $free

if (-not $server.HasExited) { Stop-Process -Id $server.Id -Force }
foreach ($p in $left) { Stop-Process -Id $p -Force -ErrorAction SilentlyContinue }

Write-Host ""
if ($script:fail -eq 0) { Write-Host "PASS  $($script:pass) passed, 0 failed" } else { Write-Host "FAIL  $($script:pass) passed, $($script:fail) failed" }
Write-Host "Log: $log"
if ($LogDir -ne "") {
    New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
    Copy-Item $log, "$log.err" -Destination $LogDir -ErrorAction SilentlyContinue
    Get-Content $log, "$log.err" -ErrorAction SilentlyContinue | Select-Object -Last 200
}
exit ([int]($script:fail -gt 0))
