#!/usr/bin/env bash
# Starts the live demo: MariaDB, then Apache on $PORT, then loads example data in the background.
set -euo pipefail
APP=/var/www/html
PORT="${PORT:-8080}"
DB_PASS="${DEMO_DB_PASSWORD:-demo-db-password}"

# Apache listens on the port the host gives us (Render sets PORT).
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# MariaDB (data lives inside the container, so every restart starts fresh).
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
if [ ! -d /var/lib/mysql/mysql ]; then
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql --skip-test-db >/dev/null
fi
mariadbd --user=mysql --datadir=/var/lib/mysql &
for i in $(seq 1 60); do mariadb-admin ping --silent 2>/dev/null && break; sleep 1; done
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS ess4crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ess4crm'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ess4crm.* TO 'ess4crm'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

URL="${RENDER_EXTERNAL_URL:-${APP_URL:-http://localhost:${PORT}}}"
KEY="$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
cat > "$APP/config.php" <<PHP
<?php
// LIVE DEMO configuration (written by demo/start.sh). Emails are kept in the in-app Demo mailbox.
return [
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'ess4crm', 'user' => 'ess4crm', 'pass' => '${DB_PASS}'],
    'timezone' => 'Africa/Johannesburg',
    'app_url' => '${URL%/}/',
    'cron_key' => '${KEY}',
    'debug' => false,
    'demo' => true,
    'mail' => ['transport' => 'log', 'from' => 'crm@ess4-demo.local', 'from_name' => 'ESS IV CRM (demo)'],
];
PHP
chown www-data:www-data "$APP/config.php"
php "$APP/scripts/demo-setup.php"
date -Iseconds > "$APP/install.lock"

# Example data + the 10-minute alert job run in the background so the site is reachable straight away.
(
  node "$APP/scripts/gen-demo.js" > /tmp/demo.json && php "$APP/scripts/seed-demo.php" /tmp/demo.json
  while true; do php "$APP/cron.php" >/dev/null 2>&1 || true; sleep 600; done
) &

echo "ESS IV CRM demo on port ${PORT} (${URL})"
exec apache2-foreground
