@echo off
setlocal

set "DEVTOOLS_DIR=D:\Program\soft\wechattools"
set "CLI=%DEVTOOLS_DIR%\cli.bat"
set "PROJECT=D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\unpackage\dist\dev\mp-weixin"
set "UPLOAD_LOG=%TEMP%\wechat-upload-%RANDOM%.log"
set "PROJECT_PORT_KEY=yoshop2.0-mp-weixin"
set "LAST_PORT_FILE=%TEMP%\wechat-devtools-last-port-%PROJECT_PORT_KEY%.txt"
set "UPLOAD_VERSION=%~1"
set "UPLOAD_DESC=%~2"
set "INFO_OUTPUT=%~3"
set "DEVTOOLS_PORT=%~4"
set "DEVTOOLS_BRIDGE_PORT=%~5"
if not defined UPLOAD_VERSION set "UPLOAD_VERSION=%WECHAT_UPLOAD_VERSION%"
if not defined UPLOAD_DESC set "UPLOAD_DESC=%WECHAT_UPLOAD_DESC%"
if not defined INFO_OUTPUT set "INFO_OUTPUT=%WECHAT_UPLOAD_INFO_OUTPUT%"
if not defined DEVTOOLS_PORT set "DEVTOOLS_PORT=%WECHAT_DEVTOOLS_PORT%"
if not defined DEVTOOLS_PORT if exist "%LAST_PORT_FILE%" set /p DEVTOOLS_PORT=<"%LAST_PORT_FILE%"
if not defined DEVTOOLS_BRIDGE_PORT set "DEVTOOLS_BRIDGE_PORT=%WECHAT_DEVTOOLS_BRIDGE_PORT%"
if not defined DEVTOOLS_BRIDGE_PORT set "DEVTOOLS_BRIDGE_PORT=3909"
set "WECHAT_DEVTOOLS_BRIDGE_PORT=%DEVTOOLS_BRIDGE_PORT%"
if defined UPLOAD_VERSION set "UPLOAD_VERSION=%UPLOAD_VERSION:\=%"
if defined UPLOAD_VERSION set "UPLOAD_VERSION=%UPLOAD_VERSION:"=%"
if defined UPLOAD_DESC set "UPLOAD_DESC=%UPLOAD_DESC:\=%"
if defined UPLOAD_DESC set "UPLOAD_DESC=%UPLOAD_DESC:"=%"
set "CLI_PORT_ARG="

if defined DEVTOOLS_PORT (
  set "CLI_PORT_ARG= --port %DEVTOOLS_PORT%"
)

if not defined UPLOAD_VERSION (
  for /f %%i in ('powershell -NoProfile -Command "(Get-Date).ToString('yyyy.MMdd.HHmm')"') do set "UPLOAD_VERSION=vp-sandbox-%%i"
)

if not defined UPLOAD_DESC (
  for /f "delims=" %%i in ('powershell -NoProfile -Command "'virtual-payment sandbox experience upload ' + (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')"') do set "UPLOAD_DESC=%%i"
)

if not defined INFO_OUTPUT (
  set "INFO_OUTPUT=%TEMP%\wechat-upload-info.json"
)

if not exist "%CLI%" (
  echo Could not find WeChat DevTools CLI:
  echo %CLI%
  exit /b 1
)

echo [1/3] Uploading mini program as an experience build...
echo       Project : %PROJECT%
echo       Version : %UPLOAD_VERSION%
echo       Desc    : %UPLOAD_DESC%
if defined DEVTOOLS_PORT echo       Port    : %DEVTOOLS_PORT%
if defined DEVTOOLS_BRIDGE_PORT echo       Bridge  : %DEVTOOLS_BRIDGE_PORT%
call "%CLI%"%CLI_PORT_ARG% upload --project "%PROJECT%" --version "%UPLOAD_VERSION%" --desc "%UPLOAD_DESC%" --info-output "%INFO_OUTPUT%" >"%UPLOAD_LOG%" 2>&1
set "CLI_EXIT_CODE=%ERRORLEVEL%"
if exist "%UPLOAD_LOG%" type "%UPLOAD_LOG%"
findstr /C:"#initialize-error" "%UPLOAD_LOG%" >nul 2>&1
if not errorlevel 1 (
  echo Upload aborted because WeChat DevTools CLI reported initialize-error.
  echo Please manually open DevTools once, then retry this upload helper or upload from the DevTools UI.
  if exist "%UPLOAD_LOG%" del /q "%UPLOAD_LOG%" >nul 2>&1
  exit /b 1
)
if not "%CLI_EXIT_CODE%"=="0" (
  if exist "%UPLOAD_LOG%" del /q "%UPLOAD_LOG%" >nul 2>&1
  exit /b %CLI_EXIT_CODE%
)

echo [2/3] Upload completed. CLI info output:
echo       %INFO_OUTPUT%
if exist "%INFO_OUTPUT%" type "%INFO_OUTPUT%"
if exist "%UPLOAD_LOG%" del /q "%UPLOAD_LOG%" >nul 2>&1

echo [3/3] Next steps:
echo   1. Open the mini program admin / DevTools experience-member panel.
echo   2. Use the uploaded experience build QR instead of the live preview QR.
echo   3. Retry the Android sandbox virtual payment with the clean account.

endlocal
