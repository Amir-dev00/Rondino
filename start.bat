@echo off
setlocal EnableExtensions
chcp 65001 >nul

set "ROOT=%~dp0"
cd /d "%ROOT%"

echo.
echo === Rondino Simple ===
echo.

where php >nul 2>&1
if errorlevel 1 (
  echo [ERROR] php was not found in PATH.
  echo Install PHP 8.2+ , add it to PATH, then run start.bat again.
  echo.
  pause
  exit /b 1
)

php "%ROOT%scripts\check-prereqs.php"
if errorlevel 1 (
  echo.
  pause
  exit /b 1
)

if not exist "%ROOT%config\local.php" (
  echo First run: database and team admin setup
  echo.
  php "%ROOT%scripts\first-run.php"
  if errorlevel 1 (
    echo.
    pause
    exit /b 1
  )
  echo.
)

for /f "usebackq delims=" %%P in (`php "%ROOT%scripts\pick-port.php"`) do set "PORT=%%P"
if not defined PORT (
  echo [ERROR] No free port found.
  pause
  exit /b 1
)

echo Local server: http://127.0.0.1:%PORT%
echo Crawl worker starts in the background.
echo setaresim is disabled from active collection.
echo rond.ir uses local Playwright helper when Node is installed.
where node >nul 2>&1
if errorlevel 1 (
  echo [hint] Optional for rond.ir: install Node.js then run:
  echo   cd scripts ^&^& npm install ^&^& npx playwright install chromium
) else (
  if not exist "%ROOT%scripts\node_modules\playwright" (
    echo [hint] Enable rond.ir helper: cd scripts ^&^& npm install ^&^& npx playwright install chromium
  )
)
echo Press Ctrl+C to stop.
echo.

if not exist "%ROOT%storage\logs" mkdir "%ROOT%storage\logs" >nul 2>&1
if exist "%ROOT%storage\logs\crawl-worker.stop" del /f /q "%ROOT%storage\logs\crawl-worker.stop" >nul 2>&1
start "Rondino Crawl Worker" /MIN php "%ROOT%scripts\crawl-worker.php"

start "" "http://127.0.0.1:%PORT%/"
php -S 127.0.0.1:%PORT% -t "%ROOT%public"
set "ERR=%ERRORLEVEL%"

echo. > "%ROOT%storage\logs\crawl-worker.stop"
echo.
if not "%ERR%"=="0" (
  echo [ERROR] PHP server stopped with code %ERR%.
  echo Check that MySQL is running and the port is free.
)
echo.
pause
endlocal
exit /b %ERR%
