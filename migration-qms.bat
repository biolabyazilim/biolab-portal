@echo off
chcp 65001 >nul
title Biolab QMS - Doctrine Migration
REM Her zaman bu dosyanin bulundugu klasorde (proje koku) calis
cd /d "%~dp0"

REM PHP 8.5: sunucuda (IIS, C:\inetpub\biolab-portal) C:\PHP\8.5; gelistirmede Apache'nin PHP'si (Herd degil)
set PHP=C:\UsePrograms\InetSrv\php-8.5.11\php.exe
if exist "C:\PHP\8.5\php.exe" set PHP=C:\PHP\8.5\php.exe
REM Sunucuda php.ini display_errors=Off; komut satirinda hatalar gorunsun
REM Portal (Biolab_PORTAL) icin migration.bat; bu dosya Biolab_QMS veritabanina calisir
set DM=%PHP% -d display_errors=1 qms-migrations.php

:menu
echo.
echo ==========================================
echo   BIOLAB QMS - MIGRATION  (Biolab_QMS)
echo   PHP: %PHP%
echo ==========================================
echo   1. Durum (status)
echo   2. Fark uret (diff)        - entity degisince (bekleyen migration varsa calismaz)
echo   3. SQL onizleme (dry-run)  - DB'ye dokunmaz
echo   4. Uygula (migrate)
echo   5. Son migration'i geri al (prev)
echo   6. Liste (list)
echo   7. Bos migration olustur (generate)
echo   8. composer dump-autoload
echo   0. Cikis
echo ==========================================
set SECIM=
set /p SECIM=Secim:
if defined SECIM set SECIM=%SECIM: =%

if "%SECIM%"=="1" %DM% status
if "%SECIM%"=="2" call :diff
if "%SECIM%"=="3" %DM% migrate --dry-run
if "%SECIM%"=="4" %DM% migrate
if "%SECIM%"=="5" %DM% migrate prev
if "%SECIM%"=="6" %DM% list
if "%SECIM%"=="7" %DM% generate
if "%SECIM%"=="8" composer dump-autoload
if "%SECIM%"=="0" exit /b
if "%SECIM%"=="" exit /b

pause
goto menu

:diff
REM Bekleyen (uygulanmamis) migration varken diff, ayni degisikligi ikinci kez uretir. Once bekleyenler uygulanmali.
%DM% up-to-date --list-migrations
if errorlevel 1 (
    echo.
    echo   UYARI: Bekleyen migration var ya da durum okunamadi. Diff CALISTIRILMADI.
    echo   Once 3 ^(dry-run^) ve 4 ^(migrate^) ile bekleyenleri uygulayin.
    exit /b
)
%DM% diff
exit /b
