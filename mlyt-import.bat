@echo off
chcp 65001 >nul
title Biolab Portal - Maliyet Excel aktarimi
REM Her zaman bu dosyanin bulundugu klasorde (proje koku) calis
cd /d "%~dp0"

REM DIKKAT: Bu dosyaya Turkce karakter YAZMAYIN (yalnizca ASCII). chcp 65001 acikken cmd bu satirlari
REM kaydirarak okur ("'u' is not recognized", bos yol). Varsayilan Excel yolu mlyt-import.php icinde (DEFAULT_FILE).

REM PHP 8.5: sunucuda (IIS, C:\inetpub\biolab-portal) C:\PHP\8.5; gelistirmede Apache'nin PHP'si (Herd degil)
REM Sunucuda php.ini display_errors=Off; komut satirinda hatalar gorunsun diye -d display_errors=1
set PHP=C:\UsePrograms\InetSrv\php-8.5.11\php.exe
if exist "C:\PHP\8.5\php.exe" set PHP=C:\PHP\8.5\php.exe
set PHP=%PHP% -d display_errors=1
set XLSX=
set REF=

:menu
echo.
echo ==========================================
echo   MALIYET - EXCEL AKTARIMI
echo   PHP: %PHP%
if "%XLSX%"=="" (echo   Dosya: varsayilan - Documents\Maliyet proje notlari\mlyt programi.xlsx) else (echo   Dosya: %XLSX%)
if "%REF%"=="" (echo   Referans: yok) else (echo   Referans: %REF%)
echo ==========================================
echo   1. Deneme (rapor, DB'ye yazmaz)
echo   2. Uygula (DB'ye yazar)
echo   3. Dosya yolunu degistir
echo   4. Varsayilan dosyaya don
echo   5. Fiyat aktarimi - deneme (MALZEME LISTESI, calisma no sorulur)
echo   6. Fiyat aktarimi - uygula
echo   7. Referans dosya (onceki aktarimin Excel'i, TL BIRIM MALIYET kolonlu)
echo   8. Referansi kaldir
echo   0. Cikis
echo ==========================================
echo   Fiyat aktarimi icin once 3 ile fiyatli Excel'i, 7 ile onceki Excel'i secin.
set SECIM=
set STUDY=
set /p SECIM=Secim:
if defined SECIM set SECIM=%SECIM: =%

set REFARG=
if not "%REF%"=="" set REFARG=--reference="%REF%"

if "%SECIM%"=="1" %PHP% mlyt-import.php "%XLSX%"
if "%SECIM%"=="2" %PHP% mlyt-import.php "%XLSX%" --apply
if "%SECIM%"=="3" set /p XLSX=Excel dosyasinin tam yolu:
if "%SECIM%"=="5" set /p STUDY=Calisma no (bos birakilirsa liste):
if "%SECIM%"=="5" %PHP% mlyt-import.php "%XLSX%" --prices --study=%STUDY% %REFARG%
if "%SECIM%"=="6" set /p STUDY=Calisma no:
if "%SECIM%"=="6" %PHP% mlyt-import.php "%XLSX%" --prices --study=%STUDY% %REFARG% --apply
if "%SECIM%"=="7" set /p REF=Onceki Excel dosyasinin tam yolu:
if "%SECIM%"=="8" set REF=
if "%SECIM%"=="4" set XLSX=
if "%SECIM%"=="0" exit /b
if "%SECIM%"=="" exit /b

pause
goto menu
