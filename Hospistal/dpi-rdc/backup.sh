#!/usr/bin/env bash
set -euo pipefail

# shellcheck disable=SC1091
source .env 2>/dev/null || true

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_FILE="${BACKUP_DIR}/dpi_${ESTABLISHMENT_CODE}_${TIMESTAMP}.sql.gz.gpg"
DB_NAME="dpi_${ESTABLISHMENT_CODE}"

mkdir -p "${BACKUP_DIR}"

pg_dump -h "${DB_HOST:-db}" -U dpi_user "${DB_NAME}" \
    | gzip \
    | gpg --encrypt --recipient "${CENTRAL_GPG_KEY}" \
    > "${BACKUP_FILE}"

echo "Sauvegarde créée: ${BACKUP_FILE}"

if ping -c1 "${CENTRAL_HOST}" &>/dev/null; then
    sftp "${CENTRAL_USER}@${CENTRAL_HOST}:/backups/" <<< "put ${BACKUP_FILE}"
    echo "Sauvegarde uploadée vers le central"
fi

find "${BACKUP_DIR}" -name "*.gpg" -mtime +7 -delete
