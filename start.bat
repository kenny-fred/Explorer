@echo off
setlocal enabledelayedexpansion
pushd "%~dp0"
title Explorer - Lanceur
color 0A

:: =================================================================
:: 1. VERIFICATION DE PHP PORTABLE (dossier runtime)
:: =================================================================
if exist "runtime\php.exe" goto php_ready

:: Recherche d'un dossier PHP fraîchement extrait (ex: php-8.4.12-...)
set "FOUND_DIR="
for /d %%D in (*) do (
    if exist "%%D\php.exe" set "FOUND_DIR=%%D"
)

if defined FOUND_DIR (
    echo.
    echo   Dossier PHP detecte : "!FOUND_DIR!"
    echo   Configuration en cours...
    if exist "runtime" rmdir /S /Q "runtime" 2>nul
    move "!FOUND_DIR!" "runtime" >nul
    goto php_ready
)

:: =================================================================
:: 2. TELECHARGEMENT DU ZIP (Si aucun dossier n'est trouve)
:: =================================================================
if not exist "php.zip" (
    echo.
    echo   Telechargement de l'archive PHP 8.4...
    powershell -NoProfile -Command "Invoke-WebRequest -Uri 'https://windows.php.net/downloads/releases/php-8.4.27-1-Win32-vs17-x64.zip' -OutFile 'php.zip'"
    if errorlevel 1 (
        echo.
        echo [ERREUR] Echec du telechargement automatique.
        echo Telechargez manuellement l'archive a cette adresse :
        echo https://windows.php.net/downloads/releases/php-8.4.12-Win32-vs17-x64.zip
        echo Placez le fichier "php.zip" dans ce dossier et relancez.
        pause
        exit /b 1
    )
)

echo.
echo =================================================================
echo   ETAPE MANUELLE REQUISE
echo =================================================================
echo   1. L'archive "php.zip" a ete telechargee dans ce dossier.
echo   2. Faites un clic droit sur "php.zip" -^> "Extraire tout".
echo   3. Relancez ce script (launch.vbs).
echo.
echo   Le script detectera le dossier extrait et terminera
echo   l'installation automatiquement.
echo =================================================================
pause
exit /b 0

:php_ready
:: =================================================================
:: 3. CREATION DU PHP.INI
:: =================================================================
if not exist "runtime\php.ini" (
    (
        echo extension_dir = "ext"
        echo extension=php_fileinfo.dll
        echo upload_max_filesize = 10240M
        echo post_max_size = 10240M
        echo max_execution_time = 3600
        echo max_input_time = 3600
    ) > "runtime\php.ini"
)

:: Nettoyage du ZIP s'il est encore là
if exist "php.zip" del "php.zip" >nul 2>&1

:: =================================================================
:: 4. MENU
:: =================================================================
:menu
cls
echo.
echo   Explorer - LANCEUR
echo.
echo   1. Configurer un compte (.env)
echo   2. Demarrer le serveur
echo   3. Quitter
echo.
set /p choix="  Choix (1-3) : "

if "%choix%"=="1" goto setup
if "%choix%"=="2" goto start
if "%choix%"=="3" goto quit
goto menu

:setup
echo.
setlocal disabledelayedexpansion
set "uname=" & set /p uname="  Nom d'utilisateur : "
if "%uname%"=="" (echo [ERREUR] Vide. & endlocal & pause & goto menu)
set "upass=" & set /p upass="  Mot de passe : "
if "%upass%"=="" (echo [ERREUR] Vide. & endlocal & pause & goto menu)
endlocal & set "SAVED_USER=%uname%" & set "SAVED_PASS=%upass%"
echo.
echo   Generation du hash...
pushd "runtime"
php.exe -c php.ini ..\setup.php "%SAVED_USER%" "%SAVED_PASS%"
popd
echo.
pause
goto menu

:start
echo.
set "LOCAL_IP=127.0.0.1"
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
    set "IP_TEMP=%%a"
    set "IP_TEMP=!IP_TEMP:~1!"
    set "LOCAL_IP=!IP_TEMP!"
    goto :ip_found
)
:ip_found

echo   Local : http://localhost:8000
echo   Reseau: http://!LOCAL_IP!:8000
echo.
echo   Appuyez sur Ctrl+C dans cette fenetre pour arreter le serveur.
echo.

start http://localhost:8000

pushd "runtime"
php.exe -c php.ini -S 0.0.0.0:8000 -t "..\src" "..\src\router.php"
popd

echo.
echo   Le serveur a ete arrete.
pause
goto menu

:quit
popd
exit /b 0