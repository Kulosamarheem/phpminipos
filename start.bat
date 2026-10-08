@echo off
chcp 65001 >nul 2>&1
title POS System Launcher
setlocal EnableDelayedExpansion

:: =========================================================
::  POS System — One-click Start
::  Usage:
::    start.bat              → development (default)
::    start.bat production   → production mode
:: =========================================================

set "PROJECT_DIR=%~dp0"
set "ENV_MODE=%~1"
if "%ENV_MODE%"=="" set "ENV_MODE=development"
set "PHP_PORT=8000"
set "PHP_HOST=localhost"

echo.
echo  ╔══════════════════════════════════════════╗
echo  ║       POS System — Service Launcher      ║
echo  ║                                          ║
echo  ║   Mode : %ENV_MODE%
echo  ╚══════════════════════════════════════════╝
echo.

:: ---------------------------------------------------------
:: 1. Check PHP
:: ---------------------------------------------------------
echo [1/4] Checking PHP...
where php >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo   ✗ PHP not found in PATH.
    echo     Please install PHP or add it to your PATH.
    echo     Common locations:
    echo       C:\xampp\php
    echo       C:\wamp64\bin\php\php8.x
    echo       C:\php
    pause
    exit /b 1
)
for /f "tokens=*" %%v in ('php -r "echo PHP_VERSION;"') do set "PHP_VER=%%v"
echo   ✓ PHP %PHP_VER%

:: ---------------------------------------------------------
:: 2. Check MySQL
:: ---------------------------------------------------------
echo [2/4] Checking MySQL...

:: Try to connect
mysql -u root -e "SELECT 1;" >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo   ▸ MySQL is not running. Attempting to start...

    :: Try XAMPP
    if exist "C:\xampp\mysql\bin\mysqld.exe" (
        echo   ▸ Found XAMPP MySQL, starting...
        start "" /b "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini"
        goto :wait_mysql
    )

    :: Try WAMP
    if exist "C:\wamp64\bin\mysql" (
        echo   ▸ Found WAMP, please start WAMP manually.
        pause
        exit /b 1
    )

    :: Try Windows Service
    net start MySQL >nul 2>&1
    if %ERRORLEVEL% equ 0 (
        echo   ▸ Started MySQL Windows Service.
        goto :wait_mysql
    )
    net start MySQL80 >nul 2>&1
    if %ERRORLEVEL% equ 0 (
        echo   ▸ Started MySQL80 Windows Service.
        goto :wait_mysql
    )
    net start MariaDB >nul 2>&1
    if %ERRORLEVEL% equ 0 (
        echo   ▸ Started MariaDB Windows Service.
        goto :wait_mysql
    )

    echo   ✗ Could not start MySQL automatically.
    echo     Please start MySQL manually and re-run this script.
    pause
    exit /b 1
)
goto :mysql_ready

:wait_mysql
echo   ▸ Waiting for MySQL to be ready...
set "RETRIES=0"
:mysql_loop
if %RETRIES% geq 15 (
    echo   ✗ MySQL did not start in time.
    pause
    exit /b 1
)
timeout /t 2 /nobreak >nul
mysql -u root -e "SELECT 1;" >nul 2>&1
if %ERRORLEVEL% neq 0 (
    set /a RETRIES+=1
    goto :mysql_loop
)

:mysql_ready
echo   ✓ MySQL is running

:: ---------------------------------------------------------
:: 3. Initialize Database
:: ---------------------------------------------------------
echo [3/4] Checking database...
mysql -u root -e "USE pos_system;" >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo   ▸ Database 'pos_system' not found. Creating...
    mysql -u root < "%PROJECT_DIR%sql\schema.sql"
    if %ERRORLEVEL% neq 0 (
        echo   ✗ Failed to import schema.
        pause
        exit /b 1
    )
    echo   ✓ Database created and schema imported
) else (
    echo   ✓ Database 'pos_system' exists
)

:: ---------------------------------------------------------
:: 4. Start PHP Development Server
:: ---------------------------------------------------------
echo [4/4] Starting PHP server...

:: Kill any existing PHP server on the same port
for /f "tokens=5" %%p in ('netstat -ano ^| findstr ":%PHP_PORT% " ^| findstr "LISTENING"') do (
    taskkill /PID %%p /F >nul 2>&1
)

:: Set environment
set "APP_ENV=%ENV_MODE%"

echo.
echo  ══════════════════════════════════════════
echo   ✓ All services ready!
echo.
echo   URL  : http://%PHP_HOST%:%PHP_PORT%
echo   Mode : %ENV_MODE%
echo   DB   : pos_system (root@localhost)
echo.
echo   Press Ctrl+C to stop the server.
echo  ══════════════════════════════════════════
echo.

:: Open browser
start "" "http://%PHP_HOST%:%PHP_PORT%"

:: Start PHP built-in server (foreground — keeps window open)
php -S %PHP_HOST%:%PHP_PORT% -t "%PROJECT_DIR%."
