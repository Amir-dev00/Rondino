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
  echo [خطا] دستور php در PATH پیدا نشد.
  echo PHP 8.2+ را نصب کنید و پوشه آن را به PATH اضافه کنید، سپس دوباره .\start.bat را اجرا کنید.
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
  echo اولین اجرا: راه‌اندازی پایگاه داده و مدیر تیم
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
  echo [خطا] پورت آزاد پیدا نشد.
  pause
  exit /b 1
)

echo سرور محلی روی http://127.0.0.1:%PORT%
echo کارگر خزش محلی در پس‌زمینه اجرا می‌شود (بدون ترمینال جدا).
echo setaresim از جمع‌آوری فعال خارج است.
echo rond.ir در صورت نصب Node+Playwright از helper محلی استفاده می‌کند (cPanel معمولاً ندارد).
where node >nul 2>&1
if errorlevel 1 (
  echo [راهنما] برای rond.ir اختیاری: Node.js را نصب کنید سپس:
  echo   cd scripts ^&^& npm install playwright ^&^& npx playwright install chromium
) else (
  if not exist "%ROOT%scripts\node_modules\playwright" (
    echo [راهنما] برای فعال‌سازی helper رند: cd scripts ^&^& npm install playwright ^&^& npx playwright install chromium
  )
)
echo برای توقف، Ctrl+C را بزنید.
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
  echo [خطا] سرور PHP متوقف شد با کد %ERR%.
  echo اگر MySQL خاموش است آن را روشن کنید. اگر پورت اشغال است برنامه دیگر را ببندید.
)
echo.
pause
endlocal
exit /b %ERR%
