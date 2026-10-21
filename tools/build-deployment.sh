#!/usr/bin/env bash
# Builds an upload-ready deployment package of ESS IV CRM.
#
#   tools/build-deployment.sh [output-folder]      (default: ../deployment)
#
# Output:
#   upload/ess-iv-crm/   the files to put on the web server (no demo, GitHub or dev files)
#   ess-iv-crm-<version>.zip
#   DEPLOY-GUIDE.md / DEPLOY-GUIDE.html, CHECKLIST.md, VERSION.txt, SHA256SUMS.txt
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/../deployment}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
case "$OUT" in "$ROOT"|"$ROOT"/*) echo "Choose an output folder outside the project." >&2; exit 1 ;; esac

VERSION="$(cd "$ROOT" && git describe --always --dirty 2>/dev/null || echo dev)"
DATE="$(date '+%Y-%m-%d %H:%M')"
PKG="$OUT/upload/ess-iv-crm"

echo "Building ESS IV CRM $VERSION into $OUT"
# Start from a clean package folder (only inside the chosen output folder).
if [ -d "$OUT/upload" ]; then find "$OUT/upload" -mindepth 1 -delete; fi
find "$OUT" -maxdepth 1 -name 'ess-iv-crm-*.zip' -delete
mkdir -p "$PKG"

# Copy production files only.
rsync -a "$ROOT/" "$PKG/" \
  --exclude '.git/' --exclude '.github/' --exclude '.devcontainer/' --exclude 'docs/' \
  --exclude 'scripts/' --exclude 'tools/' --exclude 'demo/' --exclude 'render.yaml' --exclude '.dockerignore' --exclude '.gitignore' --exclude '.DS_Store' \
  --exclude 'config.php' --exclude 'install.lock' --exclude '*.log' --exclude 'README.md'

# Every PHP file must parse.
if command -v php >/dev/null; then
  while IFS= read -r -d '' f; do php -l "$f" >/dev/null || { echo "PHP syntax error in $f" >&2; exit 1; }; done < <(find "$PKG" -name '*.php' -print0)
fi
# The package must never ship secrets or install state.
for bad in config.php install.lock; do [ ! -e "$PKG/$bad" ] || { echo "$bad must not be in the package" >&2; exit 1; }; done

(cd "$OUT/upload" && zip -rq "$OUT/ess-iv-crm-$VERSION.zip" ess-iv-crm)

cp "$ROOT/DEPLOY.md" "$OUT/DEPLOY-GUIDE.md"
if command -v pandoc >/dev/null; then
  pandoc "$ROOT/DEPLOY.md" -f gfm -t html5 -s --metadata title="ESS IV CRM: deployment guide" \
    -c /dev/null -H <(printf '<style>body{font:15px/1.6 system-ui,sans-serif;max-width:860px;margin:2rem auto;padding:0 16px;color:#16212b}code,pre{font:13px ui-monospace,Menlo,monospace;background:#eef1f4}pre{padding:12px;overflow-x:auto;border-radius:6px}code{padding:1px 4px;border-radius:4px}table{border-collapse:collapse;display:block;overflow-x:auto}th,td{border:1px solid #d9dfe5;padding:6px 10px;text-align:left;vertical-align:top}h1,h2,h3{line-height:1.25}h2{border-bottom:1px solid #d9dfe5;padding-bottom:4px;margin-top:2.2rem}a{color:#0f6e74}</style>') \
    -o "$OUT/DEPLOY-GUIDE.html" 2>/dev/null
fi

cat > "$OUT/CHECKLIST.md" <<EOF
# ESS IV CRM: deployment checklist (version $VERSION)

Follow DEPLOY-GUIDE (the .html opens in any browser). Tick each line as you go.

## Before you start
- [ ] Hosting runs PHP 8.1 or newer with pdo_mysql, mbstring and openssl
- [ ] MySQL database created, with a user that has ALL PRIVILEGES on it (write down name, user and password)
- [ ] Mailbox for sending alerts created, e.g. crm@yourdomain.co.za (write down its SMTP server, port and password)
- [ ] SSL certificate active for the domain (https)
- [ ] PHP setting post_max_size is 16M or more (needed to load example data)

## Upload
- [ ] Upload ess-iv-crm-$VERSION.zip and extract it, or upload the contents of upload/ess-iv-crm/
- [ ] index.php and .htaccess are directly in the CRM folder (e.g. public_html/crm/)

## Install (new server)
- [ ] Open https://yourdomain/crm/install.php and complete the form
- [ ] Alerts recipient: sean.h.petersen@gmail.com (add more addresses with commas)
- [ ] Copy the cron URL shown at the end
- [ ] Delete install.php from the server

## Upgrade (server already running an older version)
- [ ] Back up the database first
- [ ] Upload the new files over the old ones; do NOT overwrite or delete config.php
- [ ] Delete install.lock, run install.php once with the same database and admin details (it only adds what is missing), then delete install.php

## After deployment
- [ ] Add the cron job (every 10 minutes): php /home/USER/public_html/crm/cron.php
- [ ] Sign in as the administrator
- [ ] Email alerts > Send a test email > it arrives at sean.h.petersen@gmail.com (check spam)
- [ ] Users: create staff accounts and set access per area (Reservations, Room status, Maintenance, Finance, Kitchen & catering, Stock, Cleaning)
- [ ] Optional: Load example data to train staff, then Clear example data before going live
- [ ] Make a test booking, check in, check out (each sends a notification email)
- [ ] https://yourdomain/crm/app/ and /config.php are refused (403/blank), proving private files are protected
- [ ] Daily database backups switched on
EOF

{
  echo "ESS IV Accommodation CRM"
  echo "Version:  $VERSION"
  echo "Built:    $DATE"
  echo "Source:   https://github.com/cd5wtsnct4-bot/ess-iv-crm"
  echo "Requires: PHP 8.1+, MySQL 5.7+/8.x or MariaDB 10.3+"
} > "$OUT/VERSION.txt"

(cd "$OUT" && { shasum -a 256 "ess-iv-crm-$VERSION.zip"; (cd upload && find ess-iv-crm -type f -print0 | sort -z | xargs -0 shasum -a 256); } > SHA256SUMS.txt)

echo "Done:"
(cd "$OUT" && ls -1)
echo "Files in package: $(find "$PKG" -type f | wc -l | tr -d ' ')"
