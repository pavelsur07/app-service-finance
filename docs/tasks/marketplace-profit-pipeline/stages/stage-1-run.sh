#!/usr/bin/env bash
# Снимает эталон (stage-1-baseline.sql) с прода через codex-psql-ro в CSV вне git.
# Использование: stage-1-run.sh <company_id> <marketplace> <date_from> <date_to> <out_dir>
set -euo pipefail
[ $# -eq 5 ] || { echo "usage: $0 <company_id> <marketplace> <date_from> <date_to> <out_dir>" >&2; exit 2; }
company=$1; marketplace=$2; from=$3; to=$4; out=$5
[[ $company =~ ^[0-9a-f-]{36}$ && $marketplace =~ ^[a-z]+$ && $from =~ ^[0-9-]{10}$ && $to =~ ^[0-9-]{10}$ ]] || { echo "bad arguments" >&2; exit 2; }
sql_file="$(dirname "$0")/stage-1-baseline.sql"
mkdir -p "$out"
echo "start $(TZ=Europe/Moscow date '+%F %T МСК')" | tee "$out/run.log"
names=$(grep -oP '^-- name: \K\S+' "$sql_file")
for name in $names; do
  query=$(awk -v n="$name" '/^-- name: /{on=($3==n); next} on && !/^--/' "$sql_file" | tr '\n' ' ' \
    | sed -e "s/:company_id/'$company'/g" -e "s/:marketplace/'$marketplace'/g" -e "s/:date_from/'$from'/g" -e "s/:date_to/'$to'/g")
  case "$query" in *\"*) echo "double quote in query $name" >&2; exit 2;; esac
  timeout 300 ssh -o BatchMode=yes -o IdentityAgent=none vf-prod-codex \
    "sudo /usr/local/bin/codex-psql-ro -c \"COPY ($query) TO STDOUT WITH CSV HEADER\"" < /dev/null > "$out/$name.csv"
  printf '%s rows=%s sha256=%s\n' "$name" "$(($(wc -l < "$out/$name.csv") - 1))" "$(sha256sum "$out/$name.csv" | cut -c1-64)" | tee -a "$out/run.log"
done
echo "end $(TZ=Europe/Moscow date '+%F %T МСК')" | tee -a "$out/run.log"
