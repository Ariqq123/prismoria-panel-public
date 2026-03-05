#!/usr/bin/env bash
set -euo pipefail

# Deploy custom panel directly from a Git repository (no archive extraction).
#
# Supports two modes:
# 1) Clone/update a repo with --repo (recommended for fresh VPS).
# 2) Use already-cloned source in --panel-dir.

PANEL_DIR="/var/www/pterodactyl"
REPO_URL=""
BRANCH="main"
FORCE_REPLACE=0

PANEL_DOMAIN=""
LETSENCRYPT_EMAIL=""

DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="panel"
DB_USER="pterodactyl"
DB_PASS=""
DB_ROOT_USER="root"
DB_ROOT_PASS=""
DB_PASS_GENERATED=0
DB_DUMP_PATH=""

PHP_VERSION=""
APP_ENV="production"
APP_TIMEZONE="UTC"
REDIS_VERSION="6:8.6.1-1rl1~noble1"

INSTALL_PACKAGES=1
RUN_COMPOSER=1
RUN_MIGRATIONS=1
BUILD_ASSETS=0

usage() {
    cat <<'USAGE'
Usage:
  sudo bash scripts/auto-setup-from-repo.sh --domain panel.example.com [options]

Required:
  --domain FQDN                  Panel domain (example: panel.example.com)

Repo options:
  --repo URL                     Git repository URL to clone/update
  --branch NAME                  Git branch/tag (default: main)
  --panel-dir PATH               Deploy path (default: /var/www/pterodactyl)
  --force-replace                Remove non-git panel-dir contents before cloning

TLS:
  --letsencrypt-email EMAIL      Request and configure TLS certificate

Database:
  --db-host HOST                 DB host (default: 127.0.0.1)
  --db-port PORT                 DB port (default: 3306)
  --db-name NAME                 DB name (default: panel)
  --db-user USER                 DB user (default: pterodactyl)
  --db-pass PASS                 DB password (default: generated random)
  --db-root-user USER            MariaDB root user (default: root)
  --db-root-pass PASS            MariaDB root password (optional)
  --db-dump PATH                 Optional SQL dump (.sql or .sql.gz) to import

Runtime:
  --php-version X.Y              Force PHP version (example: 8.3)
  --app-env ENV                  APP_ENV (default: production)
  --app-timezone TZ              APP_TIMEZONE (default: UTC)
  --redis-version VER            Redis package version (default: 6:8.6.1-1rl1~noble1)
                                 Use "auto" to install distro default redis version.

Install flags:
  --skip-packages                Skip apt package installation
  --skip-composer                Skip composer install
  --skip-migrations              Skip artisan migrations
  --build-assets                 Run yarn install + yarn build:production
  --help                         Show this help

Examples:
  sudo bash scripts/auto-setup-from-repo.sh \
    --repo https://github.com/your-org/your-panel.git \
    --branch main \
    --domain panel.example.com \
    --letsencrypt-email admin@example.com

  sudo bash scripts/auto-setup-from-repo.sh \
    --domain panel.example.com \
    --db-dump /root/panel-db.sql.gz
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

escape_sed_replacement() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//&/\\&}"
    s="${s//|/\\|}"
    printf '%s' "$s"
}

upsert_env() {
    local key="$1"
    local value="$2"
    local file="$3"
    local escaped
    escaped="$(escape_sed_replacement "$value")"
    if grep -qE "^${key}=" "$file"; then
        sed -i -E "s|^${key}=.*|${key}=${escaped}|g" "$file"
    else
        echo "${key}=${value}" >> "$file"
    fi
}

restart_if_present() {
    local service="$1"
    if systemctl list-unit-files --type=service | awk '{print $1}' | rg -qx "${service}.service"; then
        systemctl enable "${service}" >/dev/null 2>&1 || true
        systemctl restart "${service}" >/dev/null 2>&1 || true
    fi
}

