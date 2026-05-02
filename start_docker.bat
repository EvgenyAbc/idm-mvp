@echo off
setlocal EnableExtensions

cd /d "%~dp0"
set "ROOT_DIR=%CD%"

if not defined SQLITE_DB_PATH (
  set "SQLITE_REL=idm_docker.sqlite"
) else (
  set "SQLITE_REL=%SQLITE_DB_PATH%"
)

set "MARKER=%ROOT_DIR%\storage\.docker_first_bootstrap_done"

REM Match docker_start.sh: absolute if leading /, UNC \\, or Windows X:\
if "%SQLITE_REL:~0,2%"=="\\" goto :sql_abs
if "%SQLITE_REL:~0,1%"=="/" goto :sql_abs
if "%SQLITE_REL:~1,1%"==":" goto :sql_abs
set "SQLITE_DB=%ROOT_DIR%\storage\%SQLITE_REL%"
goto :sql_done
:sql_abs
set "SQLITE_DB=%SQLITE_REL%"
:sql_done

if "%FRESH_KEEP_LDAP_VOLUMES%"=="1" (
  echo ==^> Stopping stack (FRESH_KEEP_LDAP_VOLUMES=1: keeping named LDAP volumes)
  docker compose down
) else (
  echo ==^> Stopping stack and removing compose volumes (LDAP data reset)
  docker compose down -v
)
if errorlevel 1 (
  echo Failed to stop Docker Compose stack.
  exit /b 1
)

echo ==^> Removing bootstrap marker and Docker SQLite DB for a fresh seed
if exist "%MARKER%" del /f /q "%MARKER%"
if exist "%SQLITE_DB%" del /f /q "%SQLITE_DB%"

echo ==^> Building and starting stack (dashboard image runs npm ci during build)
docker compose up -d --build
if errorlevel 1 (
  echo Failed to start Docker Compose stack.
  exit /b 1
)

echo Docker Compose stack started.
exit /b 0
