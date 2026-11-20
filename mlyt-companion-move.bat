@echo off
chcp 65001 >nul
title Biolab Portal - Kitle birlikte listesini receteye tasima
REM Her zaman bu dosyanin bulundugu klasorde (proje koku) calis
cd /d "%~dp0"

REM DIKKAT: Bu dosyaya Turkce karakter YAZMAYIN (yalnizca ASCII).
set PHP=C:\UsePrograms\InetSrv\php-8.5.11\php.exe
if exist "C:\PHP\8.5\php.exe" set PHP=C:\PHP\8.5\php.exe
set PHP=%PHP% -d display_errors=1

:menu
echo.
echo ==========================================
echo   KITLE BIRLIKTE LISTESI - RECETEYE TASIMA
echo   PHP: %PHP%
echo ==========================================
echo   1. Deneme (rapor yazar, veritabanina YAZMAZ)
echo   2. Uygula (yalniz hicbir testin maliyeti degismiyorsa yazar)
echo   0. Cikis
echo ==========================================
echo   Once 1 ile deneyin; son satirda "maliyetler ayni kaliyor" gorunuyorsa 2.
set SECIM=
set /p SECIM=Secim:
if defined SECIM set SECIM=%SECIM: =%

if "%SECIM%"=="1" %PHP% mlyt-companion-move.php
if "%SECIM%"=="2" %PHP% mlyt-companion-move.php --apply
if "%SECIM%"=="0" exit /b
if "%SECIM%"=="" exit /b

pause
goto menu
