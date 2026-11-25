<#
.SYNOPSIS
  vcc for Windows: run Exponential Velocity (vc) as a Windows service.

.DESCRIPTION
  The Windows counterpart of vcc ("vc control"). It keeps Velocity's Debian
  Apache layout under %ProgramData%\vc (vc.conf, ports.conf, envvars,
  sites-available, sites-enabled, conf-*, mods-*, ssl, logs, state, run),
  installs one Windows service per site, run by a low-privilege account (a
  virtual account "NT SERVICE\<name>" by default, never LocalSystem unless
  asked for), and gives it exactly the file access it needs through ACLs.

  The service host (VcService.exe) is compiled on this machine from
  VcService.cs with the .NET Framework's own C# compiler, so nothing is
  downloaded. It answers the Service Control Manager, starts qbixserver.php
  with php.exe, and turns Stop and Shutdown into Velocity's graceful stop.

  Every command ends with PASS, FAIL, USAGE or REFUSED and exits 0, 1, 2 or 3.
  Commands that change the machine need an elevated PowerShell. -DryRun prints
  what would be done and changes nothing.

.EXAMPLE
  .\vcc.ps1 doctor -Php C:\php\php.exe
  .\vcc.ps1 install -Php C:\php\php.exe -Root C:\sites\example -Port 8080 -Preset exponential -WritableDirs var
  .\vcc.ps1 status
  .\vcc.ps1 stop ; .\vcc.ps1 start ; .\vcc.ps1 restart
  .\vcc.ps1 site-list ; .\vcc.ps1 site-disable default ; .\vcc.ps1 site-enable default
  .\vcc.ps1 cert-import -Thumbprint 0123ABCD... -HttpsPort 8443
  .\vcc.ps1 logs -Tail 50
  .\vcc.ps1 uninstall            # the service and its ACL grants; the tree stays
  .\vcc.ps1 uninstall -Purge     # also %ProgramData%\vc
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)][string]$Command = "help",
    [Parameter(Position = 1)][string]$Target = "",
    [string]$Name = "vc",
    [string]$Site = "default",
    [string]$Php = "",
    [string]$Source = "",
    [string]$Root = "",
    [int]$Port = 0,
    [int]$HttpsPort = 0,
    [int]$Workers = 0,
    [string]$Preset = "",
    [string[]]$WritableDirs = @(),
    [string]$Account = "Virtual",
    [string]$Password = "",
    [ValidateSet("Automatic", "Manual", "Disabled")][string]$StartupType = "Automatic",
    [string]$ConfDir = "",
    [string]$Thumbprint = "",
    [string]$CertStore = "LocalMachine\My",
    [int]$Tail = 40,
    [int]$Grace = 25,
    [switch]$OpenFirewall,
    [switch]$Purge,
    [switch]$DryRun,
    [switch]$Json
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version 2

# ── Where things are ───────────────────────────────────────────────────────

$script:here = $PSScriptRoot
if ($Source -eq "") { $Source = (Resolve-Path (Join-Path $here "..\..")).Path }
$pd = $env:ProgramData
if (-not $pd) { $pd = "C:\ProgramData" }
if ($ConfDir -eq "") { $ConfDir = Join-Path $pd "vc" }
$dirs = [ordered]@{
    conf  = $ConfDir
    bin   = Join-Path $ConfDir "bin"
    ssl   = Join-Path $ConfDir "ssl"
    logs  = Join-Path $ConfDir "logs"
    state = Join-Path $ConfDir "state"
    run   = Join-Path $ConfDir "run"
    cache = Join-Path $ConfDir "cache"
}
$pairs = @("sites", "conf", "mods")

function Out-Line([string]$s) { if (-not $Json) { Write-Host $s } }
function Finish([string]$word, [string]$msg, [int]$code, $data = $null) {
    if ($Json) {
        $o = [ordered]@{ result = $word; message = $msg; command = $Command }
        if ($data) { $o.data = $data }
        Write-Output ($o | ConvertTo-Json -Depth 6 -Compress)
    } else {
        Write-Host "$word`: $msg"
    }
    exit $code
}
function Fail([string]$m) { Finish "FAIL" $m 1 }
function Usage([string]$m) { Finish "USAGE" $m 2 }
function Refuse([string]$m) { Finish "REFUSED" $m 3 }

