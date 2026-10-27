@echo off
cd /d "%~dp0"
set "HOST=127.0.0.1"
if /I "%~1"=="-Lan" (
    set "HOST=0.0.0.0"
    echo WARNING: LAN access exposes this server to the network. Ensure APP_DEBUG=false before continuing.
)
"C:\xampp\php\php.exe" artisan serve --host=%HOST% --port=8000
