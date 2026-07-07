@echo off
setlocal

set "DEVTOOLS_DIR=D:\Program\soft\wechattools"
set "DEVTOOLS_EXE=%DEVTOOLS_DIR%\wechatdevtools.exe"
set "CLI=%DEVTOOLS_DIR%\cli.bat"
set "PROJECT=D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\unpackage\dist\dev\mp-weixin"
set "OPEN_LOG=%TEMP%\wechat-devtools-open-%RANDOM%.log"
set "PROJECT_PORT_KEY=yoshop2.0-mp-weixin"
set "LAST_PORT_FILE=%TEMP%\wechat-devtools-last-port-%PROJECT_PORT_KEY%.txt"
set "DEVTOOLS_PORT=%~1"
set "DEVTOOLS_BRIDGE_PORT=%~2"
if not defined DEVTOOLS_PORT set "DEVTOOLS_PORT=%WECHAT_DEVTOOLS_PORT%"
if not defined DEVTOOLS_BRIDGE_PORT set "DEVTOOLS_BRIDGE_PORT=%WECHAT_DEVTOOLS_BRIDGE_PORT%"
if not defined DEVTOOLS_BRIDGE_PORT set "DEVTOOLS_BRIDGE_PORT=3909"
set "WECHAT_DEVTOOLS_BRIDGE_PORT=%DEVTOOLS_BRIDGE_PORT%"
set "CLI_PORT_ARG="
set "CLI_INIT_ERROR="
set "CLI_OPEN_SUCCESS="
set "CLI_EXIT_CODE="

if defined DEVTOOLS_PORT (
  set "CLI_PORT_ARG= --port %DEVTOOLS_PORT%"
)

echo [1/4] Stopping WeChat DevTools and stale automation processes...
taskkill /IM wechatdevtools.exe /F /T >nul 2>&1
taskkill /IM wechatwebdevtools.exe /F /T >nul 2>&1
taskkill /IM WeChatAppEx.exe /F /T >nul 2>&1
timeout /t 2 /nobreak >nul

if exist "%CLI%" (
  echo [2/4] Clearing DevTools caches for the mini program project...
  call "%CLI%"%CLI_PORT_ARG% cache --clean session --project "%PROJECT%" >nul 2>&1
  call "%CLI%"%CLI_PORT_ARG% cache --clean storage --project "%PROJECT%" >nul 2>&1
  call "%CLI%"%CLI_PORT_ARG% cache --clean network --project "%PROJECT%" >nul 2>&1
  call "%CLI%"%CLI_PORT_ARG% cache --clean compile --project "%PROJECT%" >nul 2>&1
)

echo [3/4] Re-opening WeChat DevTools...
echo       Bridge port: %DEVTOOLS_BRIDGE_PORT%
if exist "%CLI%" (
  call "%CLI%"%CLI_PORT_ARG% open --project "%PROJECT%" >"%OPEN_LOG%" 2>&1
  set "CLI_EXIT_CODE=%ERRORLEVEL%"
  if exist "%OPEN_LOG%" type "%OPEN_LOG%"
  findstr /C:"#initialize-error" "%OPEN_LOG%" >nul 2>&1
  if not errorlevel 1 set "CLI_INIT_ERROR=1"
  findstr /C:"IDE server has started" "%OPEN_LOG%" >nul 2>&1
  if not errorlevel 1 set "CLI_OPEN_SUCCESS=1"
  if defined CLI_OPEN_SUCCESS (
    for /f "delims=" %%i in ('powershell -NoProfile -Command "$text=[System.IO.File]::ReadAllText('%OPEN_LOG%'); $m=[regex]::Match($text,'listening on http://127\\.0\\.0\\.1:(\\d+)'); if($m.Success){$m.Groups[1].Value}"') do set "DEVTOOLS_PORT=%%i"
    if defined DEVTOOLS_PORT >"%LAST_PORT_FILE%" echo %DEVTOOLS_PORT%
  )
  if defined CLI_OPEN_SUCCESS if not defined CLI_INIT_ERROR goto done
  if "%CLI_EXIT_CODE%"=="0" if not defined CLI_INIT_ERROR goto done
)

if exist "%DEVTOOLS_EXE%" (
  start "" "%DEVTOOLS_EXE%"
  if defined CLI_INIT_ERROR (
    echo CLI open reported initialize-error, so the script is falling back to launching WeChat DevTools directly.
  ) else (
    echo CLI open failed. Falling back to launching WeChat DevTools directly.
  )
  echo Please open this project manually inside DevTools:
  echo %PROJECT%
  goto done
)

echo Could not find WeChat DevTools executable:
echo %DEVTOOLS_EXE%

:done
echo [4/4] Manual retry notes:
echo   1. Confirm DevTools is opened on:
echo      %PROJECT%
if defined DEVTOOLS_PORT echo   1.1 Current CLI port override: %DEVTOOLS_PORT%
if defined DEVTOOLS_BRIDGE_PORT echo   1.2 Current CLI bridge port: %DEVTOOLS_BRIDGE_PORT%
if exist "%LAST_PORT_FILE%" (
  set /p LAST_PORT=<"%LAST_PORT_FILE%"
  if defined LAST_PORT (
    echo   1.3 Last detected IDE HTTP port: %LAST_PORT%
    echo CODEX_IDE_PORT:%LAST_PORT%
  )
)
echo   2. Do not run cli auto or miniprogram-automator before the next manual payment retry.
echo   3. Retry the Android sandbox payment only after this clean reopen.
echo   4. If DevTools preview still fails, upload an experience version and retry with the experience QR instead of the live preview QR.
if exist "%OPEN_LOG%" del /q "%OPEN_LOG%" >nul 2>&1
endlocal
