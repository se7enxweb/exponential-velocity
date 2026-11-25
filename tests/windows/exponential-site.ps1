# A complete Exponential site under Velocity on Windows (docs/windows.md):
# the SQLite fixture from exponential-fixture.ps1, served by qbixserver.php
# with --preset=exponential and persistent portable workers.
#
# Checks: the public site, the editor and the admin login page answer 200,
# a static file is served, an unknown path is 404; the administrator logs in
# (POST, session cookie, redirect) and reaches the dashboard; then a short
# load -- three rounds of concurrent requests -- where every answer must be
# good, no worker may die, worker memory must level off and the server must
# not leak handles; and finally a graceful stop that frees the port.
#
#   powershell -ExecutionPolicy Bypass -File tests\windows\exponential-site.ps1 -Php C:\php\php.exe -Dir C:\exp -Port 18431

param(
    [string]$Php = "php.exe",
    [string]$Dir = "C:\exp",
    [int]$Port = 18431,
    [int]$Workers = 2,
    [int]$Rounds = 3,
    [int]$Requests = 300,
    [int]$Concurrency = 8,
    [int]$StartSeconds = 240,
    [string]$LogDir = ""
)
. (Join-Path $PSScriptRoot "common.ps1")

$base = Join-Path ([System.IO.Path]::GetTempPath()) ("vc-exp-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
New-Item -ItemType Directory -Force -Path $base | Out-Null
$conf = Join-Path $base "conf.json"
[IO.File]::WriteAllText($conf, '{"Q":{"web":{"cache":{"enabled":false}},"webserver":{"poolMode":"portable","maxRequests":100000}}}')
$pidFile = Join-Path $base "vc.pid"; $log = Join-Path $base "server.log"
$url = "http://127.0.0.1:$Port"
Write-Host "PHP:  $(& $Php -v | Select-Object -First 1)"
Write-Host "Site: $Dir   Port: $Port   Temp: $base"

$sw = [Diagnostics.Stopwatch]::StartNew()
$server = Start-Velocity $Php @("--root=$Dir", "--preset=exponential", "--port=$Port", "--workers=$Workers",
    "--config=$conf", "--pid=$pidFile", "--pool-mode=portable") $log
$up = Wait-Port $Port $StartSeconds $server
Check "server listens on $Port" $up "($([int]$sw.Elapsed.TotalSeconds) s)"
if (-not $up) {
    Get-Content $log, "$log.err" -ErrorAction SilentlyContinue | Select-Object -Last 60
    Save-Logs $LogDir @($log, "$log.err")
    if (-not $server.HasExited) { Stop-Process -Id $server.Id -Force }
    exit (Done "exponential-site")
}

# ── Pages ────────────────────────────────────────────────────────────────
$t = [Diagnostics.Stopwatch]::StartNew()
$first = Http-Status "$url/"
Check "/ answers 200 (first request, $([int]$t.Elapsed.TotalMilliseconds) ms)" ($first -eq 200) "(got $first)"
foreach ($p in @("/site/", "/editor/", "/admin/user/login")) {
    $s = Http-Status "$url$p"
    Check "$p answers 200" ($s -eq 200) "(got $s)"
}
$body = Http-Body "$url/site/"
Check "/site/ is an Exponential page" ($body -match '<html' -and $body -match '</html>') "($($body.Length) bytes)"
$login = Http-Body "$url/admin/user/login"
Check "the admin login page has the login form" ($login -match 'name="Login"' -and $login -match 'name="Password"')
$css = Http-Status "$url/design/standard/stylesheets/core.css"
Check "a static file is served" ($css -eq 200) "(got $css)"
$nf = Http-Status "$url/no-such-page-$([guid]::NewGuid().ToString('N').Substring(0,6))"
Check "an unknown path answers 404" ($nf -eq 404) "(got $nf)"

# ── Admin login ──────────────────────────────────────────────────────────
$jar = Join-Path $base "cookies.txt"
$pwFile = "$Dir.admin-password"
$loggedIn = $false
if (Test-Path $pwFile) {
    $pw = [IO.File]::ReadAllText($pwFile).Trim()
    $null = Http-Status "$url/admin/user/login" @("-c", $jar, "-b", $jar)
    $form = Join-Path $base "login.form"
    # The password goes to curl in a file, not on its command line.
    [IO.File]::WriteAllText($form, "Login=admin&Password=" + [Uri]::EscapeDataString($pw) + "&LoginButton=Login&RedirectURI=")
    $hdr = Join-Path $base "login.headers"
    $code = Http-Status "$url/admin/user/login" @("-c", $jar, "-b", $jar, "--data-binary", "@$form", "-D", $hdr)
    Remove-Item $form -Force
    $location = ""
    if (Test-Path $hdr) { $location = (Select-String -Path $hdr -Pattern '^Location:\s*(.+)$' | Select-Object -First 1 | ForEach-Object { $_.Matches[0].Groups[1].Value.Trim() }) }
    Check "admin login: POST answers a redirect" ($code -eq 302 -or $code -eq 303) "(got $code, Location $location)"
    $dash = Http-Body "$url/admin/content/dashboard" @("-c", $jar, "-b", $jar)
    $loggedIn = $dash -match '(?i)logout'
    Check "...to the dashboard, logged in" $loggedIn "($($dash.Length) bytes)"
} else {
    Check "the administrator's password file exists ($pwFile)" $false
}

# ── Load ─────────────────────────────────────────────────────────────────
function Pool-Sample {
    $kids = Child-Php $server.Id
    $mem = 0; $pids = @()
    foreach ($k in $kids) {
        $p = Get-Process -Id $k.ProcessId -ErrorAction SilentlyContinue
        if ($p) { $mem += $p.PrivateMemorySize64; $pids += $k.ProcessId }
    }
    $sp = Get-Process -Id $server.Id -ErrorAction SilentlyContinue
    return @{ Pids = ($pids | Sort-Object); Mem = $mem; Handles = $sp.HandleCount; ServerMem = $sp.PrivateMemorySize64 }
}
$urls = @("$url/", "$url/site/", "$url/admin/user/login", "$url/design/standard/stylesheets/core.css")
$samples = @()
$before = Pool-Sample
Write-Host ("  pool before load: workers {0}, private {1:N1} MB, server handles {2}" -f $before.Pids.Count, ($before.Mem / 1MB), $before.Handles)
for ($r = 1; $r -le $Rounds; $r++) {
    $out = & $Php "$script:repo\tests\windows\load.php" "--requests=$Requests" "--concurrency=$Concurrency" @urls 2>&1 | Out-String
    $code = $LASTEXITCODE
    $s = Pool-Sample
    $samples += $s
    Write-Host ("  round {0}: {1}" -f $r, $out.Trim())
    Write-Host ("           workers {0} ({1}), private {2:N1} MB, server handles {3}, server private {4:N1} MB" -f $s.Pids.Count, ($s.Pids -join ','), ($s.Mem / 1MB), $s.Handles, ($s.ServerMem / 1MB))
    Check "load round $r`: $Requests requests at concurrency $Concurrency, every answer good" ($code -eq 0)
}
$last = $samples[$samples.Count - 1]
Check "no worker died or was replaced under load" ((($before.Pids -join ',') -eq ($last.Pids -join ',')) -and $last.Pids.Count -eq $Workers) "($($before.Pids -join ',') -> $($last.Pids -join ','))"
if ($samples.Count -ge 3) {
    $m2 = $samples[1].Mem; $m3 = $samples[2].Mem
    $grow = $m3 - $m2
    Check "worker memory levels off (round 2 -> 3 grows under 16 MB or 15%)" ($grow -lt [Math]::Max(16MB, $m2 * 0.15)) ("({0:N1} -> {1:N1} MB)" -f ($m2 / 1MB), ($m3 / 1MB))
    $h1 = $samples[0].Handles; $h3 = $samples[2].Handles
    Check "the server does not leak handles (round 1 -> 3 grows under 100)" (($h3 - $h1) -lt 100) "($h1 -> $h3)"
}
if ($loggedIn) {
    $dash = Http-Body "$url/admin/content/dashboard" @("-c", $jar, "-b", $jar)
    Check "the admin session survives the load" ($dash -match '(?i)logout')
}

# ── Stop ─────────────────────────────────────────────────────────────────
$workerPids = @((Child-Php $server.Id) | ForEach-Object { $_.ProcessId })
$gone = Stop-Velocity $Php $pidFile $server $base
Check "--stop ends the server" $gone
Start-Sleep -Seconds 2
$left = @($workerPids | Where-Object { Get-Process -Id $_ -ErrorAction SilentlyContinue })
Check "...and every worker" ($left.Count -eq 0) "(left: $($left -join ', '))"
$text = (Get-Content $log, "$log.err" -Raw -ErrorAction SilentlyContinue) -join "`n"
Check "...gracefully" ($text -like "*Graceful shutdown*")
Check "the port is free afterwards" (Port-Free $Port)
if (-not $server.HasExited) { Stop-Process -Id $server.Id -Force }
foreach ($p in $left) { Stop-Process -Id $p -Force -ErrorAction SilentlyContinue }

Save-Logs $LogDir @($log, "$log.err")
if ($LogDir -ne "") {
    Get-ChildItem $LogDir -Filter "server.log*" | ForEach-Object { Rename-Item $_.FullName "exponential-$($_.Name)" -Force -ErrorAction SilentlyContinue }
    $expLog = Join-Path $Dir "var\log"
    if (Test-Path $expLog) { Copy-Item "$expLog\*.log" -Destination $LogDir -ErrorAction SilentlyContinue }
    $siteLog = Get-ChildItem (Join-Path $Dir "var") -Recurse -Filter "error.log" -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($siteLog) { Copy-Item $siteLog.FullName (Join-Path $LogDir "exponential-error.log") -ErrorAction SilentlyContinue }
}
Get-Content $log, "$log.err" -ErrorAction SilentlyContinue | Select-Object -Last 30
exit (Done "exponential-site")
