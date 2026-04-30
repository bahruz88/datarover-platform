@echo off
REM Re-dump current XAMPP MySQL `datarover` database into docker/db-init/.
REM The Docker MySQL container runs every .sql in /docker-entrypoint-initdb.d
REM ON FIRST START ONLY (when the volume is empty). For an already-running
REM stack, drop the volume first:  docker compose down -v && docker compose up -d
SETLOCAL
SET MYSQLDUMP=c:\xampp\mysql\bin\mysqldump.exe
SET OUT=%~dp0db-init\01-datarover.sql

IF NOT EXIST "%MYSQLDUMP%" (
    echo mysqldump not found at %MYSQLDUMP%
    exit /b 1
)

echo Dumping XAMPP MySQL `datarover` to %OUT%
"%MYSQLDUMP%" -u root --default-character-set=utf8mb4 --skip-extended-insert --hex-blob --single-transaction --add-drop-database --databases datarover > "%OUT%"
IF %ERRORLEVEL% NEQ 0 (
    echo mysqldump failed. Is XAMPP MySQL running?
    exit /b 1
)

echo Done. Size:
dir "%OUT%" | findstr 01-datarover.sql
