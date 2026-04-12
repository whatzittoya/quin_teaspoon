@echo off
REM QUINOS — Run daily sales upload
REM Called by Windows Task Scheduler

set PHP=C:\xampp\php\php.exe
set SCRIPT=%~dp0upload_sales.php
set LOG=%~dp0upload.log

echo ============================================ >> "%LOG%"
echo %date% %time% — Starting scheduled upload >> "%LOG%"
echo ============================================ >> "%LOG%"

"%PHP%" "%SCRIPT%" >> "%LOG%" 2>&1

echo Exit code: %ERRORLEVEL% >> "%LOG%"
echo. >> "%LOG%"
