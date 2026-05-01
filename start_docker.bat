@echo off
setlocal

docker compose up -d --build
if errorlevel 1 (
  echo Failed to start Docker Compose stack.
  exit /b 1
)

echo Docker Compose stack started.
exit /b 0
