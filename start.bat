@echo off
setlocal enabledelayedexpansion
title Explorer - Lanceur
color 0A

:: =================================================================
:: 1. VERIFICATION DE PHP
:: =================================================================
where php >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERREUR] PHP n'est pas installe ou n'est pas dans le PATH.
    echo Installez-le avec : winget install PHP.PHP.8.4
    pause
    exit /b 1
)

:: =================================================================
:: 2. VERIFICATION DU PHP.INI LOCAL
:: =================================================================
if not exist "php.ini" (
    echo [ERREUR] Le fichier php.ini local est introuvable.
    echo Veuillez le creer a la racine du projet.
    pause
    exit /b 1
)

:: Test rapide pour voir si le php.ini local charge bien fileinfo
php -c php.ini -m | findstr /i "fileinfo" >nul
if %errorlevel% neq 0 (
    echo.
    echo [ERREUR] L'extension "fileinfo" n'a pas pu etre chargee avec votre php.ini.
    echo Verifiez le chemin "extension_dir" dans le fichier php.ini.
    pause
    exit /b 1
)

:: =================================================================
:: MENU PRINCIPAL
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
if "%choix%"=="3" exit /b
goto menu

:setup
echo.
set /p username="  Nom d'utilisateur : "
set /p password="  Mot de passe : "
echo.
echo   Generation du hash...
php setup.php "%username%" "%password%"
echo.
pause
goto menu

:start
echo.
:: Extraction de l'adresse IP locale (IPv4)
set "LOCAL_IP=127.0.0.1"
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
    set "IP_TEMP=%%a"
    set "IP_TEMP=!IP_TEMP:~1!"
    set "LOCAL_IP=!IP_TEMP!"
    goto :ip_found
)
:ip_found

echo   Demarrage du serveur...
echo   Local : http://localhost:8000
echo   Reseau: http://!LOCAL_IP!:8000
echo.
echo   Appuyez sur Ctrl+C dans cette fenetre pour arreter le serveur.
echo.

:: Ouvre le navigateur par defaut
start http://localhost:8000

:: Lancement du serveur en utilisant le fichier php.ini local (-c php.ini)
php -c php.ini -S 0.0.0.0:8000 -t src src/router.php

echo.
echo   Le serveur a ete arrete.
pause
goto menu