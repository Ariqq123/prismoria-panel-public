#!/usr/bin/env bash
set -euo pipefail

# Build a complete Prismo panel backup bundle, including database dump by default.

PANEL_DIR="/var/www/pterodactyl"
OUTPUT_DIR="/root/download/ptero-prismo"
INCLUDE_DB=1

usage() {
    cat <<'USAGE'
Usage:
  sudo bash scripts/build-ptero-prismo-backup.sh [options]

Options:
  --panel-dir PATH      Panel directory (default: /var/www/pterodactyl)
  --output-dir PATH     Output directory (default: /root/download/ptero-prismo)
  --skip-db             Do not include DB dump in archive
  --help                Show this help

Output:
  <output-dir>/ptero-prismo-panel-bundle-YYYYmmdd-HHMMSS.zip
  <output-dir>/ptero-prismo-panel-bundle-YYYYmmdd-HHMMSS.zip.sha256
  <output-dir>/ptero-prismo-panel-bundle-latest.zip
  <output-dir>/ptero-prismo-panel-bundle-latest.zip.sha256
USAGE
}

log() {
    printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

fail() {
    echo "[ERROR] $*" >&2
    exit 1
}

read_env_value() {
    local key="$1"
    local env_file="$2"
    local value
    value="$(grep -E "^${key}=" "${env_file}" | tail -n1 | cut -d= -f2- || true)"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "${value}"
}

build_db_dump() {
    local stage_backup_meta="$1"
    local env_file="${PANEL_DIR}/.env"
    [[ -f "${env_file}" ]] || fail "Missing ${env_file}"

    local db_host db_port db_name db_user db_pass
    db_host="$(read_env_value DB_HOST "${env_file}")"
    db_port="$(read_env_value DB_PORT "${env_file}")"
    db_name="$(read_env_value DB_DATABASE "${env_file}")"
    db_user="$(read_env_value DB_USERNAME "${env_file}")"
    db_pass="$(read_env_value DB_PASSWORD "${env_file}")"

    [[ -n "${db_host}" ]] || db_host="127.0.0.1"
    [[ -n "${db_port}" ]] || db_port="3306"
    [[ -n "${db_name}" ]] || fail "DB_DATABASE missing in .env"
    [[ -n "${db_user}" ]] || fail "DB_USERNAME missing in .env"

    local out_sql_gz="${stage_backup_meta}/panel-database.sql.gz"
    log "Dumping database ${db_name}@${db_host}:${db_port} ..."
    MYSQL_PWD="${db_pass}" mysqldump \
        --host="${db_host}" \
        --port="${db_port}" \
        --user="${db_user}" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        "${db_name}" | gzip -c > "${out_sql_gz}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --panel-dir)
            PANEL_DIR="${2:-}"
            shift 2
            ;;
        --output-dir)
            OUTPUT_DIR="${2:-}"
            shift 2
            ;;
        --skip-db)
            INCLUDE_DB=0
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

[[ "${EUID}" -eq 0 ]] || fail "Run as root."
[[ -d "${PANEL_DIR}" ]] || fail "Panel dir not found: ${PANEL_DIR}"
command -v rsync >/dev/null 2>&1 || fail "rsync not installed."
command -v zip >/dev/null 2>&1 || fail "zip not installed."
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum not installed."
if [[ "${INCLUDE_DB}" -eq 1 ]]; then
    command -v mysqldump >/dev/null 2>&1 || fail "mysqldump not installed."
fi

TS="$(date -u +%Y%m%d-%H%M%S)"
STAGE_DIR="/tmp/ptero-prismo-export-${TS}"
OUT_BASE="${OUTPUT_DIR}/ptero-prismo-panel-bundle-${TS}"
OUT_ZIP="${OUT_BASE}.zip"
OUT_SHA="${OUT_ZIP}.sha256"

