#!/usr/bin/env sh
set -eu

STORAGE_DIR="/app/var/storage"
IMPORT_DIR="$STORAGE_DIR/cash-file-imports"

mkdir -p "$IMPORT_DIR"
chown -R www-data:www-data "$STORAGE_DIR"
chmod -R 0775 "$STORAGE_DIR"

# запуск основной команды контейнера под пользователем app
exec su-exec app "$@"
