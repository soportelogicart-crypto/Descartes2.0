@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0sync-xampp.ps1" %*
exit /b %ERRORLEVEL%
