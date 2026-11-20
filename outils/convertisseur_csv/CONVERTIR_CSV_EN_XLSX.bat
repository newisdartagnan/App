@echo off
rem Conversion CSV -> XLSX sans perte : double-cliquer sur ce fichier.
rem Deposer les fichiers .csv (ou .tsv, .txt) dans le dossier "entree" ; les .xlsx arrivent dans "sortie".
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

%PY% csv_vers_xlsx.py
echo.
pause
