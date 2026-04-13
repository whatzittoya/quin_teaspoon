@echo off
REM QUINOS — Install Windows Task Scheduler job
REM Runs daily at 07:00 (7:00 AM local time)
REM Run this script as Administrator

set TASK_NAME=QuinosSalesUpload
set BAT_PATH=%~dp0run.bat

echo Creating scheduled task "%TASK_NAME%"...
echo Batch file: %BAT_PATH%
echo Schedule: Daily at 07:00
echo.

schtasks /Create /TN "%TASK_NAME%" /TR "\"%BAT_PATH%\"" /SC DAILY /ST 07:00 /F

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
)

echo.
pause
