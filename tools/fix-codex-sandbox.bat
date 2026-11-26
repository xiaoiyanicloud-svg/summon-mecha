@echo off
setlocal enabledelayedexpansion
title Codex sandbox repair
set "PS1=%~dp0fix-codex-sandbox.ps1"

echo ============================================================
echo  Codex / ChatGPT Windows sandbox setup repair
echo  Target: %USERPROFILE%\.codex\.sandbox-bin
echo ============================================================
echo.

if not exist "%PS1%" (
    echo [ERROR] fix-codex-sandbox.ps1 not found next to this file.
    echo         Expected: %PS1%
    echo.
    pause
    exit /b 1
)

net session >nul 2>&1
if not "%errorlevel%"=="0" (
    echo Requesting administrator rights ^(UAC prompt will appear^)...
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

echo Running as administrator. Starting repair...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS1%"
set "RC=%errorlevel%"

echo.
echo ============================================================
if "%RC%"=="0" (
    echo Repair finished. Now:
    echo   1. open the ChatGPT / Codex app
    echo   2. press the "Reset setup" button on the banner
) else (
    echo Repair exited with code %RC% - read the output above.
)
echo ============================================================
echo.
pause