detect_php_version() {
    if [[ -n "${PHP_VERSION}" ]]; then
        return 0
    fi

    local candidates=("8.3" "8.2" "8.1")
    local v
    for v in "${candidates[@]}"; do
        if apt-cache show "php${v}-fpm" >/dev/null 2>&1; then
            PHP_VERSION="${v}"
            return 0
        fi
    done

    fail "Unable to detect supported PHP version (8.3/8.2/8.1). Use --php-version."
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

sanitize_redis_config() {
    local redis_conf="/etc/redis/redis.conf"
    [[ -f "${redis_conf}" ]] || return 0

    if rg -q '^[[:space:]]*locale-(collate|ctype)[[:space:]]' "${redis_conf}"; then
        sed -i -E '/^[[:space:]]*locale-(collate|ctype)[[:space:]]/d' "${redis_conf}"
    fi
    if rg -q '^[[:space:]]*set-max-listpack-(entries|value)[[:space:]]' "${redis_conf}"; then
        sed -i -E '/^[[:space:]]*set-max-listpack-(entries|value)[[:space:]]/d' "${redis_conf}"
    fi
}

install_packages() {
    log "Installing required packages..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y \
        ca-certificates curl gnupg lsb-release apt-transport-https \
        git tar gzip unzip rsync jq ripgrep \
        nginx mariadb-server mariadb-client certbot python3-certbot-nginx

    install_redis_packages

    detect_php_version
    apt-get install -y \
        "php${PHP_VERSION}" \
        "php${PHP_VERSION}-cli" \
        "php${PHP_VERSION}-fpm" \
        "php${PHP_VERSION}-redis" \
        "php${PHP_VERSION}-mysql" \
        "php${PHP_VERSION}-xml" \
        "php${PHP_VERSION}-mbstring" \
        "php${PHP_VERSION}-curl" \
        "php${PHP_VERSION}-zip" \
        "php${PHP_VERSION}-bcmath" \
        "php${PHP_VERSION}-tokenizer"

    if ! command_exists composer; then
        apt-get install -y composer
    fi

    if ! command_exists node; then
        apt-get install -y nodejs npm || true
    fi

    if ! command_exists yarn; then
        npm install -g yarn >/dev/null 2>&1 || true
    fi
}

verify_php_runtime_modules() {
    if ! php -m | rg -qi '^redis$'; then
        fail "PHP redis extension missing. Install php${PHP_VERSION}-redis, then retry."
    fi
}

ensure_repo_present() {
    if [[ -n "${REPO_URL}" ]]; then
        if [[ -d "${PANEL_DIR}/.git" ]]; then
            log "Updating existing git repo in ${PANEL_DIR} ..."
            git -C "${PANEL_DIR}" fetch --all --tags
            git -C "${PANEL_DIR}" checkout "${BRANCH}"
            git -C "${PANEL_DIR}" pull --ff-only origin "${BRANCH}"
        else
            if [[ -d "${PANEL_DIR}" ]] && [[ -n "$(ls -A "${PANEL_DIR}" 2>/dev/null || true)" ]]; then
                if [[ "${FORCE_REPLACE}" -ne 1 ]]; then
                    fail "${PANEL_DIR} exists and is not a git repo. Use --force-replace or empty the directory."
                fi
                rm -rf "${PANEL_DIR}"
            fi
            log "Cloning ${REPO_URL} (${BRANCH}) into ${PANEL_DIR} ..."
            git clone --branch "${BRANCH}" --depth 1 "${REPO_URL}" "${PANEL_DIR}"
        fi
    fi

    [[ -f "${PANEL_DIR}/artisan" ]] || fail "Panel source missing in ${PANEL_DIR} (artisan not found)."
}

mysql_root_exec() {
    local query="$1"
    if [[ -n "${DB_ROOT_PASS}" ]]; then
        mysql -u"${DB_ROOT_USER}" --password="${DB_ROOT_PASS}" -e "${query}"
    else
        mysql -u"${DB_ROOT_USER}" -e "${query}"
    fi
}

create_database_and_user() {
    [[ "${DB_NAME}" =~ ^[a-zA-Z0-9_]+$ ]] || fail "--db-name must match [a-zA-Z0-9_]+"
    [[ "${DB_USER}" =~ ^[a-zA-Z0-9_]+$ ]] || fail "--db-user must match [a-zA-Z0-9_]+"

    local db_pass_sql
    db_pass_sql="${DB_PASS//\'/\'\'}"
    log "Creating/updating MariaDB database and user ..."
    mysql_root_exec "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql_root_exec "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${db_pass_sql}';"
    mysql_root_exec "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${db_pass_sql}';"
    mysql_root_exec "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${db_pass_sql}';"
    mysql_root_exec "ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${db_pass_sql}';"
    mysql_root_exec "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
    mysql_root_exec "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';"
    mysql_root_exec "FLUSH PRIVILEGES;"
}

import_database_dump() {
    local dump_path="$1"
    [[ -f "${dump_path}" ]] || fail "DB dump not found: ${dump_path}"
    log "Importing database dump (${dump_path}) ..."
    if [[ "${dump_path}" == *.gz ]]; then
        gzip -dc "${dump_path}" | mysql -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" --password="${DB_PASS}" "${DB_NAME}"
    else
        mysql -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" --password="${DB_PASS}" "${DB_NAME}" < "${dump_path}"
    fi
}

configure_env() {
    local env_file="${PANEL_DIR}/.env"
    if [[ ! -f "${env_file}" ]]; then
        [[ -f "${PANEL_DIR}/.env.example" ]] || fail ".env missing and .env.example not found."
        cp -f "${PANEL_DIR}/.env.example" "${env_file}"
    fi

    local app_url_proto="http"
    if [[ -n "${LETSENCRYPT_EMAIL}" ]]; then
        app_url_proto="https"
    fi

    upsert_env "APP_ENV" "${APP_ENV}" "${env_file}"
    upsert_env "APP_DEBUG" "false" "${env_file}"
    upsert_env "APP_URL" "${app_url_proto}://${PANEL_DOMAIN}" "${env_file}"
    upsert_env "APP_TIMEZONE" "${APP_TIMEZONE}" "${env_file}"
    upsert_env "DB_HOST" "${DB_HOST}" "${env_file}"
    upsert_env "DB_PORT" "${DB_PORT}" "${env_file}"
    upsert_env "DB_DATABASE" "${DB_NAME}" "${env_file}"
    upsert_env "DB_USERNAME" "${DB_USER}" "${env_file}"
    upsert_env "DB_PASSWORD" "${DB_PASS}" "${env_file}"
    upsert_env "CACHE_DRIVER" "redis" "${env_file}"
    upsert_env "SESSION_DRIVER" "redis" "${env_file}"
    upsert_env "QUEUE_CONNECTION" "redis" "${env_file}"

    if ! grep -qE '^APP_KEY=base64:' "${env_file}"; then
        php "${PANEL_DIR}/artisan" key:generate --force
    fi
}

write_nginx_config() {
    local php_socket="/run/php/php${PHP_VERSION}-fpm.sock"
    [[ -S "${php_socket}" ]] || fail "PHP-FPM socket missing: ${php_socket}"

    cat > /etc/nginx/sites-available/pterodactyl.conf <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${PANEL_DOMAIN};

    root ${PANEL_DIR}/public;
    index index.php index.html index.htm;
    charset utf-8;

    client_max_body_size 100m;
    client_body_timeout 120s;
    sendfile off;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:${php_socket};
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param PHP_VALUE "upload_max_filesize = 100M \n post_max_size=100M";
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_intercept_errors off;
        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_read_timeout 300;
    }

    location ~ /\.ht {
        deny all;
    }

    access_log off;
    error_log /var/log/nginx/pterodactyl.app-error.log error;
}
EOF

    rm -f /etc/nginx/sites-enabled/default
    ln -sfn /etc/nginx/sites-available/pterodactyl.conf /etc/nginx/sites-enabled/pterodactyl.conf
    nginx -t
}

