@echo off
rem Run acceptance tests on Windows with background servers and driver cleanup

rem Clean up any stale background server instances
taskkill /FI "WINDOWTITLE eq PHP_FRONTEND_SERVER*" /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq PHP_BACKEND_SERVER*" /F >nul 2>&1
taskkill /IM msedgedriver.exe /F >nul 2>&1

set FRONT_CMD=php -S localhost:8080 -t public
echo Starting front-end server (http://localhost:8080)...
start "PHP_FRONTEND_SERVER" cmd /c "%FRONT_CMD%"

ping 127.0.0.1 -n 4 >nul

set BACKEND_CMD=php -S localhost:8081 ./public/backend-test-api/index.php
echo Starting backend server (http://localhost:8081)...
start "PHP_BACKEND_SERVER" cmd /c "%BACKEND_CMD%"

ping 127.0.0.1 -n 4 >nul

echo Starting Edge WebDriver on port 10888...
start /B "" "C:\webdriver\msedgedriver.exe" --port=10888

ping 127.0.0.1 -n 5 >nul

echo Running Codeception tests...
if "%~1"=="" (
    vendor\bin\codecept run Acceptance,NoJs
) else (
    vendor\bin\codecept run %*
)
set TEST_EXIT=%ERRORLEVEL%

echo Stopping WebDriver...
taskkill /IM msedgedriver.exe /F >nul 2>&1

echo Stopping front-end server...
taskkill /FI "WINDOWTITLE eq PHP_FRONTEND_SERVER*" /F >nul 2>&1

echo Stopping backend server...
taskkill /FI "WINDOWTITLE eq PHP_BACKEND_SERVER*" /F >nul 2>&1

exit /b %TEST_EXIT%
