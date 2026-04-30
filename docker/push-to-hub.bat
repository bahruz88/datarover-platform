@echo off
REM Tags the locally-built images with your Docker Hub username and pushes them.
REM Usage:  push-to-hub.bat <docker-hub-username> [tag]
REM Example: push-to-hub.bat bahruz88 v1
REM
REM Prereq:  docker login   (run once before this script)
SETLOCAL ENABLEEXTENSIONS

IF "%~1"=="" (
    echo Usage: push-to-hub.bat ^<docker-hub-username^> [tag]
    exit /b 1
)
SET USER=%~1
SET TAG=%~2
IF "%TAG%"=="" SET TAG=latest

echo === Tagging images as %USER%/datarover-*:%TAG% ===
docker tag htdocs-apache:latest    %USER%/datarover-apache:%TAG%    || exit /b 1
docker tag htdocs-scanner:latest   %USER%/datarover-scanner:%TAG%   || exit /b 1
docker tag htdocs-schedule:latest  %USER%/datarover-schedule:%TAG%  || exit /b 1

echo.
echo === Pushing to Docker Hub ===
docker push %USER%/datarover-apache:%TAG%    || exit /b 1
docker push %USER%/datarover-scanner:%TAG%   || exit /b 1
docker push %USER%/datarover-schedule:%TAG%  || exit /b 1

echo.
echo === DONE ===
echo Images now available at:
echo   https://hub.docker.com/r/%USER%/datarover-apache
echo   https://hub.docker.com/r/%USER%/datarover-scanner
echo   https://hub.docker.com/r/%USER%/datarover-schedule
echo.
echo Update docker-compose.prod.yml's IMAGE_OWNER to '%USER%' before sharing.

ENDLOCAL