configure_tls_if_requested() {
    if [[ -z "${LETSENCRYPT_EMAIL}" ]]; then
        log "Skipping certbot (no --letsencrypt-email provided)."
        return 0
    fi

    certbot --nginx \
        -d "${PANEL_DOMAIN}" \
        --non-interactive \
        --agree-tos \
        --email "${LETSENCRYPT_EMAIL}" \
        --redirect || true
}

ensure_service_files() {
    cat > /etc/systemd/system/pteroq.service <<EOF
[Unit]
Description=Pterodactyl Queue Worker
After=redis-server.service

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php ${PANEL_DIR}/artisan queue:work --queue=high,standard,low --sleep=3 --tries=3
StartLimitInterval=180
StartLimitBurst=30
RestartSec=5s

[Install]
WantedBy=multi-user.target
EOF

    if [[ -f "${PANEL_DIR}/scripts/external-websocket-proxy.js" ]] && command_exists node; then
        local node_path
        node_path="$(command -v node)"
        cat > /etc/systemd/system/pterodactyl-external-ws-proxy.service <<EOF
[Unit]
Description=Pterodactyl External Websocket Proxy
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=${PANEL_DIR}
Environment=EXTERNAL_WS_PROXY_DEBUG=false
ExecStart=${node_path} ${PANEL_DIR}/scripts/external-websocket-proxy.js
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF
    fi
}

apply_permissions() {
    chown -R www-data:www-data "${PANEL_DIR}"
    mkdir -p "${PANEL_DIR}/storage" "${PANEL_DIR}/bootstrap/cache"
    chmod -R u+rwX,g+rwX "${PANEL_DIR}/storage" "${PANEL_DIR}/bootstrap/cache"
    chmod 644 "${PANEL_DIR}/.env" 2>/dev/null || true
    chmod +x "${PANEL_DIR}/artisan" 2>/dev/null || true
}

