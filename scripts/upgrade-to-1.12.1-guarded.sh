#!/usr/bin/env bash
set -euo pipefail

PANEL_DIR="/var/www/pterodactyl"
RELEASE_TAR="${PANEL_DIR}/panel.tar.gz"
TS="$(date -u +%Y%m%d-%H%M%S)"
BACKUP_DIR="${PANEL_DIR}/backups/upgrade-1.12.1-${TS}"
STAGE_DIR="/tmp/pterodactyl-1.12.1-stage-${TS}"

if [[ ! -f "${RELEASE_TAR}" ]]; then
    echo "[ERROR] Missing release archive: ${RELEASE_TAR}" >&2
    exit 1
fi

if [[ "${EUID}" -ne 0 ]]; then
    echo "[ERROR] Run this script as root." >&2
    exit 1
fi

mkdir -p "${BACKUP_DIR}"/{database,panel,proxy,meta}

echo "[1/10] Writing metadata..."
{
    echo "created_at_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "panel_dir=${PANEL_DIR}"
    echo "release_tar=${RELEASE_TAR}"
} > "${BACKUP_DIR}/meta/info.txt"

echo "[2/10] Reading database credentials..."
cd "${PANEL_DIR}"
DB_HOST="$(grep '^DB_HOST=' .env | cut -d= -f2-)"
DB_PORT="$(grep '^DB_PORT=' .env | cut -d= -f2-)"
DB_NAME="$(grep '^DB_DATABASE=' .env | cut -d= -f2-)"
DB_USER="$(grep '^DB_USERNAME=' .env | cut -d= -f2-)"
DB_PASS="$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)"

echo "[3/10] Creating database backup..."
mysqldump \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    -h "${DB_HOST}" \
    -P "${DB_PORT}" \
    -u "${DB_USER}" \
    --password="${DB_PASS}" \
    "${DB_NAME}" > "${BACKUP_DIR}/database/panel.sql"
gzip -f "${BACKUP_DIR}/database/panel.sql"

echo "[4/10] Creating full panel filesystem backup..."
tar \
    --exclude='pterodactyl/backups/upgrade-1.12.1-*' \
    --exclude='pterodactyl/backups/pre-upgrade-*' \
    --exclude='pterodactyl/backups/pre-upgrade-files-*' \
    --exclude='pterodactyl/storage/logs/*.log' \
    -I 'zstd -10 -T0' \
    -cf "${BACKUP_DIR}/panel/pterodactyl-files.tar.zst" \
    -C /var/www pterodactyl

echo "[5/10] Backing up websocket and reverse proxy config..."
cp -a /etc/nginx/sites-enabled/pterodactyl.conf "${BACKUP_DIR}/proxy/nginx-sites-enabled-pterodactyl.conf" 2>/dev/null || true
cp -a /etc/nginx/sites-available/pterodactyl.conf "${BACKUP_DIR}/proxy/nginx-sites-available-pterodactyl.conf" 2>/dev/null || true
cp -a /etc/systemd/system/pterodactyl-external-ws-proxy.service "${BACKUP_DIR}/proxy/pterodactyl-external-ws-proxy.service" 2>/dev/null || true
cp -a "${PANEL_DIR}/scripts/external-websocket-proxy.js" "${BACKUP_DIR}/proxy/external-websocket-proxy.js" 2>/dev/null || true
cp -a /etc/pterodactyl/config.yml "${BACKUP_DIR}/proxy/wings-config.yml" 2>/dev/null || true
cp -a /etc/systemd/system/wings.service "${BACKUP_DIR}/proxy/wings.service" 2>/dev/null || true

echo "[6/10] Enabling maintenance mode..."
CACHE_DRIVER=file SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan down || true

echo "[7/10] Extracting 1.12.1 release to staging..."
rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}"
tar -xzf "${RELEASE_TAR}" -C "${STAGE_DIR}"

echo "[8/10] Syncing release files into panel (preserving local environment/custom folders)..."
rsync -a \
    --exclude='.env' \
    --exclude='backups/' \
    --exclude='.blueprint/' \
    --exclude='default-img/' \
    --exclude='public/default-img/' \
    "${STAGE_DIR}/" "${PANEL_DIR}/"

echo "[9/10] Installing dependencies and running migrations..."
cd "${PANEL_DIR}"
composer install --no-dev --optimize-autoloader --no-interaction
CACHE_DRIVER=file SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan migrate --seed --force
CACHE_DRIVER=file SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan optimize:clear

echo "[10/10] Restarting panel workers and proxy services..."
systemctl restart pteroq || true
systemctl restart redis || systemctl restart redis-server || true
systemctl restart pterodactyl-external-ws-proxy || true
systemctl reload nginx || true
CACHE_DRIVER=file SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan queue:restart || true
CACHE_DRIVER=file SESSION_DRIVER=file QUEUE_CONNECTION=sync php artisan up || true

(
    cd "${BACKUP_DIR}"
    find . -type f -print0 | sort -z | xargs -0 sha256sum > "${BACKUP_DIR}/meta/sha256sum.txt"
)

echo
echo "Upgrade complete."
echo "Backup path: ${BACKUP_DIR}"
echo
echo "If rollback is needed:"
echo "1) Restore files from ${BACKUP_DIR}/panel/pterodactyl-files.tar.zst"
echo "2) Restore DB from ${BACKUP_DIR}/database/panel.sql.gz"
echo "3) Reapply proxy config files from ${BACKUP_DIR}/proxy/"