function Test-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}
function Need-Admin { if (-not $DryRun -and -not (Test-Admin)) { Refuse "'$Command' changes the machine: run it from an elevated PowerShell (or add -DryRun)" } }

function Do-Step([string]$what, [scriptblock]$action) {
    if ($DryRun) { Out-Line "  would: $what"; return }
    Out-Line "  $what"
    & $action
}

function Run-Native([string]$exe, [string[]]$arguments) {
    # Native programs writing to stderr must not throw under "Stop".
    $prev = $ErrorActionPreference; $ErrorActionPreference = "Continue"
    try { $out = & $exe @arguments 2>&1 | Out-String } finally { $ErrorActionPreference = $prev }
    return @{ Code = $LASTEXITCODE; Out = $out.Trim() }
}

function Service-Account([string]$svc) {
    switch -Regex ($Account) {
        '^(?i)virtual$'        { return "NT SERVICE\$svc" }
        '^(?i)localservice$'   { return "NT AUTHORITY\LocalService" }
        '^(?i)networkservice$' { return "NT AUTHORITY\NetworkService" }
        '^(?i)localsystem$'    { return "LocalSystem" }
        default                { return $Account }
    }
}

function Find-Csc {
    $roots = @("Framework64", "FrameworkArm64", "Framework") | ForEach-Object { Join-Path $env:WINDIR "Microsoft.NET\$_\v4.0.30319\csc.exe" }
    foreach ($c in $roots) { if (Test-Path $c) { return $c } }
    return $null
}

function Resolve-Php {
    if ($Php -ne "") { if (Test-Path $Php) { return (Resolve-Path $Php).Path } return $null }
    $c = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($c) { return $c.Source }
    return $null
}

function Meta-File([string]$svc) { return Join-Path $dirs.state "service-$svc.json" }
function Read-Meta([string]$svc) {
    $f = Meta-File $svc
    if (Test-Path $f) { return Get-Content $f -Raw | ConvertFrom-Json }
    return $null
}

function Write-Json([string]$file, $obj) {
    $text = $obj | ConvertTo-Json -Depth 8
    [IO.File]::WriteAllText($file, $text + "`r`n", (New-Object Text.UTF8Encoding($false)))
}

function Grant([string]$path, [string]$who, [string]$rights) {
    # icacls takes its arguments as given: no shell quoting is involved.
    $r = Run-Native "icacls.exe" @($path, "/grant", "${who}:$rights", "/Q")
    if ($r.Code -ne 0) { throw "icacls $path /grant ${who}:$rights failed: $($r.Out)" }
}
function Revoke([string]$path, [string]$who) {
    if (Test-Path $path) { Run-Native "icacls.exe" @($path, "/remove:g", $who, "/T", "/C", "/Q") | Out-Null }
}

# ── The tree ───────────────────────────────────────────────────────────────

function Ensure-Tree {
    foreach ($d in $dirs.Values) { if (-not (Test-Path $d)) { New-Item -ItemType Directory -Force -Path $d | Out-Null } }
    foreach ($p in $pairs) {
        foreach ($s in @("available", "enabled")) {
            $d = Join-Path $ConfDir "$p-$s"
            if (-not (Test-Path $d)) { New-Item -ItemType Directory -Force -Path $d | Out-Null }
        }
    }
    $main = Join-Path $ConfDir "vc.conf"
    if (-not (Test-Path $main)) {
        Write-Json $main ([ordered]@{
            _comment = "Base settings, shared by every site (like apache2.conf), Windows layout: docs/windows.md"
            Q = @{ webserver = @{ poolMode = "portable" } }
        })
    }
    $ports = Join-Path $ConfDir "ports.conf"
    if (-not (Test-Path $ports)) {
        Write-Json $ports ([ordered]@{
            _comment = "Listen ports (like Apache's ports.conf). A site file may set its own."
            Q = @{ webserver = @{ port = 8080 } }
        })
    }
    $env = Join-Path $ConfDir "envvars"
    if (-not (Test-Path $env)) {
        [IO.File]::WriteAllText($env, "# Environment for the server (like Apache's envvars): NAME='value' per line, read, never run.`r`n")
    }
}

