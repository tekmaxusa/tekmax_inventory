@echo off
REM Deploy Inventory System with Docker Desktop (Windows)
setlocal
cd /d "%~dp0"

where docker >nul 2>&1
if errorlevel 1 (
  echo Docker is not installed. Install Docker Desktop first:
  echo   https://www.docker.com/products/docker-desktop/
  exit /b 1
)

if not exist .env (
  copy .env.example .env >nul
  echo Created .env from .env.example
  echo Edit .env ^(set DB_PASS, DB_ROOT_PASSWORD, APP_URL^) then run deploy.bat again.
  exit /b 1
)

echo Building and starting containers...
docker compose up -d --build
if errorlevel 1 exit /b 1

echo.
echo App is starting. Open the APP_URL from your .env ^(default http://localhost:8080^)
echo Login: admin@example.com / admin123  — CHANGE IMMEDIATELY
echo.
echo Useful: docker compose logs -f app
endlocal
