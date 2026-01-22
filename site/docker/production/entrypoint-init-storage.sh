#!/usr/bin/env sh
set -eu

STORAGE_DIR="/app/var/storage"
IMPORT_DIR="$STORAGE_DIR/cash-file-imports"

# 1) создаём директории (после mount volume они могут быть пустыми)
mkdir -p "$IMPORT_DIR"

# 2) права: владелец www-data, группа www-data, чтобы и FPM и воркеры могли читать/писать
chown -R www-data:www-data "$STORAGE_DIR"
chmod -R 0775 "$STORAGE_DIR"

# 3) запускаем исходную команду контейнера
exec "$@"
