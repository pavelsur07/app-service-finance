#!/usr/bin/env bash
# Sourced by the single locked production job; credentials stay in its environment.
set -euo pipefail
umask 077
source "$(dirname "${BASH_SOURCE[0]}")/installed-sha.sh"
source "$(dirname "${BASH_SOURCE[0]}")/infrastructure-digests.sh"

release_phase() {
    if [[ -n ${release_current_phase:-} ]]; then
        printf '%s\t%s\t0\n' "$release_current_phase" "$((SECONDS - release_phase_start))" >> release-timings.tsv
    fi
    release_current_phase=$1
    release_phase_start=$SECONDS
    printf 'Release phase: %s\n' "$1"
}

release_report() {
    local code=$1
    if [[ -n ${release_current_phase:-} ]]; then
        printf '%s\t%s\t%s\n' "$release_current_phase" "$((SECONDS - release_phase_start))" "$code" >> release-timings.tsv
    fi
    printf 'Release SHA: %s; exit: %s\n' "$RELEASE_SHA" "$code"
    [[ ! -f release-timings.tsv ]] || cat release-timings.tsv
}

release_backup() {
    local root=${RELEASE_BACKUP_ROOT:-/var/backups/app-service-finance}
    local directory="$root/$RELEASE_SHA/$RELEASE_RUN" database partial
    if [[ -d $root && ! -w $root ]]; then
        printf 'Backup directory is not writable by the workflow deploy user: %s\n' "$root" >&2
        return 1
    fi
    install -d -m 0700 "$root" "$root/$RELEASE_SHA"
    # A retry has a distinct run/attempt. Never overwrite a previous backup.
    mkdir -m 0700 "$directory"
    docker exec symfony-postgres psql -U app -d app -At -v ON_ERROR_STOP=1 \
        -c 'SELECT datname FROM pg_database WHERE NOT datistemplate ORDER BY datname' \
        < /dev/null > "$directory/databases.txt"
    test -s "$directory/databases.txt"
    while IFS= read -r database; do
        [[ $database =~ ^[A-Za-z0-9_-]+$ ]] || { echo 'Unsafe database name in backup inventory.' >&2; return 1; }
        partial="$directory/$database.dump.partial"
        docker exec symfony-postgres pg_dump -U app --dbname="$database" --format=custom \
            < /dev/null > "$partial"
        test -s "$partial"
        # Full archive read/decompression. No restore into a database is performed.
        docker exec -i symfony-postgres pg_restore --file=/dev/null < "$partial"
        mv -n "$partial" "$directory/$database.dump"
        test ! -e "$partial"
        sha256sum "$directory/$database.dump" > "$directory/$database.dump.sha256"
        sha256sum --check "$directory/$database.dump.sha256"
    done < "$directory/databases.txt"
    printf 'Backup archives fully read and checksums verified: %s\n' "$directory"
}

release_record_high_water() {
    printf '%s\n' "$RELEASE_SHA" > "$SERVER_DIR/shared/.release-high-water-$RELEASE_RUN"
    mv "$SERVER_DIR/shared/.release-high-water-$RELEASE_RUN" "$SERVER_DIR/shared/release-high-water"
}

release_service_migrations() {
    docker compose -f docker-compose.prod.yml -f compose.digest.json exec -T -e OZON_FETCHER_DB_PASSWORD site-postgres \
        psql -U app -d app -v ON_ERROR_STOP=1 -q < docker/ozon-fetcher/db-bootstrap.sql
    docker compose -f docker-compose.prod.yml -f compose.digest.json run --rm --no-deps -T ozon-fetcher -migrate < /dev/null
    docker compose -f docker-compose.prod.yml -f compose.digest.json exec -T -e WB_FETCHER_DB_PASSWORD site-postgres \
        psql -U app -d app -v ON_ERROR_STOP=1 -q < docker/wb-fetcher/db-bootstrap.sql
    docker compose -f docker-compose.prod.yml -f compose.digest.json run --rm --no-deps -T wb-fetcher -migrate < /dev/null
    docker compose -f docker-compose.prod.yml -f compose.digest.json exec -T -e AI_SERVICE_DB_PASSWORD site-postgres \
        psql -U app -d app -v ON_ERROR_STOP=1 -q < docker/ai-service/db-bootstrap.sql
    docker compose -f docker-compose.prod.yml -f compose.digest.json run --rm --no-deps -T ai-service migrate < /dev/null
}

