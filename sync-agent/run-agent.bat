@echo off
rem Run the ReelFlow sync agent. Usage: run-agent.bat [command] [options]
rem With no arguments it runs the long-running "run" command.
setlocal
cd /d "%~dp0"
set "PY=py -3"
if exist ".venv\Scripts\python.exe" set "PY=.venv\Scripts\python.exe"
if "%~1"=="" (
    %PY% -m reelflow_agent run
) else (
    %PY% -m reelflow_agent %*
)
exit /b %ERRORLEVEL%
