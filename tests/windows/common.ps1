# Shared helpers for the Windows tests (docs/windows.md). Dot-source it:
#   . (Join-Path $PSScriptRoot "common.ps1")
# Works in Windows PowerShell 5.1 and PowerShell 7.

$ErrorActionPreference = "Continue"
$script:pass = 0
$script:fail = 0
$script:repo = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path

function Check([string]$what, [bool]$ok, [string]$detail = "") {
    if ($ok) { $script:pass++; Write-Host "  PASS  $what $detail" }
    else { $script:fail++; Write-Host "  FAIL  $what $detail" }
}

function Done([string]$name) {
    Write-Host ""
    if ($script:fail -eq 0) { Write-Host "PASS  $name`: $($script:pass) passed, 0 failed" }
    else { Write-Host "FAIL  $name`: $($script:pass) passed, $($script:fail) failed" }
    return [int]($script:fail -gt 0)
}

function FreePort {
    $l = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
    $l.Start(); $p = $l.LocalEndpoint.Port; $l.Stop(); return $p
}

function Port-Free([int]$port) {
    try { $l = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $port); $l.Start(); $l.Stop(); return $true }
    catch { return $false }
}

function Quote([string]$a) { if ($a -match '[\s"]') { return '"' + ($a -replace '"', '\"') + '"' } return $a }

function Wait-Port([int]$port, [int]$seconds, $proc = $null) {
    # By the clock, with a short connect timeout: a refused connection to
    # localhost can take seconds on Windows (SYN retries).
    $deadline = (Get-Date).AddSeconds($seconds)
    while ((Get-Date) -lt $deadline) {
        $c = [System.Net.Sockets.TcpClient]::new()
        try {
            $t = $c.ConnectAsync("127.0.0.1", $port)
            if ($t.Wait(1000) -and $c.Connected) { return $true }
        } catch { } finally { $c.Close() }
        if ($proc -and $proc.HasExited) { return $false }
        Start-Sleep -Milliseconds 300
    }
    return $false
}

# Start qbixserver.php; returns the process. Output goes to $log and $log.err.
function Start-Velocity([string]$php, [string[]]$serverArgs, [string]$log) {
    $all = @("$script:repo\sbin\qbixserver.php") + $serverArgs
    return Start-Process -FilePath $php -ArgumentList ($all | ForEach-Object { Quote $_ }) -PassThru -NoNewWindow `
        -RedirectStandardOutput $log -RedirectStandardError "$log.err"
}

function Stop-Velocity([string]$php, [string]$pidFile, $server, [string]$base) {
    $p = Start-Process -FilePath $php -ArgumentList (@("$script:repo\sbin\qbixserver.php", "--stop", "--pid=$pidFile") | ForEach-Object { Quote $_ }) `
        -PassThru -NoNewWindow -Wait -RedirectStandardOutput "$base\stop.out" -RedirectStandardError "$base\stop.err"
    if ($server) { return $server.WaitForExit(30000) }
    return $true
}

# curl.exe (in every Windows since 10 1803 / Server 2019): status code only.
function Http-Status([string]$url, [string[]]$extra = @()) {
    $out = & curl.exe -s -o NUL -w "%{http_code}" --max-time 60 @extra $url 2>$null
    return [int]("0" + ($out -replace '[^0-9]', ''))
}
function Http-Body([string]$url, [string[]]$extra = @()) {
    return (& curl.exe -s --max-time 60 @extra $url 2>$null) -join "`n"
}

function Child-Php([int]$parentPid) {
    return @(Get-CimInstance Win32_Process -Filter "ParentProcessId = $parentPid" | Where-Object { $_.Name -like "php*" })
}

# One HTTPS request over SslStream, accepting any certificate, returning the
# status line and the certificate the server presented. TLS 1.2 or later.
function Https-Get([int]$port, [string]$path, [string]$sni = "localhost") {
    $result = @{ Status = 0; Line = ""; Thumbprint = ""; Subject = ""; Protocol = ""; Error = "" }
    $script:seenCert = $null
    $client = $null
    try {
        $client = [System.Net.Sockets.TcpClient]::new("127.0.0.1", $port)
        $client.ReceiveTimeout = 20000; $client.SendTimeout = 20000
        $cb = [System.Net.Security.RemoteCertificateValidationCallback] { param($s, $c, $ch, $e) $script:seenCert = $c; return $true }
        $ssl = [System.Net.Security.SslStream]::new($client.GetStream(), $false, $cb)
        $protocols = [System.Security.Authentication.SslProtocols]::Tls12
        try { $protocols = $protocols -bor [System.Security.Authentication.SslProtocols]::Tls13 } catch { }
        $ssl.AuthenticateAsClient($sni, $null, $protocols, $false)
        $result.Protocol = [string]$ssl.SslProtocol
        $req = [System.Text.Encoding]::ASCII.GetBytes("GET $path HTTP/1.1`r`nHost: $sni`r`nConnection: close`r`n`r`n")
        $ssl.Write($req, 0, $req.Length); $ssl.Flush()
        $reader = [System.IO.StreamReader]::new($ssl)
        $line = $reader.ReadLine()
        $result.Line = $line
        if ($line -match '^HTTP/\S+\s+(\d{3})') { $result.Status = [int]$Matches[1] }
        # What the server presented, as the stream kept it (the callback's
        # variables are not reliably visible here).
        $presented = $ssl.RemoteCertificate
        if ($presented) {
            $c2 = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new($presented)
            $result.Thumbprint = $c2.Thumbprint; $result.Subject = $c2.Subject
        }
    } catch {
        $result.Error = $_.Exception.Message
        if ($_.Exception.InnerException) { $result.Error += " / " + $_.Exception.InnerException.Message }
    } finally { if ($client) { $client.Close() } }
    return $result
}

function Save-Logs([string]$logDir, [string[]]$files) {
    if ($logDir -eq "") { return }
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    foreach ($f in $files) { if (Test-Path $f) { Copy-Item $f -Destination $logDir -Force -ErrorAction SilentlyContinue } }
}
