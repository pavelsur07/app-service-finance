#!/usr/bin/env bash
# One-time operator command for the approved PR #2474 backup.
# Run on production as root; never run through an unrestricted agent wrapper.
set -euo pipefail
umask 077
[[ ${EUID} -eq 0 ]] || { echo 'Run this script as root.' >&2; exit 1; }
backup_dir=/var/backups/app-service-finance
install -d -o root -g root -m 0700 "$backup_dir"
backup_partial=$(mktemp "$backup_dir/pr-2474-$(date -u +%Y%m%dT%H%M%SZ).XXXXXX.partial")
trap 'rm -f -- "$backup_partial"' EXIT
# Credentials remain inside the existing database container.
docker exec symfony-postgres sh -eu -c 'exec pg_dump --username="$POSTGRES_USER" --dbname="${POSTGRES_DB:-$POSTGRES_USER}" --format=custom' > "$backup_partial"
test -s "$backup_partial"
# Read and decompress the entire archive without connecting to or modifying a DB.
docker exec -i symfony-postgres pg_restore --file=/dev/null < "$backup_partial"
backup_file=${backup_partial%.partial}.dump
mv -n -- "$backup_partial" "$backup_file"
test ! -e "$backup_partial"
trap - EXIT
sha256sum "$backup_file" > "$backup_file.sha256"
sha256sum --check "$backup_file.sha256"
stat --format='Backup: %n | bytes: %s | permissions: %a | owner: %U' "$backup_file"
printf '%s\n' 'Archive fully read; checksum verified. No restore into a database was performed.'