# Inheritance from %ProgramData% (where every user may create files) is cut:
# the tree is SYSTEM's and the Administrators'; the service account reads it
# and may write only logs, state, run, cache and ssl.
function Secure-Tree([string]$who) {
    $r = Run-Native "icacls.exe" @($ConfDir, "/inheritance:r", "/grant:r", "*S-1-5-18:(OI)(CI)F", "*S-1-5-32-544:(OI)(CI)F", "/Q")
    if ($r.Code -ne 0) { throw "icacls $ConfDir failed: $($r.Out)" }
    Grant $ConfDir $who "(OI)(CI)RX"
    foreach ($k in @("logs", "state", "run", "cache", "ssl")) { Grant $dirs[$k] $who "(OI)(CI)M" }
}

function Site-File([string]$s, [string]$kind = "available") { return Join-Path $ConfDir "sites-$kind\$s.conf" }

function Write-Site {
    $f = Site-File $Site
    $q = [ordered]@{}
    $ws = [ordered]@{}
    if ($Port -gt 0) { $ws.port = $Port }
    if ($Workers -gt 0) { $ws.workers = $Workers }
    $ws.poolMode = "portable"
    $q.webserver = $ws
    if ($Preset -ne "") { $q.compat = @{ preset = $Preset } }
    if ($HttpsPort -gt 0) { $q.web = @{ https = [ordered]@{ port = $HttpsPort; mode = "self-signed" } } }
    if (Test-Path $f) {
        $old = Get-Content $f -Raw | ConvertFrom-Json
        Out-Line "  keeping $f (exists)"
        return
    }
    Write-Json $f ([ordered]@{ _comment = "Site '$Site' (docs/windows.md). Enabled by vcc.ps1 site-enable."; Q = $q })
}

function Toggle-Site([string]$s, [bool]$on) {
    $phpExe = Resolve-Php
    if (-not $phpExe) { Fail "php.exe not found: pass -Php" }
    $verb = "site:disable"; if ($on) { $verb = "site:enable" }
    $r = Run-Native $phpExe @((Join-Path $Source "sbin\qbixconsole.php"), $verb, $s, "--distribution=vc", "--conf-dir=$ConfDir")
    Out-Line "  $($r.Out)"
    return $r.Code -eq 0
}

# ── Commands ───────────────────────────────────────────────────────────────

function Cmd-Doctor {
    $ok = $true
    $rows = [ordered]@{}
    $os = Get-CimInstance Win32_OperatingSystem
    $rows.windows = "$($os.Caption) $($os.Version) build $($os.BuildNumber) ($env:PROCESSOR_ARCHITECTURE)"
    $phpExe = Resolve-Php
    if (-not $phpExe) { $rows.php = "MISSING (pass -Php)"; $ok = $false }
    else {
        $v = (Run-Native $phpExe @("-v")).Out -split "`r?`n" | Select-Object -First 1
        $rows.php = "$phpExe ($v)"
        $m = (Run-Native $phpExe @("-m")).Out -split "`r?`n" | ForEach-Object { $_.Trim().ToLower() }
        foreach ($e in @("sockets", "openssl", "mbstring", "sqlite3")) {
            if ($m -contains $e) { $rows["ext $e"] = "ok" } else { $rows["ext $e"] = "MISSING"; if ($e -ne "sqlite3") { $ok = $false } }
        }
    }
    $csc = Find-Csc
    if ($csc) { $rows.csc = $csc } else { $rows.csc = "MISSING (.NET Framework 4.x)"; $ok = $false }
    if (Test-Path (Join-Path $Source "sbin\qbixserver.php")) { $rows.source = $Source } else { $rows.source = "MISSING: $Source"; $ok = $false }
    $rows.admin = [string](Test-Admin)
    $rows.tree = $ConfDir
    foreach ($k in $rows.Keys) { Out-Line ("  {0,-14} {1}" -f $k, $rows[$k]) }
    if ($ok) { Finish "PASS" "prerequisites present" 0 $rows } else { Fail "prerequisites missing" }
}

