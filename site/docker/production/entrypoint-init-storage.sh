#!/usr/bin/env sh
set -eu

STORAGE_DIR="/app/var/storage"
IMPORT_DIR="$STORAGE_DIR/cash-file-imports"
COMPANY_DIR="$STORAGE_DIR/companies"
LOG_DIR="/app/var/log"

# 1) создаём директории (после mount volume они могут быть пустыми)
mkdir -p "$IMPORT_DIR"
mkdir -p "$COMPANY_DIR"
mkdir -p "$LOG_DIR"

# 2) права: владелец www-data, группа www-data, чтобы и FPM и воркеры могли читать/писать
# companies — отдельный том, смонтированный ВНУТРИ storage: chown/chmod -R по storage
# заходят и в него (busybox, проверено 10.10.2026), отдельный проход не нужен.
chown -R www-data:www-data "$STORAGE_DIR"
chmod -R 0775 "$STORAGE_DIR"
chown -R www-data:www-data "$LOG_DIR"
chmod -R 0775 "$LOG_DIR"

# 3) запускаем исходную команду контейнера
exec "$@"
