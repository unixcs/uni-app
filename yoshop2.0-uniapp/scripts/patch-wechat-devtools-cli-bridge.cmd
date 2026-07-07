@echo off
setlocal

set "DEVTOOLS_DIR=D:\Program\soft\wechattools"
set "CLI_BUNDLE=%DEVTOOLS_DIR%\code\package.nw\js\common\cli\index.js"
set "PATCH_PORT=%~1"
if not defined PATCH_PORT set "PATCH_PORT=%WECHAT_DEVTOOLS_BRIDGE_PORT%"
if not defined PATCH_PORT set "PATCH_PORT=3909"
set "BACKUP_PATH=%CLI_BUNDLE%.codex-backup-20260703"

if not exist "%CLI_BUNDLE%" (
  echo Could not find WeChat DevTools CLI bundle:
  echo %CLI_BUNDLE%
  exit /b 1
)

echo [1/2] Patching WeChat DevTools CLI bridge port...
echo       File   : %CLI_BUNDLE%
echo       Backup : %BACKUP_PATH%
echo       Port   : %PATCH_PORT%

powershell -NoProfile -Command "$file='%CLI_BUNDLE%'; $backup='%BACKUP_PATH%'; $patchPort='%PATCH_PORT%'; $utf8NoBom=New-Object System.Text.UTF8Encoding($false); $content=[System.IO.File]::ReadAllText($file); if (-not (Test-Path $backup)) { [System.IO.File]::WriteAllText($backup, $content, $utf8NoBom) }; $replacement=('let j=Number(process.env.WECHAT_DEVTOOLS_BRIDGE_PORT||{0});Number.isFinite(j)&&j>0||(j={0});' -f $patchPort); if ($content.Contains('WECHAT_DEVTOOLS_BRIDGE_PORT')) { $content=[System.Text.RegularExpressions.Regex]::Replace($content, 'let j=Number\(process\.env\.WECHAT_DEVTOOLS_BRIDGE_PORT\|\|\d+\);Number\.isFinite\(j\)&&j>0\|\|\(j=\d+\);', $replacement, 1) } elseif ($content.Contains('let j=3799;')) { $content=$content.Replace('let j=3799;', $replacement) } else { throw 'Could not find CLI bridge-port pattern to patch.' }; [System.IO.File]::WriteAllText($file, $content, $utf8NoBom); $verify=[System.IO.File]::ReadAllText($file); if (-not $verify.Contains('WECHAT_DEVTOOLS_BRIDGE_PORT')) { throw 'Patch verification failed.' }; $idx=$verify.IndexOf('WECHAT_DEVTOOLS_BRIDGE_PORT'); $start=[Math]::Max(0, $idx - 80); $length=[Math]::Min(220, $verify.Length - $start); $verify.Substring($start, $length)"
if errorlevel 1 exit /b 1

echo [2/2] Patch complete.
echo       The CLI bridge port now defaults to %PATCH_PORT%.
echo       Next: rerun reset-wechat-devtools-manual.cmd or upload-wechat-experience.cmd.

endlocal