artisan_as_www_data() {
    runuser -u www-data -- env CACHE_DRIVER=file SESSION_DRIVER=file QUEUE_CONNECTION=sync php "${PANEL_DIR}/artisan" "$@" || true
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --repo) REPO_URL="${2:-}"; shift 2 ;;
        --branch) BRANCH="${2:-}"; shift 2 ;;
        --panel-dir) PANEL_DIR="${2:-}"; shift 2 ;;
        --force-replace) FORCE_REPLACE=1; shift ;;
        --domain) PANEL_DOMAIN="${2:-}"; shift 2 ;;
        --letsencrypt-email) LETSENCRYPT_EMAIL="${2:-}"; shift 2 ;;
        --db-host) DB_HOST="${2:-}"; shift 2 ;;
        --db-port) DB_PORT="${2:-}"; shift 2 ;;
        --db-name) DB_NAME="${2:-}"; shift 2 ;;
        --db-user) DB_USER="${2:-}"; shift 2 ;;
        --db-pass) DB_PASS="${2:-}"; shift 2 ;;
        --db-root-user) DB_ROOT_USER="${2:-}"; shift 2 ;;
        --db-root-pass) DB_ROOT_PASS="${2:-}"; shift 2 ;;
        --db-dump) DB_DUMP_PATH="${2:-}"; shift 2 ;;
        --php-version) PHP_VERSION="${2:-}"; shift 2 ;;
        --app-env) APP_ENV="${2:-}"; shift 2 ;;
        --app-timezone) APP_TIMEZONE="${2:-}"; shift 2 ;;
        --redis-version) REDIS_VERSION="${2:-}"; shift 2 ;;
        --skip-packages) INSTALL_PACKAGES=0; shift ;;
        --skip-composer) RUN_COMPOSER=0; shift ;;
        --skip-migrations) RUN_MIGRATIONS=0; shift ;;
        --build-assets) BUILD_ASSETS=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) fail "Unknown argument: $1" ;;
    esac
done

[[ "${EUID}" -eq 0 ]] || fail "Run as root."
[[ -n "${PANEL_DOMAIN}" ]] || fail "--domain is required."
if [[ -n "${DB_DUMP_PATH}" ]]; then
    [[ -f "${DB_DUMP_PATH}" ]] || fail "DB dump not found: ${DB_DUMP_PATH}"
fi

if [[ -z "${DB_PASS}" ]]; then
    DB_PASS="$(openssl rand -base64 24 | tr -d '\n')"
    DB_PASS_GENERATED=1
fi

if [[ "${INSTALL_PACKAGES}" -eq 1 ]]; then
    install_packages
else
    detect_php_version
fi

command_exists git || fail "git not available."
command_exists rg || fail "ripgrep not available."
command_exists php || fail "php not available."
command_exists mysql || fail "mysql client not available."
command_exists nginx || fail "nginx not available."

sanitize_redis_config
verify_php_runtime_modules
ensure_repo_present

restart_if_present mariadb
restart_if_present mysql
restart_if_present redis
restart_if_present redis-server
restart_if_present "php${PHP_VERSION}-fpm"

create_database_and_user
configure_env
write_nginx_config
configure_tls_if_requested
ensure_service_files
apply_permissions

cd "${PANEL_DIR}"

if [[ "${RUN_COMPOSER}" -eq 1 ]]; then
    if [[ -f composer.json ]]; then
        COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
    fi
fi

if [[ -n "${DB_DUMP_PATH}" ]]; then
    import_database_dump "${DB_DUMP_PATH}"
fi

artisan_as_www_data optimize:clear
if [[ "${RUN_MIGRATIONS}" -eq 1 ]]; then
    if [[ -n "${DB_DUMP_PATH}" ]]; then
        artisan_as_www_data migrate --force
    else
        artisan_as_www_data migrate --seed --force
    fi
fi
artisan_as_www_data config:cache
artisan_as_www_data route:cache
artisan_as_www_data view:cache
artisan_as_www_data queue:restart

if [[ "${BUILD_ASSETS}" -eq 1 ]]; then
    if command_exists yarn && [[ -f package.json ]]; then
        NODE_OPTIONS=--openssl-legacy-provider yarn install --frozen-lockfile --ignore-engines
        NODE_OPTIONS=--openssl-legacy-provider yarn build:production
        chown -R www-data:www-data "${PANEL_DIR}/public/assets" 2>/dev/null || true
    fi
fi

apply_permissions

systemctl daemon-reload
sanitize_redis_config
restart_if_present redis
restart_if_present redis-server
restart_if_present "php${PHP_VERSION}-fpm"
restart_if_present nginx
restart_if_present pteroq
restart_if_present pterodactyl-external-ws-proxy
restart_if_present wings

echo "Setup complete."
echo "Panel: ${PANEL_DIR}"
echo "Domain: ${PANEL_DOMAIN}"
if [[ "${DB_PASS_GENERATED}" -eq 1 ]]; then
    echo "Generated DB password: ${DB_PASS}"
fi
echo "Check: systemctl status nginx php${PHP_VERSION}-fpm redis pteroq pterodactyl-external-ws-proxy --no-pager"