function Cmd-Install {
    Need-Admin
    $phpExe = Resolve-Php
    if (-not $phpExe) { Fail "php.exe not found: pass -Php" }
    if ($Root -eq "") { Usage "install needs -Root <document root>" }
    if (-not (Test-Path $Root)) { Fail "no such directory: $Root" }
    if ($Account -match '^(?i)localsystem$') { Out-Line "  warning: LocalSystem is the most privileged account on the machine; the default virtual account is enough" }
    $csc = Find-Csc
    if (-not $csc) { Fail "the .NET Framework 4.x C# compiler (csc.exe) was not found" }
    $who = Service-Account $Name
    $rootFull = (Resolve-Path $Root).Path
    $exe = Join-Path $dirs.bin "VcService.exe"
    $pidPath = Join-Path $dirs.run "$Name.pid"
    $log = Join-Path $dirs.logs "$Name-server.log"
    $tmp = Join-Path $dirs.run "tmp-$Name"

    Do-Step "tree $ConfDir" { Ensure-Tree }
    Do-Step "site file $(Site-File $Site)" { Write-Site }
    Do-Step "enable site $Site" { if (-not (Toggle-Site $Site $true)) { throw "could not enable site $Site" } }
    Do-Step "compile the service host $exe" {
        $r = Run-Native $csc @("/nologo", "/target:exe", "/platform:anycpu", "/optimize+", "/out:$exe", "/reference:System.ServiceProcess.dll", (Join-Path $here "VcService.cs"))
        if ($r.Code -ne 0) { throw "csc failed: $($r.Out)" }
    }
    $serverArgs = @((Join-Path $Source "sbin\qbixserver.php"), "--distribution=vc", "--config=$(Site-File $Site 'enabled')",
        "--root=$rootFull", "--pid=$pidPath", "--pool-mode=portable")
    if ($Workers -gt 0) { $serverArgs += "--workers=$Workers" }
    function Q([string]$a) { if ($a -match '[\s"]') { return '"' + ($a -replace '"', '\"') + '"' } return $a }
    $image = (@($exe, "--name", $Name, "--php", $phpExe, "--pid", $pidPath, "--log", $log, "--event-source", $Name,
        "--tmp", $tmp, "--grace", "$Grace", "--workdir", $rootFull, "--") + $serverArgs | ForEach-Object { Q $_ }) -join " "

    Do-Step "event log source '$Name' (Application)" {
        if (-not [Diagnostics.EventLog]::SourceExists($Name)) { [Diagnostics.EventLog]::CreateEventSource($Name, "Application") }
    }
    Do-Step "service '$Name' ($StartupType)" {
        $existing = Get-Service -Name $Name -ErrorAction SilentlyContinue
        if ($existing) {
            if ($existing.Status -ne "Stopped") { Stop-Service -Name $Name -Force }
            # Through WMI, not sc.exe: Windows PowerShell 5.1 drops the quotes
            # inside an argument it passes to a native program.
            $w = Get-CimInstance Win32_Service -Filter "Name='$Name'"
            $r = Invoke-CimMethod -InputObject $w -MethodName Change -Arguments @{ PathName = $image }
            if ($r.ReturnValue -ne 0) { throw "changing the service's command line failed (Win32_Service.Change $($r.ReturnValue))" }
        } else {
            New-Service -Name $Name -BinaryPathName $image -DisplayName "Exponential Velocity ($Name)" `
                -Description "Exponential Velocity web server, site $Site (docs/windows.md)" -StartupType $StartupType | Out-Null
        }
        $cfg = @("config", $Name, "obj=", $who)
        if ($Password -ne "") { $cfg += @("password=", $Password) }
        $r = Run-Native "sc.exe" $cfg
        if ($r.Code -ne 0) { throw "sc config obj= $who failed: $($r.Out)" }
        Run-Native "sc.exe" @("failure", $Name, "reset=", "86400", "actions=", "restart/5000/restart/5000/restart/30000") | Out-Null
        Run-Native "sc.exe" @("failureflag", $Name, "1") | Out-Null
    }
    Do-Step "ACLs for $who" {
        Secure-Tree $who
        Grant $rootFull $who "(OI)(CI)RX"
        foreach ($w in $WritableDirs) {
            $p = Join-Path $rootFull $w
            if (-not (Test-Path $p)) { New-Item -ItemType Directory -Force -Path $p | Out-Null }
            Grant $p $who "(OI)(CI)M"
        }
        Grant $Source $who "(OI)(CI)RX"
        Grant (Split-Path $phpExe -Parent) $who "(OI)(CI)RX"
    }
    if ($OpenFirewall) {
        Do-Step "firewall: allow inbound TCP to the site's ports" {
            $ports = @()
            if ($Port -gt 0) { $ports += $Port } else { $ports += 8080 }
            if ($HttpsPort -gt 0) { $ports += $HttpsPort }
            Get-NetFirewallRule -Name "vc-$Name" -ErrorAction SilentlyContinue | Remove-NetFirewallRule
            New-NetFirewallRule -Name "vc-$Name" -DisplayName "Exponential Velocity ($Name)" -Direction Inbound -Action Allow `
                -Protocol TCP -LocalPort $ports -Profile Any | Out-Null
        }
    }
    Do-Step "record $(Meta-File $Name)" {
        Write-Json (Meta-File $Name) ([ordered]@{
            name = $Name; site = $Site; root = $rootFull; php = $phpExe; source = $Source; account = $who
            pid = $pidPath; log = $log; writable = @($WritableDirs); firewall = [bool]$OpenFirewall
            installed = (Get-Date).ToString("o")
        })
    }
    Finish "PASS" "service '$Name' installed for site '$Site' as $who; start it with: vcc.ps1 start -Name $Name" 0
}

