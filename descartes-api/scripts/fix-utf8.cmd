@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0fix-utf8.ps1"
exit /b %ERRORLEVEL%
