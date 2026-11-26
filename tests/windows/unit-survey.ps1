# Run every self-contained unit test (tests/unit-*.php) on Windows and record
# which pass, which fail and which time out (docs/windows.md, "Unit tests on
# Windows"). Most were written for Linux, where several exercise fork(),
# signals, /proc or POSIX permissions, so this is a survey, not a gate: the
# gate is the list in tests/windows/unit-windows.txt, which must all pass.
#
#   powershell -ExecutionPolicy Bypass -File tests\windows\unit-survey.ps1 -Php C:\php\php.exe [-Gate]
#
# -Gate runs only the listed tests and exits non-zero if any fails.

param(
    [string]$Php = "php.exe",
    [int]$Timeout = 120,
    [switch]$Gate,
    [string]$LogDir = ""
)
. (Join-Path $PSScriptRoot "common.ps1")
$tests = Get-ChildItem (Join-Path $script:repo "tests") -Filter "unit-*.php" | Sort-Object Name
$listFile = Join-Path $PSScriptRoot "unit-windows.txt"
$listed = @(Get-Content $listFile | Where-Object { $_ -match '^\s*unit-' } | ForEach-Object { ($_ -split '\s')[0].Trim() })
if ($Gate) { $tests = $tests | Where-Object { $listed -contains $_.Name } }
if ($LogDir -ne "") { New-Item -ItemType Directory -Force -Path $LogDir | Out-Null }
$rows = @()
$tmp = Join-Path ([IO.Path]::GetTempPath()) ("vc-unit-" + [guid]::NewGuid().ToString("N").Substring(0, 8))
New-Item -ItemType Directory -Force -Path $tmp | Out-Null
foreach ($t in $tests) {
    $out = Join-Path $tmp "$($t.BaseName).txt"
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $p = Start-Process -FilePath $Php -ArgumentList @(Quote $t.FullName) -PassThru -NoNewWindow `
        -RedirectStandardOutput $out -RedirectStandardError "$out.err" -WorkingDirectory $script:repo
    # Without the handle taken now, ExitCode reads as null once the process has ended.
    $null = $p.Handle
    $result = "pass"
    if (-not $p.WaitForExit($Timeout * 1000)) {
        & taskkill.exe /PID $p.Id /T /F 2>&1 | Out-Null
        $result = "timeout"
    } elseif ($p.ExitCode -ne 0) { $result = "fail" }
    $last = (Get-Content $out, "$out.err" -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -ne "" } | Select-Object -Last 1)
    $rows += [pscustomobject]@{ Test = $t.Name; Result = $result; Seconds = [Math]::Round($sw.Elapsed.TotalSeconds, 1); Listed = ($listed -contains $t.Name); Last = "$last" }
    Write-Host ("  {0,-8} {1,-58} {2,6}s  {3}" -f $result.ToUpper(), $t.Name, [Math]::Round($sw.Elapsed.TotalSeconds, 1), $last)
    if ($Gate) { Check $t.Name ($result -eq "pass") }
    if ($LogDir -ne "" -and $result -ne "pass") { Copy-Item $out, "$out.err" -Destination $LogDir -ErrorAction SilentlyContinue }
}
$n = $rows.Count
$ok = @($rows | Where-Object { $_.Result -eq "pass" }).Count
$summary = "Windows unit tests: $ok of $n pass (" + (@($rows | Where-Object { $_.Result -eq "fail" }).Count) + " fail, " + (@($rows | Where-Object { $_.Result -eq "timeout" }).Count) + " time out)"
Write-Host ""
Write-Host $summary
if ($LogDir -ne "") { $rows | Export-Csv -NoTypeInformation -Path (Join-Path $LogDir "unit-survey.csv") }
if ($env:GITHUB_STEP_SUMMARY) {
    Add-Content $env:GITHUB_STEP_SUMMARY "### $summary`n"
    Add-Content $env:GITHUB_STEP_SUMMARY "| Test | Result | s |`n|---|---|---|"
    foreach ($r in $rows) { Add-Content $env:GITHUB_STEP_SUMMARY "| $($r.Test) | $($r.Result) | $($r.Seconds) |" }
}
if ($Gate) { exit (Done "unit-windows") }
exit 0
