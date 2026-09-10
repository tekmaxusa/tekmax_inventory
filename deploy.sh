#!/usr/bin/env bash
# Deploy Inventory System with Docker Compose on a Linux VPS (Ubuntu/Debian).
# Usage:  bash deploy.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed. Install Docker Engine + Compose plugin first:"
  echo "  https://docs.docker.com/engine/install/"
  exit 1
fi

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env from .env.example — edit passwords and APP_URL, then re-run."
  echo "  nano .env"
  exit 1
fi

# shellcheck disable=SC1091
set -a
# shellcheck source=/dev/null
. ./.env
set +a

if [ -z "${DB_PASS:-}" ] || [ "${DB_PASS}" = "change-me-strong-db-password" ]; then
  echo "Set a real DB_PASS in .env before deploying."
  exit 1
fi

if [ -z "${DB_ROOT_PASSWORD:-}" ] || [ "${DB_ROOT_PASSWORD}" = "change-me-strong-root-password" ]; then
  echo "Set a real DB_ROOT_PASSWORD in .env before deploying."
  exit 1
fi

echo "Building and starting containers..."
docker compose up -d --build

echo ""
echo "App is starting."
echo "  Local URL:  ${APP_URL:-http://localhost:${APP_PORT:-8080}}"
echo "  Login:      admin@example.com / admin123  (CHANGE IMMEDIATELY)"
echo ""
echo "Useful commands:"
echo "  docker compose logs -f app"
echo "  docker compose ps"
echo "  docker compose down"
