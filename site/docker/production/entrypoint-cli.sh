#!/usr/bin/env sh
set -eu

STORAGE_DIR="/app/var/storage"
IMPORT_DIR="$STORAGE_DIR/cash-file-imports"
COMPANY_DIR="$STORAGE_DIR/companies"
LOG_DIR="/app/var/log"

# Права правим только там, где они разошлись. chown/chmod -R переписывали метаданные
# каждого из ~36 тыс. файлов storage на каждом старте каждого контейнера, хотя
# расходились единицы (замер на проде 10.10.2026). find только читает, пока не
# найдёт отличающийся файл. Симлинки пропускаются: chown без -h сменил бы
# владельца цели ссылки (в storage их нет). 00775, а не 0775: GNU chmod в php-cli
# иначе оставляет setgid на каталогах, и такой каталог правился бы на каждом старте.
fix_perms() {
    find "$1" ! -type l \( ! -user www-data -o ! -group www-data \) -exec chown www-data:www-data {} +
    find "$1" ! -type l ! -perm 0775 -exec chmod 00775 {} +
}

mkdir -p "$IMPORT_DIR"
mkdir -p "$COMPANY_DIR"
mkdir -p "$LOG_DIR"
# companies — отдельный том, смонтированный ВНУТРИ storage: find по storage
# заходит и в него (busybox, проверено 10.10.2026), отдельный проход не нужен.
fix_perms "$STORAGE_DIR"
fix_perms "$LOG_DIR"

# запуск основной команды контейнера под пользователем app
exec su-exec app "$@"
