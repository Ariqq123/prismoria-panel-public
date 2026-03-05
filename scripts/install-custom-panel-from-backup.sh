#!/usr/bin/env bash
set -euo pipefail

# Restores a custom Pterodactyl panel backup archive created with absolute paths
# (stored as relative paths inside tar), e.g.:
#   /root/download/pterodactyl-panel-wsproxy-config-*.tar.gz
#
# This script is intended for Debian/Ubuntu-based hosts.

PANEL_DIR="/var/www/pterodactyl"
ARCHIVE_PATH=""
CHECKSUM_PATH=""
DB_DUMP_PATH=""
INSTALL_PACKAGES=1
RUN_COMPOSER=1
RUN_MIGRATIONS=1
BUILD_ASSETS=0
REDIS_VERSION="6:8.6.1-1rl1~noble1"

usage() {
    cat <<'USAGE'
Usage:
  sudo bash scripts/install-custom-panel-from-backup.sh --archive /absolute/path/to/backup.tar.gz [options]

Required:
  --archive PATH            Path to panel backup tar.gz

Optional:
  --checksum PATH           Path to sha256 file for archive verification
  --db-dump PATH            Optional SQL dump (.sql or .sql.gz) to import after files restore
                            If omitted, script auto-detects bundled dump at /backup-meta/panel-database.sql.gz
  --panel-dir PATH          Panel directory (default: /var/www/pterodactyl)
  --redis-version VER       Redis package version (default: 6:8.6.1-1rl1~noble1)
                            Use "auto" for distro default redis.
  --skip-packages           Skip apt package installation
  --skip-composer           Skip composer install
  --skip-migrations         Skip artisan migrate --seed --force
  --build-assets            Run yarn install + yarn build:production
  --help                    Show this help text

Examples:
  sudo bash scripts/install-custom-panel-from-backup.sh \
    --archive /root/download/pterodactyl-panel-wsproxy-config-20260303-075607.tar.gz \
    --checksum /root/download/pterodactyl-panel-wsproxy-config-20260303-075607.tar.gz.sha256

  sudo bash scripts/install-custom-panel-from-backup.sh \
    --archive /root/download/pterodactyl-panel-wsproxy-config-20260303-075607.tar.gz \
    --db-dump /root/download/panel.sql.gz
USAGE
}

