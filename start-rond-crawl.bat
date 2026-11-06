@echo off
setlocal EnableExtensions
chcp 65001 >nul
set "ROOT=%~dp0"
cd /d "%ROOT%"

echo.
echo === Rondino full rond.ir inventory crawl ===
echo.

where php >nul 2>&1
if errorlevel 1 (
  echo [ERROR] php was not found in PATH.
  pause
  exit /b 1
)

if not exist "%ROOT%config\local.php" (
  echo [ERROR] config\local.php missing. Run start.bat first-run setup.
  pause
  exit /b 1
)

if not exist "%ROOT%storage\logs" mkdir "%ROOT%storage\logs" >nul 2>&1
if exist "%ROOT%storage\logs\crawl-worker.stop" del /f /q "%ROOT%storage\logs\crawl-worker.stop" >nul 2>&1

REM Stop background multi-source worker so it cannot contend with this dedicated crawl.
if exist "%ROOT%storage\logs\crawl-worker.pid" (
  echo Stopping general crawl-worker to avoid lock contention...
  echo. > "%ROOT%storage\logs\crawl-worker.stop"
  timeout /t 3 /nobreak >nul
  del /f /q "%ROOT%storage\logs\crawl-worker.stop" >nul 2>&1
)

echo Logging to storage\logs\crawl-rond-full.log
echo Resume by default. Pass --restart to wipe the current pass cursor.
echo Press Ctrl+C to stop; progress is checkpointed in MySQL.
echo.

php "%ROOT%scripts\crawl-rond.php" %*
set "ERR=%ERRORLEVEL%"
echo.
if not "%ERR%"=="0" (
  echo Crawl exited with code %ERR%. See storage\logs\crawl-rond-full.log
) else (
  echo Crawl finished successfully.
)
pause
endlocal
exit /b %ERR%
