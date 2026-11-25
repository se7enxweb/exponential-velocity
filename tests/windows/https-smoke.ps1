# HTTPS on Windows (docs/windows.md): Velocity's own TLS, with the portable
# worker pool, from two certificate sources.
#
#   1. self-signed: Velocity makes its own certificate (openssl extension on
#      Windows, which needs an openssl.cnf -- the case that breaks first);
#   2. the Windows certificate store: a certificate made with
#      New-SelfSignedCertificate in Cert:\CurrentUser\My is exported as a
#      .pfx and served through mode "pkcs12" -- the server must present
#      exactly that certificate (same thumbprint).
#
# Each part checks: HTTPS answers 200 from a worker, over TLS 1.2 or later;
# plain HTTP on the other port still answers; a stop is graceful.
#
#   powershell -ExecutionPolicy Bypass -File tests\windows\https-smoke.ps1 -Php C:\php\php.exe

param(
    [string]$Php = "php.exe",
    [string]$LogDir = "",
    [int]$StartSeconds = 60
)
. (Join-Path $PSScriptRoot "common.ps1")

$base = Join-Path ([System.IO.Path]::GetTempPath()) ("vc-https-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
$web = Join-Path $base "web"
New-Item -ItemType Directory -Force -Path $web | Out-Null
Set-Content -Path (Join-Path $web "pid.php") -Value '<?php echo getmypid(), " ", isset($_SERVER["HTTPS"]) ? $_SERVER["HTTPS"] : "-";' -Encoding ASCII
Write-Host "PHP:  $(& $Php -v | Select-Object -First 1)"

function Run-Part([string]$label, [hashtable]$https, [string]$wantThumb) {
    $dir = Join-Path $base $label
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    $port = FreePort; $sport = FreePort
    while ($sport -eq $port) { $sport = FreePort }
    $https.port = $sport
    $conf = @{ Q = @{ web = @{ cache = @{ enabled = $false }; https = $https }; webserver = @{ poolMode = "portable" } } }
    $confFile = Join-Path $dir "conf.json"
    [IO.File]::WriteAllText($confFile, ($conf | ConvertTo-Json -Depth 8))
    $pidFile = Join-Path $dir "vc.pid"; $log = Join-Path $dir "server.log"
    $server = Start-Velocity $Php @("--root=$web", "--port=$port", "--workers=2", "--config=$confFile", "--pid=$pidFile", "--pool-mode=portable") $log
    $up = (Wait-Port $port $StartSeconds $server) -and (Wait-Port $sport 30 $server)
    Check "[$label] listens on HTTP $port and HTTPS $sport" $up
    if ($up) {
        $r = $null
        for ($i = 0; $i -lt 20; $i++) { $r = Https-Get $sport "/pid.php"; if ($r.Status -eq 200) { break }; Start-Sleep -Milliseconds 500 }
        Check "[$label] HTTPS answers 200" ($r.Status -eq 200) "($($r.Line) $($r.Error))"
        Check "[$label] ...over TLS 1.2 or later" ($r.Protocol -match 'Tls1[23]') "($($r.Protocol))"
        if ($wantThumb -ne "") { Check "[$label] ...presenting the store's certificate" ($r.Thumbprint -eq $wantThumb) "(got $($r.Thumbprint), want $wantThumb)" }
        else { Check "[$label] ...presenting a certificate" ($r.Thumbprint -ne "") "($($r.Subject))" }
        $ok = 0
        for ($i = 0; $i -lt 10; $i++) { if ((Https-Get $sport "/pid.php").Status -eq 200) { $ok++ } }
        Check "[$label] ten more HTTPS requests answered" ($ok -eq 10) "($ok/10)"
        Check "[$label] plain HTTP still answers" ((Http-Status "http://127.0.0.1:$port/pid.php") -eq 200)
        $gone = Stop-Velocity $Php $pidFile $server $dir
        Check "[$label] --stop ends the server" $gone
        Start-Sleep -Seconds 1
        Check "[$label] ...and frees both ports" ((Port-Free $port) -and (Port-Free $sport))
    }
    if (-not $server.HasExited) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
    Get-Content $log, "$log.err" -ErrorAction SilentlyContinue | Select-String -Pattern 'tls|ssl|cert|error|warn' | Select-Object -First 15 | ForEach-Object { Write-Host "    | $_" }
    Save-Logs $LogDir @($log, "$log.err")
    if ($LogDir -ne "") { Get-ChildItem $LogDir -Filter "server.log*" | ForEach-Object { Rename-Item $_.FullName "https-$label-$($_.Name)" -Force -ErrorAction SilentlyContinue } }
}

# 1. Self-signed, made by Velocity.
Run-Part "self-signed" @{ mode = "self-signed"; selfSigned = @{ dir = (Join-Path $base "ssl-self") } } ""

# 2. The Windows certificate store, as PKCS#12.
$cert = $null
try {
    $cert = New-SelfSignedCertificate -DnsName "localhost", "vc-https-smoke.test" -CertStoreLocation "Cert:\CurrentUser\My" `
        -KeyExportPolicy Exportable -KeyAlgorithm RSA -KeyLength 2048 -NotAfter (Get-Date).AddDays(7)
} catch { Write-Host "  New-SelfSignedCertificate: $($_.Exception.Message)" }
Check "a certificate was made in Cert:\CurrentUser\My" ($cert -ne $null)
if ($cert) {
    $pfx = Join-Path $base "store.pfx"
    $pwPlain = "vc-" + [guid]::NewGuid().ToString("N").Substring(0, 12)
    $pwFile = Join-Path $base "store.pass"
    [IO.File]::WriteAllText($pwFile, $pwPlain)
    Export-PfxCertificate -Cert $cert -FilePath $pfx -Password (ConvertTo-SecureString $pwPlain -AsPlainText -Force) | Out-Null
    Check "...and exported as a .pfx" (Test-Path $pfx)
    Run-Part "cert-store" @{ mode = "pkcs12"; bundle = $pfx; passwordFile = $pwFile; selfSigned = @{ dir = (Join-Path $base "ssl-store") } } $cert.Thumbprint
    Remove-Item "Cert:\CurrentUser\My\$($cert.Thumbprint)" -ErrorAction SilentlyContinue
}

exit (Done "https-smoke")