log() {
    printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

fail() {
    echo "[ERROR] $*" >&2
    exit 1
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

read_env_value() {
    local key="$1"
    local env_file="$2"
    local value
    value="$(grep -E "^${key}=" "${env_file}" | tail -n1 | cut -d'=' -f2- || true)"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "${value}"
}

ensure_redis_repo_for_pinned_version() {
    local codename
    codename="$(
        if [[ -f /etc/os-release ]]; then
            # shellcheck disable=SC1091
            source /etc/os-release
            echo "${VERSION_CODENAME:-}"
        fi
    )"
    if [[ -z "${codename}" ]] && command_exists lsb_release; then
        codename="$(lsb_release -cs)"
    fi
    [[ -n "${codename}" ]] || fail "Unable to detect distro codename for Redis repository setup."

    log "Adding Redis upstream apt repository (codename: ${codename}) ..."
    apt-get install -y ca-certificates curl gnupg lsb-release apt-transport-https
    install -d -m 0755 /usr/share/keyrings
    curl -fsSL https://packages.redis.io/gpg | gpg --dearmor -o /usr/share/keyrings/redis-archive-keyring.gpg
    echo "deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb ${codename} main" > /etc/apt/sources.list.d/redis.list
    apt-get update -y
}

install_redis_packages() {
    if [[ "${REDIS_VERSION}" == "auto" ]]; then
        log "Installing distro default redis-server and redis-tools ..."
        apt-get install -y redis-server redis-tools
        return 0
    fi

    if ! apt-cache madison redis-server | awk '{print $3}' | rg -qx "${REDIS_VERSION}"; then
        ensure_redis_repo_for_pinned_version
    fi

    if ! apt-cache madison redis-server | awk '{print $3}' | rg -qx "${REDIS_VERSION}"; then
        fail "Requested Redis version ${REDIS_VERSION} not available. Use --redis-version auto or a valid apt version."
    fi

    log "Installing pinned Redis version ${REDIS_VERSION} ..."
    apt-get install -y "redis-server=${REDIS_VERSION}" "redis-tools=${REDIS_VERSION}"
    apt-mark hold redis-server redis-tools >/dev/null 2>&1 || true
}

install_base_packages() {
    log "Installing base packages..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y \
        ca-certificates curl tar gzip unzip rsync jq \
        nginx mariadb-client \
        php8.3 php8.3-cli php8.3-fpm php8.3-redis php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip

    install_redis_packages

    if ! command_exists composer; then
        apt-get install -y composer || true
    fi
}

restart_if_present() {
    local service="$1"
    if systemctl list-unit-files --type=service | awk '{print $1}' | rg -qx "${service}.service"; then
        systemctl enable "${service}" >/dev/null 2>&1 || true
        systemctl restart "${service}" >/dev/null 2>&1 || true
    fi
}

sanitize_redis_config() {
    local redis_conf="/etc/redis/redis.conf"
    [[ -f "${redis_conf}" ]] || return 0

    if rg -q '^[[:space:]]*locale-(collate|ctype)[[:space:]]' "${redis_conf}"; then
        log "Sanitizing invalid Redis locale directives in ${redis_conf} ..."
        sed -i -E '/^[[:space:]]*locale-(collate|ctype)[[:space:]]/d' "${redis_conf}"
    fi

    if rg -q '^[[:space:]]*set-max-listpack-(entries|value)[[:space:]]' "${redis_conf}"; then
        log "Sanitizing unsupported Redis set-max-listpack directives in ${redis_conf} ..."
        sed -i -E '/^[[:space:]]*set-max-listpack-(entries|value)[[:space:]]/d' "${redis_conf}"
    fi
}

import_database_dump() {
    local dump_path="$1"
    local env_file="${PANEL_DIR}/.env"

    [[ -f "${dump_path}" ]] || fail "DB dump not found: ${dump_path}"
    [[ -f "${env_file}" ]] || fail "Cannot import DB dump: .env missing in ${PANEL_DIR}"

    local db_host db_port db_name db_user db_pass
    db_host="$(read_env_value DB_HOST "${env_file}")"
    db_port="$(read_env_value DB_PORT "${env_file}")"
    db_name="$(read_env_value DB_DATABASE "${env_file}")"
    db_user="$(read_env_value DB_USERNAME "${env_file}")"
    db_pass="$(read_env_value DB_PASSWORD "${env_file}")"

    [[ -n "${db_host}" ]] || fail "DB_HOST missing in .env"
    [[ -n "${db_port}" ]] || db_port="3306"
    [[ -n "${db_name}" ]] || fail "DB_DATABASE missing in .env"
    [[ -n "${db_user}" ]] || fail "DB_USERNAME missing in .env"

    log "Importing database dump into ${db_name}@${db_host}:${db_port}..."
    if [[ "${dump_path}" == *.gz ]]; then
        gzip -dc "${dump_path}" | mysql -h "${db_host}" -P "${db_port}" -u "${db_user}" --password="${db_pass}" "${db_name}"
    else
        mysql -h "${db_host}" -P "${db_port}" -u "${db_user}" --password="${db_pass}" "${db_name}" < "${dump_path}"
    fi
}

discover_bundled_db_dump() {
    if [[ -n "${DB_DUMP_PATH}" ]]; then
        return 0
    fi

    local candidates=(
        "/backup-meta/panel-database.sql.gz"
        "/backup-meta/panel-database.sql"
    )
    local p
    for p in "${candidates[@]}"; do
        if [[ -f "${p}" ]]; then
            DB_DUMP_PATH="${p}"
            log "Detected bundled database dump: ${DB_DUMP_PATH}"
            return 0
        fi
    done
}

artisan_as_www_data() {
    runuser -u www-data -- env CACHE_DRIVER=file SESSION_DRIVER=file QUEUE_CONNECTION=sync php "${PANEL_DIR}/artisan" "$@" || true
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --archive)
            ARCHIVE_PATH="${2:-}"
            shift 2
            ;;
        --checksum)
            CHECKSUM_PATH="${2:-}"
            shift 2
            ;;
        --db-dump)
            DB_DUMP_PATH="${2:-}"
            shift 2
            ;;
        --panel-dir)
            PANEL_DIR="${2:-}"
            shift 2
            ;;
        --redis-version)
            REDIS_VERSION="${2:-}"
            shift 2
            ;;
        --skip-packages)
            INSTALL_PACKAGES=0
            shift
            ;;
        --skip-composer)
            RUN_COMPOSER=0
            shift
            ;;
        --skip-migrations)
            RUN_MIGRATIONS=0
            shift
            ;;
        --build-assets)
            BUILD_ASSETS=1
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            fail "Unknown argument: $1"
            ;;
    esac
done

[[ "${EUID}" -eq 0 ]] || fail "Run this script as root."
[[ -n "${ARCHIVE_PATH}" ]] || fail "--archive is required."
[[ -f "${ARCHIVE_PATH}" ]] || fail "Archive not found: ${ARCHIVE_PATH}"

if [[ -n "${CHECKSUM_PATH}" ]]; then
    [[ -f "${CHECKSUM_PATH}" ]] || fail "Checksum file not found: ${CHECKSUM_PATH}"
fi

if [[ "${INSTALL_PACKAGES}" -eq 1 ]]; then
    install_base_packages
fi

if ! command_exists rg; then
    fail "ripgrep (rg) is required. Install with: apt-get install -y ripgrep"
fi

sanitize_redis_config

if [[ -n "${CHECKSUM_PATH}" ]]; then
    log "Verifying archive checksum..."
    sha256sum -c "${CHECKSUM_PATH}"
