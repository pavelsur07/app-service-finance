#!/bin/sh
# Ночная чистка Docker на проде: остаются KEEP последних релизов каждого образа
# приложения (ghcr.io/pavelsur07/*), остальные SHA-теги снимаются.
#
# Референсная копия. На проде: /usr/local/sbin/docker-retention, запускается
# docker-retention.timer (docs/maintenance/docker-retention.timer). Установка и
# обоснование — docs/maintenance/production-logging.md, «Host disk».
#
# Почему по числу релизов, а не по возрасту: за неделю бывает от 7 до 45 выкаток,
# и фильтр until=… то держит сотни образов, то почти ничего. Один релиз приносит
# ~21 тыс. inode (замер 09.10.2026), поэтому потолок должен быть в штуках.
#
# Что НЕ удаляется:
#   - образ любого контейнера, в том числе остановленного: docker rmi без -f
#     отказывает, отказ печатается и не считается ошибкой;
#   - теги, не похожие на SHA коммита (latest и т. п.), и чужие образы
#     (traefik, postgres, redis) — скрипт их не перебирает;
#   - тома и контейнеры.
# Старые SHA-образы остаются в GHCR: откат дальше KEEP релизов — обычный pull.
#
# DRY_RUN=1 — только напечатать, что было бы удалено.

set -eu

KEEP="${KEEP:-10}"
DRY_RUN="${DRY_RUN:-0}"
REPO_PREFIX="ghcr.io/pavelsur07/"

case "$KEEP" in
    '' | *[!0-9]*) echo "KEEP must be a positive integer, got '$KEEP'" >&2; exit 2 ;;
esac
[ "$KEEP" -ge 1 ] || { echo "KEEP must be >= 1" >&2; exit 2; }

# Два запуска подряд (ручной поверх таймерного) не должны драться за одни теги.
exec 9>"${LOCK_FILE:-/run/docker-retention.lock}"
flock -n 9 || { echo "docker-retention: already running"; exit 0; }

root="$(docker info -f '{{.DockerRootDir}}' 2>/dev/null || echo /)"
usage() {
    printf 'inodes %s, bytes %s' \
        "$(df -Pi "$root" | awk 'NR==2 {print $5}')" \
        "$(df -Pk "$root" | awk 'NR==2 {print $5}')"
}

echo "docker-retention: start KEEP=$KEEP DRY_RUN=$DRY_RUN $root $(usage)"

for repo in $(docker images --format '{{.Repository}}' | grep "^${REPO_PREFIX}" | sort -u); do
    # CreatedAt — время сборки в CI в одной зоне хоста, строка сортируется как дата.
    docker images "$repo" --format '{{.CreatedAt}}|{{.Tag}}' \
        | grep -E '\|[0-9a-f]{40}$' \
        | sort -r \
        | tail -n +"$((KEEP + 1))" \
        | cut -d'|' -f2 \
        | while read -r tag; do
            if [ "$DRY_RUN" = 1 ]; then
                echo "would remove $repo:$tag"
            elif docker rmi "$repo:$tag" >/dev/null 2>&1; then
                echo "removed $repo:$tag"
            else
                echo "kept $repo:$tag (in use by a container)"
            fi
        done
done

if [ "$DRY_RUN" != 1 ]; then
    # Висячие слои после снятия тегов и build-кэш: на проде образы не собираются.
    docker image prune -f >/dev/null
    docker builder prune -af >/dev/null
fi

echo "docker-retention: done $(usage)"