release_service_schema() {
    local database file stem expected actual query
    local -a files
    for database in ozon_fetcher wb_fetcher ai_service; do
        expected=$(mktemp)
        actual=$(mktemp)
        case "$database" in
            ozon_fetcher) files=(services/ozon-fetcher/internal/ozonstore/migrations/*.sql) ;;
            wb_fetcher) files=(services/shared/fetchkit/store/migrations/*.sql services/wb-fetcher/internal/app/migrations/*.sql) ;;
            ai_service) files=(services/ai-service/migrations/*.sql) ;;
        esac
        for file in "${files[@]}"; do
            test -f "$file"
            stem=${file##*/}
            if [[ $database == ai_service ]]; then
                printf '%s\n' "${stem%.sql}"
            else
                stem=${stem%%_*}
                printf '%s\n' "$((10#$stem))"
            fi
        done | LC_ALL=C sort -u > "$expected"
        query='SELECT version FROM schema_migrations'
        if [[ $database != ai_service ]]; then
            query='SELECT version_id FROM (SELECT DISTINCT ON (version_id) version_id, is_applied FROM goose_db_version WHERE version_id > 0 ORDER BY version_id, id DESC) versions WHERE is_applied'
        fi
        docker exec symfony-postgres psql -U app -d "$database" -At -v ON_ERROR_STOP=1 -c "$query" \
            < /dev/null | LC_ALL=C sort -u > "$actual"
        diff -u "$expected" "$actual"
        rm "$expected" "$actual"
    done
}

release_prepare() {
    [[ $RELEASE_SHA =~ ^[0-9a-f]{40}$ && $EXPECTED_INSTALLED_SHA =~ ^[0-9a-f]{40}$ ]]
    [[ $RELEASE_RUN =~ ^[0-9]+-[0-9]+$ ]]
    case "$PRODUCTION_ACTION" in release|migrations|deploy) ;; *) return 1 ;; esac
    local key
    for key in INTERNAL_AUTH_TOKEN OZON_FETCHER_API_TOKEN OZON_FETCHER_DB_PASSWORD OZON_FETCHER_S3_ACCESS_KEY_ID OZON_FETCHER_S3_SECRET_ACCESS_KEY WB_FETCHER_API_TOKEN WB_FETCHER_DB_PASSWORD AI_SERVICE_API_TOKEN AI_SERVICE_DB_PASSWORD AI_GATEWAY_TOKEN; do
        [[ -n ${!key:-} ]] || { printf 'Required service secret is missing: %s\n' "$key" >&2; return 1; }
    done
    [[ ${#AI_SERVICE_API_TOKEN} -ge 32 && ${#AI_GATEWAY_TOKEN} -ge 32 ]]
    mkdir -p "$SERVER_DIR/shared"
    exec 9> "$SERVER_DIR/shared/production-release.lock"
    flock 9
    release_phase guard
    trap 'release_report "$?"' EXIT
    # Ancestry was checked on the runner. Recheck the observed predecessor under
    # the remote lock so another approved run cannot invalidate that decision.
    local installed
    installed=$(installed_sha "$SERVER_DIR")
    [[ $installed == "$EXPECTED_INSTALLED_SHA" ]] || {
        echo 'Installed version changed after ancestry validation; refusing release.' >&2
        return 1
    }
    local current_infrastructure
    current_infrastructure=$(mktemp)
    infrastructure_digests | LC_ALL=C sort > "$current_infrastructure"
    LC_ALL=C sort infrastructure.tsv | diff -u - "$current_infrastructure"
    rm "$current_infrastructure"
    if [[ $PRODUCTION_ACTION == release || $PRODUCTION_ACTION == migrations ]]; then
        release_phase backup
        release_backup
        # Reserve the new schema version before any migration, even if it fails.
        release_record_high_water
        release_phase migrate
        docker compose -f docker-compose.prod.yml -f compose.digest.json run --rm --no-deps -T \
            site-php-cli bin/console doctrine:migrations:migrate --no-interaction < /dev/null
        release_service_migrations
    fi
    release_phase schema
    docker compose -f docker-compose.prod.yml -f compose.digest.json run --rm --no-deps -T \
        site-php-cli bin/console doctrine:migrations:up-to-date --no-interaction < /dev/null
    release_service_schema
    release_record_high_water
}

release_finish() {
    if [[ $PRODUCTION_ACTION != migrations ]]; then
        printf '%s\n' "$RELEASE_SHA" > "$SERVER_DIR/shared/release-last-success"
    fi
    release_report 0
    trap - EXIT
}