fi

TS="$(date -u +%Y%m%d-%H%M%S)"
PRE_BACKUP_DIR="/root/panel-restore-preflight-${TS}"
mkdir -p "${PRE_BACKUP_DIR}"

log "Creating pre-restore safety backup at ${PRE_BACKUP_DIR}..."
if [[ -d "${PANEL_DIR}" ]] && [[ -n "$(ls -A "${PANEL_DIR}" 2>/dev/null || true)" ]]; then
    tar -czpf "${PRE_BACKUP_DIR}/panel-files-before-restore.tar.gz" -C "$(dirname "${PANEL_DIR}")" "$(basename "${PANEL_DIR}")"
fi
cp -a /etc/nginx/sites-available/pterodactyl.conf "${PRE_BACKUP_DIR}/" 2>/dev/null || true
cp -a /etc/nginx/sites-enabled/pterodactyl.conf "${PRE_BACKUP_DIR}/" 2>/dev/null || true
cp -a /etc/systemd/system/pterodactyl-external-ws-proxy.service "${PRE_BACKUP_DIR}/" 2>/dev/null || true

log "Extracting backup archive to / ..."
tar -xzpf "${ARCHIVE_PATH}" -C /
discover_bundled_db_dump

if [[ ! -d "${PANEL_DIR}" ]]; then
    fail "Panel directory missing after extraction: ${PANEL_DIR}"
fi

if [[ -f /etc/nginx/sites-available/pterodactyl.conf ]]; then
    ln -sfn /etc/nginx/sites-available/pterodactyl.conf /etc/nginx/sites-enabled/pterodactyl.conf
fi

if [[ -f /etc/systemd/system/pterodactyl-external-ws-proxy.service ]] && command_exists node; then
    NODE_PATH="$(command -v node)"
    sed -i -E "s#^ExecStart=.*external-websocket-proxy\\.js#ExecStart=${NODE_PATH} /var/www/pterodactyl/scripts/external-websocket-proxy.js#g" /etc/systemd/system/pterodactyl-external-ws-proxy.service
fi

log "Fixing ownership and writable permissions..."
chown -R www-data:www-data "${PANEL_DIR}"
mkdir -p "${PANEL_DIR}/storage" "${PANEL_DIR}/bootstrap/cache"
chmod -R u+rwX,g+rwX "${PANEL_DIR}/storage" "${PANEL_DIR}/bootstrap/cache"

cd "${PANEL_DIR}"

if [[ "${RUN_COMPOSER}" -eq 1 ]]; then
    if command_exists composer && [[ -f composer.json ]]; then
        log "Installing composer dependencies..."
        COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
    else
        log "Skipping composer install (composer or composer.json missing)."
    fi
fi

if [[ -n "${DB_DUMP_PATH}" ]]; then
    import_database_dump "${DB_DUMP_PATH}"
fi

if [[ -f artisan ]]; then
    log "Running artisan maintenance tasks..."
    artisan_as_www_data optimize:clear

    if [[ "${RUN_MIGRATIONS}" -eq 1 ]]; then
        artisan_as_www_data migrate --seed --force
    fi

    artisan_as_www_data config:cache
    artisan_as_www_data route:cache
    artisan_as_www_data view:cache
    artisan_as_www_data queue:restart
fi

if [[ "${BUILD_ASSETS}" -eq 1 ]]; then
    if command_exists yarn && [[ -f package.json ]]; then
        log "Building frontend assets..."
        NODE_OPTIONS=--openssl-legacy-provider yarn install --frozen-lockfile --ignore-engines
        NODE_OPTIONS=--openssl-legacy-provider yarn build:production
        chown -R www-data:www-data "${PANEL_DIR}/public/assets" 2>/dev/null || true
    else
        log "Skipping asset build (yarn or package.json missing)."
    fi
fi

log "Re-applying panel ownership for runtime services ..."
chown -R www-data:www-data "${PANEL_DIR}/storage" "${PANEL_DIR}/bootstrap/cache" "${PANEL_DIR}/public/assets" 2>/dev/null || true

log "Reloading services..."
systemctl daemon-reload
sanitize_redis_config
restart_if_present redis
restart_if_present redis-server
restart_if_present php8.3-fpm
restart_if_present nginx
restart_if_present pteroq
restart_if_present pterodactyl-external-ws-proxy
restart_if_present wings

log "Restore complete."
echo
echo "Archive restored from: ${ARCHIVE_PATH}"
echo "Panel path: ${PANEL_DIR}"
echo "Pre-restore safety backup: ${PRE_BACKUP_DIR}"
echo
echo "Recommended checks:"
echo "  1) systemctl status nginx php8.3-fpm redis pteroq pterodactyl-external-ws-proxy --no-pager"
echo "  2) php ${PANEL_DIR}/artisan p:environment:mail"
echo "  3) Visit your panel URL and test dashboard, console, external server routes."
