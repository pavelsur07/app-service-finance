#!/usr/bin/env bash
# Read-only: resolve the exact images already used by infrastructure containers.
infrastructure_digests() {
    local name container image_id ref digest
    for name in traefik postgres redis; do
        case "$name" in
            traefik) container=traefik ;;
            postgres) container=symfony-postgres ;;
            redis) container=site-redis ;;
        esac
        image_id=$(docker inspect --format '{{.Image}}' "$container" < /dev/null)
        digest=''
        while IFS= read -r ref; do
            ref=${ref#docker.io/}
            ref=${ref#library/}
            if [[ $ref =~ ^$name@sha256:[0-9a-f]{64}$ ]]; then
                digest="docker.io/library/$ref"
            fi
        done < <(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$image_id" < /dev/null)
        [[ -n $digest ]] || { printf 'No registry digest for installed %s image.\n' "$name" >&2; return 1; }
        printf '%s\t%s\n' "$name" "$digest"
    done
}

if [[ ${BASH_SOURCE[0]:-$0} == "$0" ]]; then
    set -euo pipefail
    infrastructure_digests
fi
