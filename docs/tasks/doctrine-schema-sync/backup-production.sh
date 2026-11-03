#!/usr/bin/env bash
# One-time operator command: backup of the tables dropped by migration Version20261103150000
# (docs/tasks/doctrine-schema-sync, stage 3). Run on production as root BEFORE
# `production_action=migrations`; never run through an unrestricted agent wrapper.
set -euo pipefail
umask 077
[[ ${EUID} -eq 0 ]] || { echo 'Run this script as root.' >&2; exit 1; }

tables=(
  balance_categories
  balance_category_links
  document_counterparty_repair_backup
  marketplace_raw_processing_step_runs
  repair_backup_ad_load_jobs_20260527
  repair_backup_ad_scheduled_batches_20260527
  bank_connection
  bank_import_cursor
)

backup_dir=/var/backups/app-service-finance
install -d -o root -g root -m 0700 "$backup_dir"
backup_partial=$(mktemp "$backup_dir/schema-sync-stage3-$(date -u +%Y%m%dT%H%M%SZ).XXXXXX.partial")
trap 'rm -f -- "$backup_partial"' EXIT

table_args=()
for table in "${tables[@]}"; do
  table_args+=("--table=public.${table}")
done

# Row counts "before" — must match docs/tasks/doctrine-schema-sync/README.md (13/3/5/3/6/97/0/0).
for table in "${tables[@]}"; do
  rows=$(docker exec symfony-postgres sh -eu -c \
    'psql --username="$POSTGRES_USER" --dbname="${POSTGRES_DB:-$POSTGRES_USER}" -Atc "SELECT count(*) FROM public.$1"' _ "$table")
  printf '%s: %s\n' "$table" "$rows"
done

# Credentials remain inside the existing database container.
docker exec symfony-postgres sh -eu -c \
  'exec pg_dump --username="$POSTGRES_USER" --dbname="${POSTGRES_DB:-$POSTGRES_USER}" --format=custom "$@"' _ "${table_args[@]}" > "$backup_partial"
test -s "$backup_partial"
# Read the entire archive without connecting to or modifying a DB; list what it holds.
docker exec -i symfony-postgres pg_restore --file=/dev/null < "$backup_partial"
docker exec -i symfony-postgres pg_restore --list < "$backup_partial" | grep -E 'TABLE DATA' || true

backup_file=${backup_partial%.partial}.dump
mv -n -- "$backup_partial" "$backup_file"
test ! -e "$backup_partial"
trap - EXIT
sha256sum "$backup_file" > "$backup_file.sha256"
sha256sum --check "$backup_file.sha256"
stat --format='Backup: %n | bytes: %s | permissions: %a | owner: %U' "$backup_file"
printf '%s\n' 'Archive fully read; checksum verified. Restore of one table: pg_restore --table=<name> --data-only (after recreating it).'
