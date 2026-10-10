#!/usr/bin/env sh
set -eu

STORAGE_DIR="/app/var/storage"
IMPORT_DIR="$STORAGE_DIR/cash-file-imports"
COMPANY_DIR="$STORAGE_DIR/companies"
LOG_DIR="/app/var/log"

mkdir -p "$IMPORT_DIR"
mkdir -p "$COMPANY_DIR"
mkdir -p "$LOG_DIR"
# companies — отдельный том, смонтированный ВНУТРИ storage: chown/chmod -R по storage
# заходят и в него (busybox, проверено 10.10.2026), отдельный проход не нужен.
chown -R www-data:www-data "$STORAGE_DIR"
chmod -R 0775 "$STORAGE_DIR"
chown -R www-data:www-data "$LOG_DIR"
chmod -R 0775 "$LOG_DIR"

# запуск основной команды контейнера под пользователем app
exec su-exec app "$@"
