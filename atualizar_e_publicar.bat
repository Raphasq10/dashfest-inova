@echo off
chcp 65001 > nul
title Festival Iguassu Inova 2026 - Sincronizacao de Dados

echo =====================================================================
echo    FESTIVAL IGUASSU INOVA 2026 - SINCRONIZADOR AO VIVO
echo    Puxa dados da Yazo, GA4 e Meta Ads e publica no GitHub Pages
echo =====================================================================
echo.

set "PHP_BIN=C:\xampp\php\php.exe"
if not exist "%PHP_BIN%" (
    echo [AVISO] C:\xampp\php\php.exe nao encontrado. Tentando 'php' do PATH...
    set "PHP_BIN=php"
)

cd /d "%~dp0"

echo [1/3] Consultando API Yazo Tickets (todas as inscricoes)...
"%PHP_BIN%" api_yazo.php > nul
if errorlevel 1 (
    echo [ERRO] Falha ao coletar dados da Yazo API.
) else (
    echo [OK] Dados da Yazo atualizados com sucesso! (yazo_operacao_data.js)
)
echo.

echo [2/3] Consultando Google Analytics 4 (Propriedade 466940299)...
"%PHP_BIN%" api_ga4.php > nul
if errorlevel 1 (
    echo [ERRO] Falha ao coletar dados do GA4.
) else (
    echo [OK] Dados do GA4 atualizados com sucesso! (ga4_operacao_data.js)
)
echo.

echo [3/3] Consultando Meta Marketing API v21.0 (Conta act_140031283)...
"%PHP_BIN%" api_meta.php > nul
if errorlevel 1 (
    echo [ERRO] Falha ao coletar dados da Meta API.
) else (
    echo [OK] Dados da Meta atualizados com sucesso! (meta_data.js)
)
echo.

echo ---------------------------------------------------------------------
echo Enviando snapshots atualizados para o GitHub Pages...
echo ---------------------------------------------------------------------

git add yazo_operacao_data.js ga4_operacao_data.js meta_data.js
git diff --cached --quiet
if errorlevel 1 (
    git commit -m "chore(sync): sincronizacao ao vivo via script local"
    git push origin main
    if errorlevel 1 (
        echo.
        echo [AVISO] Houve um problema ao fazer o push para o GitHub. Verifique sua conexao.
    ) else (
        echo.
        echo =====================================================================
        echo  SINCRONIZACAO CONCLUIDA COM SUCESSO!
        echo  O GitHub Pages sera atualizado em cerca de 30 a 60 segundos:
        echo  - War Room: https://raphasq10.github.io/dashfest-inova/operacao.html
        echo  - Home:     https://raphasq10.github.io/dashfest-inova/index.html
        echo  - Meta Ads: https://raphasq10.github.io/dashfest-inova/meta_marketing.html
        echo =====================================================================
    )
) else (
    echo.
    echo [INFO] Os dados estao 100%% atualizados e sincronizados com o GitHub!
)

echo.
pause
