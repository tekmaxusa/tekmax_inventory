#!/bin/sh
set -e

# Ensure runtime directories exist and are writable (bind mounts may reset ownership)
mkdir -p /var/www/html/storage/logs /var/www/html/uploads/products
chown -R www-data:www-data /var/www/html/storage /var/www/html/uploads || true
chmod -R 775 /var/www/html/storage /var/www/html/uploads || true

# Wait for MariaDB when DB_HOST is set (compose networking)
if [ -n "${DB_HOST:-}" ]; then
  echo "Waiting for database at ${DB_HOST}:${DB_PORT:-3306}..."
  i=0
  while [ "$i" -lt 60 ]; do
    if php -r '
      $h=getenv("DB_HOST")?: "db";
      $p=(int)(getenv("DB_PORT")?:3306);
      $u=getenv("DB_USER")?: "inventory";
      $w=getenv("DB_PASS")?: "";
      try { new PDO("mysql:host=$h;port=$p", $u, $w); exit(0); }
      catch (Throwable $e) { exit(1); }
    '; then
      echo "Database is ready."
      break
    fi
    i=$((i + 1))
    sleep 2
  done
fi

exec "$@"
