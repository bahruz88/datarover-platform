@echo off
echo ============================================
echo DQ SCHEDULE SERVICE - STARTING
echo ============================================
echo.

:: Activate virtual environment
if not exist "venv\Scripts\activate.bat" (
    echo ERROR: Virtual environment tapilmadi!
    echo Once install.bat ishledin?
    pause
    exit /b 1
)

call venv\Scripts\activate.bat

echo Starting service on port 8001...
echo.
echo Press Ctrl+C to stop
echo.

python dq_schedule_simple.py

pause
