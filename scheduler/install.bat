@echo off
REM QUINOS — Install Windows Task Scheduler job
REM Runs daily at 22:30 (10:30 PM local time) — uploads today's sales
REM Run this script as Administrator

set TASK_NAME=QuinosSalesUpload
set BAT_PATH=%~dp0run.bat

REM Remove existing task first (ignore error if not found)
schtasks /Delete /TN "%TASK_NAME%" /F >nul 2>&1

echo Creating scheduled task "%TASK_NAME%"...
echo Batch file: %BAT_PATH%
echo Schedule: Daily at 22:30
echo.

schtasks /Create /TN "%TASK_NAME%" /TR "\"%BAT_PATH%\"" /SC DAILY /ST 22:30 /F

if %ERRORLEVEL% EQU 0 (
    echo.
    echo Task created successfully!
    echo.
    REM Create the .enabled flag so the script will run
    echo enabled > "%~dp0.enabled"
    echo Scheduler enabled.
) else (
    echo.
    echo Failed to create task. Make sure you run this as Administrator.
    echo Note: A previous task with the same name may still exist.
)

echo.
pause
