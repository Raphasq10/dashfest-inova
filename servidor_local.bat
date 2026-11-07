@echo off
title Servidor Local - Festival Iguassu Inova 2026
color 0b
echo ======================================================================
echo    FESTIVAL IGUASSU INOVA 2026 - DASHBOARD EXECUTIVO LOCAL
echo ======================================================================
echo.
echo [*] Configurando ambiente local (Porta: 8085 - evitando conflito com 3000)...
echo.

set PORT=8085

:: Tenta usar o PHP do XAMPP para suporte completo as APIs (api_meta.php, sync_yazo.php)
if exist "C:\xampp\php\php.exe" (
    echo [OK] PHP do XAMPP encontrado. Iniciando servidor PHP integrado...
    echo [*] Acessivel em: http://localhost:%PORT%
    echo [*] Pressione CTRL + C para encerrar o servidor.
    start "" http://localhost:%PORT%
    "C:\xampp\php\php.exe" -S localhost:%PORT%
    goto end
)

:: Caso nao ache no caminho padrao do XAMPP, tenta o comando php no PATH
where php >nul 2>nul
if %ERRORLEVEL% equ 0 (
    echo [OK] PHP encontrado no PATH do sistema. Iniciando servidor PHP...
    echo [*] Acessivel em: http://localhost:%PORT%
    start "" http://localhost:%PORT%
    php -S localhost:%PORT%
    goto end
)

:: Fallback para servidor HTTP do Python
where python >nul 2>nul
if %ERRORLEVEL% equ 0 (
    echo [AVISO] PHP nao localizado no PATH. Usando servidor Python (somente arquivos estaticos)...
    echo [*] Acessivel em: http://localhost:%PORT%
    start "" http://localhost:%PORT%
    python -m http.server %PORT%
    goto end
)

echo [ERRO] Nao foi possivel encontrar PHP nem Python instalados para iniciar o servidor.
echo Abrindo o arquivo index.html diretamente no navegador...
start "" "index.html"

:end
pause