mkdir -p \
    "${STAGE_DIR}/var/www" \
    "${STAGE_DIR}/etc/nginx/sites-available" \
    "${STAGE_DIR}/etc/systemd/system" \
    "${STAGE_DIR}/backup-meta" \
    "${OUTPUT_DIR}"

log "Syncing panel files ..."
rsync -a "${PANEL_DIR}/" "${STAGE_DIR}/var/www/pterodactyl/" \
    --exclude '.git' \
    --exclude 'node_modules' \
    --exclude '.desloppify' \
    --exclude 'backups' \
    --exclude 'element_clones' \
    --exclude '.yarn-cache' \
    --exclude 'storage/logs/*' \
    --exclude 'storage/framework/cache/data/*' \
    --exclude 'storage/framework/sessions/*' \
    --exclude 'storage/framework/views/*' \
    --exclude 'storage/debugbar/*'

cp -a /etc/nginx/sites-available/pterodactyl.conf "${STAGE_DIR}/etc/nginx/sites-available/" 2>/dev/null || true
cp -a /etc/systemd/system/pterodactyl-external-ws-proxy.service "${STAGE_DIR}/etc/systemd/system/" 2>/dev/null || true
cp -a /etc/systemd/system/pteroq.service "${STAGE_DIR}/etc/systemd/system/" 2>/dev/null || true
cp -a /etc/systemd/system/wings.service "${STAGE_DIR}/etc/systemd/system/" 2>/dev/null || true

if [[ "${INCLUDE_DB}" -eq 1 ]]; then
    build_db_dump "${STAGE_DIR}/backup-meta"
fi

cat > "${STAGE_DIR}/backup-meta/README-RESTORE.txt" <<EOF
Prismo Custom Pterodactyl Panel Bundle
Generated (UTC): $(date -u +%Y-%m-%dT%H:%M:%SZ)

Includes:
- /var/www/pterodactyl
- /etc/nginx/sites-available/pterodactyl.conf
- /etc/systemd/system/pterodactyl-external-ws-proxy.service
- /etc/systemd/system/pteroq.service
- /etc/systemd/system/wings.service
$(if [[ "${INCLUDE_DB}" -eq 1 ]]; then echo "- /backup-meta/panel-database.sql.gz"; fi)

Install:
sudo bash /root/download/ptero-prismo/auto-setup-custom-panel.sh \\
  --archive /root/download/ptero-prismo/$(basename "${OUT_ZIP}") \\
  --domain panel.example.com \\
  --letsencrypt-email admin@example.com
EOF

cat > "${STAGE_DIR}/backup-meta/MANIFEST.txt" <<EOF
archive: $(basename "${OUT_ZIP}")
generated_utc: $(date -u +%Y-%m-%dT%H:%M:%SZ)
redis_installer_default: 6:8.6.1-1rl1~noble1
includes_db_dump: $(if [[ "${INCLUDE_DB}" -eq 1 ]]; then echo "yes"; else echo "no"; fi)
EOF

log "Creating zip archive ..."
( cd "${STAGE_DIR}" && zip -qr "${OUT_ZIP}" . )
sha256sum "${OUT_ZIP}" > "${OUT_SHA}"

cp -f "${PANEL_DIR}/scripts/auto-setup-custom-panel.sh" "${OUTPUT_DIR}/auto-setup-custom-panel.sh"
chmod +x "${OUTPUT_DIR}/auto-setup-custom-panel.sh"
ln -sfn "${OUT_ZIP}" "${OUTPUT_DIR}/ptero-prismo-panel-bundle-latest.zip"
ln -sfn "${OUT_SHA}" "${OUTPUT_DIR}/ptero-prismo-panel-bundle-latest.zip.sha256"

rm -rf "${STAGE_DIR}"

echo "ZIP=${OUT_ZIP}"
echo "SHA=${OUT_SHA}"
echo "SCRIPT=${OUTPUT_DIR}/auto-setup-custom-panel.sh"
