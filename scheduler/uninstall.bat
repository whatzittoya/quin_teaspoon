@echo off
REM QUINOS — Remove Windows Task Scheduler job
REM Run this script as Administrator

set TASK_NAME=QuinosSalesUpload

echo Removing scheduled task "%TASK_NAME%"...

schtasks /Delete /TN "%TASK_NAME%" /F

if %ERRORLEVEL% EQU 0 (
    echo.
    echo Task removed successfully!
    REM Remove the .enabled flag
    if exist "%~dp0.enabled" del "%~dp0.enabled"
) else (
    echo.
    echo Failed to remove task. Make sure you run this as Administrator.
)

echo.
pause
