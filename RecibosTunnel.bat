@echo off
REM Sobe o gerador de recibos na porta 8000 e expõe publicamente
REM via Cloudflare Tunnel (URL temporária https://xxx.trycloudflare.com).
REM NAO precisa de firewall liberado e NAO precisa de IP fixo.
REM A URL muda toda vez que rodar este .bat (túnel rápido descartável).
REM
REM Como usar:
REM   1. Execute este .bat como Administrador (clique direito -> executar
REM      como administrador). Pode ser normal também — só é necessário se
REM      o Windows bloquear o cloudflared.
REM   2. Espere aparecer a mensagem "URL publica: https://..."
REM   3. Acesse esse link de qualquer lugar (celular, outro PC, etc.)
REM   4. Para parar: feche esta janela ou Ctrl+C
title Gerador de Recibos - Cloudflare Tunnel
cd /d "%~dp0"
set "PHP_EXE=%~dp0php\php.exe"
set "TUNNEL_EXE=%~dp0cloudflared.exe"

if not exist "%PHP_EXE%" (
  echo [ERRO] PHP embutido nao encontrado em: %PHP_EXE%
  pause
  exit /b 1
)
if not exist "%TUNNEL_EXE%" (
  echo [ERRO] cloudflared.exe nao encontrado em: %TUNNEL_EXE%
  echo        Baixe de https://github.com/cloudflare/cloudflared/releases
  pause
  exit /b 1
)

echo.
echo  Iniciando servidor PHP em http://localhost:8000 ...
echo  Iniciando Cloudflare Tunnel (URL publica aparecera abaixo) ...
echo.
echo  IMPORTANTE: NAO feche esta janela enquanto estiver usando o site.
echo  Para parar, feche a janela ou Ctrl+C.
echo.

REM O 'start /B' deixa o PHP rodar em background. O cloudflared roda
REM em primeiro plano e mostra a URL publica no terminal.
start "Recibos PHP Server" /B "%PHP_EXE%" -S 127.0.0.1:8000 -t .

REM Espera o PHP subir (1.5s é folgado pra um servidor embutido).
timeout /t 2 /nobreak >nul

REM Tenta abrir o navegador localmente tambem (opcional, pode fechar).
start "" http://127.0.0.1:8000/ 2>nul

REM Sobe o tunel publico. --url http://localhost:8000 cria um tunel
REM rapido e descartavel com URL https://*.trycloudflare.com.
REM O --no-autoupdate evita que ele tente se atualizar e trave.
"%TUNNEL_EXE%" tunnel --url http://localhost:8000 --no-autoupdate

REM Quando o usuario fechar o tunel, mata tambem o PHP.
echo.
echo  Encerrando servidor PHP...
taskkill /FI "WindowTitle eq Recibos PHP Server*" /T /F >nul 2>&1
