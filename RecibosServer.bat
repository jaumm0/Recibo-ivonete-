@echo off
REM Sobe o gerador de recibos em http://0.0.0.0:8000
REM Usa o PHP embutido na pasta php\ do projeto (com extensao zip ativa).
REM Libera a porta 8000 no Firewall do Windows automaticamente (regra
REM "Recibos PHP Server" - se ja existir, atualiza; se nao, cria).
REM Deixa a janela aberta mostrando os requests em tempo real.
title Gerador de Recibos - http://localhost:8000
cd /d "%~dp0"
set "PHP_EXE=%~dp0php\php.exe"

if not exist "%PHP_EXE%" (
  echo [ERRO] PHP embutido nao encontrado em: %PHP_EXE%
  echo        Verifique se a pasta php\ existe junto deste .bat.
  pause
  exit /b 1
)

REM --- Libera a porta 8000 no Firewall do Windows ---
echo  Liberando porta 8000 no Firewall do Windows...
netsh advfirewall firewall delete rule name="Recibos PHP Server" >nul 2>&1
netsh advfirewall firewall add rule name="Recibos PHP Server" dir=in action=allow protocol=TCP localport=8000 profile=private,domain >nul 2>&1
if %errorlevel%==0 (
    echo  [OK] Regra de firewall criada/atualizada.
) else (
    echo  [AVISO] Nao foi possivel criar a regra de firewall.
    echo          Execute este .bat como Administrador, ou libere a porta
    echo          manualmente em "Windows Defender Firewall com Seguranca Avancada".
)

REM --- Detecta o IP da maquina para mostrar na mensagem ---
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4"') do (
    set "MEU_IP=%%a"
)
set "MEU_IP=%MEU_IP: =%"

echo.
echo  Servidor de recibos escutando em http://0.0.0.0:8000
echo  Acesse deste PC:        http://127.0.0.1:8000/
echo  Acesse de outro PC:     http://%MEU_IP%:8000/
echo  Feche esta janela ou Ctrl+C para parar.
echo.
"%PHP_EXE%" -S 0.0.0.0:8000 -t .
pause