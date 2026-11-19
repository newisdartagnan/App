@echo off
rem Monkole - Conversion CSV -> XLSX sans perte : double-cliquer sur ce fichier.
rem Deposer les extractions .csv brutes d Evolucare (sans les renommer) dans le dossier "entrees_csv".
rem Les .xlsx sont ecrits dans "entrees", prets pour LANCER_MONKOLE.bat et LANCER_DIAGNOSTICS.bat.
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

%PY% convertir_csv.py
echo.
pause
