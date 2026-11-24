@echo off
rem Windows smoke test for the portable worker pool. See docs/windows.md.
rem   tests\windows\smoke.bat [C:\php\php.exe]
setlocal
set PHPEXE=%~1
if "%PHPEXE%"=="" set PHPEXE=php.exe
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0smoke.ps1" -Php "%PHPEXE%"
exit /b %ERRORLEVEL%