function Wait-Status([string]$svc, [string]$want, [int]$seconds) {
    for ($i = 0; $i -lt $seconds * 2; $i++) {
        $s = Get-Service -Name $svc -ErrorAction SilentlyContinue
        if ($s -and [string]$s.Status -eq $want) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return $false
}

function Cmd-Start {
    Need-Admin
    if (-not (Get-Service -Name $Name -ErrorAction SilentlyContinue)) { Fail "no service '$Name' (vcc.ps1 install)" }
    Do-Step "start $Name" { Start-Service -Name $Name }
    if (-not $DryRun -and -not (Wait-Status $Name "Running" 30)) { Fail "service '$Name' did not reach Running" }
    Finish "PASS" "service '$Name' running" 0
}

function Cmd-Stop {
    Need-Admin
    if (-not (Get-Service -Name $Name -ErrorAction SilentlyContinue)) { Fail "no service '$Name'" }
    Do-Step "stop $Name (graceful: the server's stop request file)" { Stop-Service -Name $Name -Force }
    if (-not $DryRun -and -not (Wait-Status $Name "Stopped" ($Grace + 20))) { Fail "service '$Name' did not stop" }
    Finish "PASS" "service '$Name' stopped" 0
}

function Cmd-Status {
    $s = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if (-not $s) { Finish "PASS" "no service '$Name' installed" 0 @{ installed = $false } }
    $w = Get-CimInstance Win32_Service -Filter "Name='$Name'"
    $meta = Read-Meta $Name
    $data = [ordered]@{ installed = $true; status = [string]$s.Status; startType = [string]$w.StartMode; account = $w.StartName; processId = $w.ProcessId }
    if ($meta) { $data.site = $meta.site; $data.root = $meta.root; $data.log = $meta.log; $data.pid = $meta.pid }
    if ($w.ProcessId -gt 0) {
        $kids = @(Get-CimInstance Win32_Process -Filter "ParentProcessId=$($w.ProcessId)")
        if ($kids.Count -gt 0) {
            $srv = $kids[0]
            $data.server = $srv.ProcessId
            $data.workers = @(Get-CimInstance Win32_Process -Filter "ParentProcessId=$($srv.ProcessId)" | Where-Object { $_.Name -like "php*" }).Count
        }
    }
    foreach ($k in $data.Keys) { Out-Line ("  {0,-10} {1}" -f $k, $data[$k]) }
    Finish "PASS" "service '$Name' $($s.Status)" 0 $data
}

function Cmd-Uninstall {
    Need-Admin
    $meta = Read-Meta $Name
    $who = Service-Account $Name
    if ($meta -and $meta.account) { $who = $meta.account }
    $svc = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if ($svc) {
        if ($svc.Status -ne "Stopped") { Do-Step "stop $Name" { Stop-Service -Name $Name -Force; Wait-Status $Name "Stopped" ($Grace + 20) | Out-Null } }
        Do-Step "delete service $Name" {
            $r = Run-Native "sc.exe" @("delete", $Name)
            if ($r.Code -ne 0) { throw "sc delete failed: $($r.Out)" }
        }
    }
    if ($who -like "NT SERVICE\*" -or ($meta -and $meta.account)) {
        Do-Step "remove the ACL grants of $who" {
            # A virtual account's name stops resolving once its service is
            # deleted; the grants are removed by its SID, which is derived from
            # the service name (NT SERVICE\<name> = S-1-5-80-<sha1>).
            $sid = $null
            try { $sid = (New-Object Security.Principal.NTAccount($who)).Translate([Security.Principal.SecurityIdentifier]).Value } catch { }
            if (-not $sid -and $who -like "NT SERVICE\*") { $sid = (Run-Native "sc.exe" @("showsid", $Name)).Out -replace '(?s).*SERVICE SID:\s*(S-[0-9-]+).*', '$1' }
            $principal = $who
            if ($sid -and $sid -like "S-1-5-*") { $principal = "*$sid" }
            if ($meta) {
                Revoke $meta.root $principal
                Revoke $meta.source $principal
                Revoke (Split-Path $meta.php -Parent) $principal
            }
            Revoke $ConfDir $principal
        }
    }
    if ($meta -and $meta.firewall) { Do-Step "remove firewall rule vc-$Name" { Get-NetFirewallRule -Name "vc-$Name" -ErrorAction SilentlyContinue | Remove-NetFirewallRule } }
    if ([Diagnostics.EventLog]::SourceExists($Name)) { Do-Step "event log source '$Name'" { [Diagnostics.EventLog]::DeleteEventSource($Name) } }
    if (Test-Path (Meta-File $Name)) { Do-Step "forget $(Meta-File $Name)" { Remove-Item (Meta-File $Name) -Force } }
    if ($Purge) { Do-Step "remove $ConfDir" { Remove-Item $ConfDir -Recurse -Force } }
    Finish "PASS" "service '$Name' uninstalled" 0
}

function Cmd-SiteList {
    $rows = @()
    $avail = Join-Path $ConfDir "sites-available"
    if (Test-Path $avail) {
        foreach ($f in Get-ChildItem $avail -Filter *.conf) {
            $n = [IO.Path]::GetFileNameWithoutExtension($f.Name)
            $on = Test-Path (Site-File $n "enabled")
            $rows += [ordered]@{ site = $n; enabled = $on }
            $mark = " "; if ($on) { $mark = "*" }
            Out-Line "  $mark $n"
        }
    }
    Finish "PASS" "$($rows.Count) site(s) in $avail (* enabled)" 0 $rows
}

function Cmd-SiteToggle([bool]$on) {
    Need-Admin
    $s = $Target; if ($s -eq "") { $s = $Site }
    if ($on -and -not (Test-Path (Site-File $s))) {
        if ($Root -eq "") { Fail "no sites-available\$s.conf (vcc.ps1 site-add $s ...)" }
    }
    $done = $true
    Do-Step "$(if ($on) { 'enable' } else { 'disable' }) site $s" { $script:ok = Toggle-Site $s $on; if (-not $script:ok) { throw "failed" } }
    Finish "PASS" "site $s $(if ($on) { 'enabled' } else { 'disabled' }); restart the service to apply" 0
}

function Cmd-SiteAdd {
    Need-Admin
    if ($Target -ne "") { $script:Site = $Target }
    Do-Step "tree $ConfDir" { Ensure-Tree }
    Do-Step "site file $(Site-File $Site)" { Write-Site }
    Finish "PASS" "site $Site written; enable it with: vcc.ps1 site-enable $Site" 0
}

# The Windows certificate store -> a PKCS#12 bundle in ssl\, which Velocity
# reads natively (Q.web.https.mode = pkcs12). The key must be exportable.
function Cmd-CertImport {
    Need-Admin
    if ($Thumbprint -eq "") { Usage "cert-import needs -Thumbprint" }
    $tp = ($Thumbprint -replace '\s', '').ToUpper()
    $path = "Cert:\$CertStore\$tp"
    if (-not (Test-Path $path)) { Fail "no certificate $tp in Cert:\$CertStore" }
    $cert = Get-Item $path
    if (-not $cert.HasPrivateKey) { Fail "certificate $tp has no private key" }
    $pfx = Join-Path $dirs.ssl "store-$tp.pfx"
    $pwFile = Join-Path $dirs.ssl "store-$tp.pass"
    Do-Step "export $path to $pfx (random password in $pwFile)" {
        $bytes = New-Object byte[] 24
        [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
        $pw = [Convert]::ToBase64String($bytes) -replace '[^A-Za-z0-9]', 'x'
        $sec = ConvertTo-SecureString $pw -AsPlainText -Force
        # AES-256: the default TripleDES/RC2 bundle needs OpenSSL 3's legacy provider,
        # which not every PHP build loads (the 32-bit one does not).
        Export-PfxCertificate -Cert $cert -FilePath $pfx -Password $sec -CryptoAlgorithmOption AES256_SHA256 | Out-Null
        [IO.File]::WriteAllText($pwFile, $pw)
    }
    $f = Site-File $Site
    if (-not (Test-Path $f)) { Fail "no site file $f" }
    Do-Step "site $Site serves HTTPS from it (mode pkcs12)" {
        $j = Get-Content $f -Raw | ConvertFrom-Json
        if (-not $j.PSObject.Properties["Q"]) { $j | Add-Member Q ([pscustomobject]@{}) }
        if (-not $j.Q.PSObject.Properties["web"]) { $j.Q | Add-Member web ([pscustomobject]@{}) }
        $port = $HttpsPort; if ($port -le 0) { $port = 443 }
        $https = [ordered]@{ port = $port; mode = "pkcs12"; bundle = $pfx; passwordFile = $pwFile }
        if ($j.Q.web.PSObject.Properties["https"]) { $j.Q.web.https = $https } else { $j.Q.web | Add-Member https $https }
        Write-Json $f $j
        Toggle-Site $Site $true | Out-Null
    }
    Finish "PASS" "certificate $tp ($($cert.Subject), until $($cert.NotAfter.ToString('yyyy-MM-dd'))) configured for site $Site; restart the service to apply" 0
}

function Cmd-Logs {
    $meta = Read-Meta $Name
    $log = Join-Path $dirs.logs "$Name-server.log"
    if ($meta) { $log = $meta.log }
    if (-not (Test-Path $log)) { Fail "no log yet at $log" }
    Get-Content $log -Tail $Tail | ForEach-Object { Out-Line $_ }
    Out-Line "  (event log: Get-EventLog -LogName Application -Source $Name -Newest 20)"
    Finish "PASS" $log 0
}

function Cmd-Help {
    Get-Help $MyInvocation.PSCommandPath -Detailed | Out-String | Write-Host
    Finish "USAGE" "vcc.ps1 doctor|install|start|stop|restart|status|uninstall|site-add|site-list|site-enable|site-disable|cert-import|logs" 2
}

try {
    switch ($Command.ToLower()) {
        "doctor"       { Cmd-Doctor }
        "install"      { Cmd-Install }
        "start"        { Cmd-Start }
        "stop"         { Cmd-Stop }
        "restart"      { Need-Admin; if (-not $DryRun) { Restart-Service -Name $Name -Force; if (-not (Wait-Status $Name "Running" 60)) { Fail "not running after restart" } }; Finish "PASS" "service '$Name' restarted" 0 }
        "status"       { Cmd-Status }
        "uninstall"    { Cmd-Uninstall }
        "site-list"    { Cmd-SiteList }
        "sites"        { Cmd-SiteList }
        "site-add"     { Cmd-SiteAdd }
        "site-enable"  { Cmd-SiteToggle $true }
        "site-disable" { Cmd-SiteToggle $false }
        "cert-import"  { Cmd-CertImport }
        "logs"         { Cmd-Logs }
        default        { Cmd-Help }
    }
} catch {
    Fail "$Command`: $($_.Exception.Message)"
}
