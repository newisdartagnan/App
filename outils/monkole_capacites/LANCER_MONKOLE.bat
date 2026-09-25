@echo off
rem Monkole - Activites et capacites : double-cliquer sur ce fichier.
rem Les six exports doivent se trouver dans le dossier "entrees".
chcp 65001 >nul
set PYTHONUTF8=1
cd /d "%~dp0"

set PY=
where py >nul 2>nul && set PY=py
if "%PY%"=="" where python >nul 2>nul && set PY=python
if "%PY%"=="" (
    echo Python est introuvable. Installez Python depuis https://www.python.org puis relancez.
    pause
    exit /b 1
)

%PY% -c "import openpyxl" >nul 2>nul
if errorlevel 1 (
    echo Installation du module openpyxl ^(une seule fois, connexion Internet necessaire^)...
    %PY% -m pip install --user openpyxl
)

%PY% monkole_capacites.py
echo.
pause
