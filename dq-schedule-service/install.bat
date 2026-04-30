@echo off
echo ============================================
echo DQ SCHEDULE SERVICE - INSTALLATION
echo ============================================
echo.

:: Check Python
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Python tapilmadi!
    echo Python 3.7+ qurashdirilmalidir.
    pause
    exit /b 1
)

echo [1/3] Creating virtual environment...
python -m venv venv
if %errorlevel% neq 0 (
    echo ERROR: Virtual environment yaradila bilmedi!
    pause
    exit /b 1
)

echo [2/3] Activating virtual environment...
call venv\Scripts\activate.bat

echo [3/3] Installing packages...
pip install --quiet flask apscheduler requests

echo.
echo ============================================
echo INSTALLATION COMPLETED!
echo ============================================
echo.
echo Next step: Run start.bat
echo.
pause
