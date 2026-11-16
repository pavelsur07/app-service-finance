#!/usr/bin/env bash
# Read-only bootstrap: established high-water, otherwise the live PHP-FPM SHA.
installed_sha() {
    local server_dir=$1 installed
    if [[ -f "$server_dir/shared/release-high-water" ]]; then
        installed=$(cat "$server_dir/shared/release-high-water")
    else
        installed=$(docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' site-php-fpm < /dev/null |
            sed -n 's/^SENTRY_RELEASE=//p')
    fi
    [[ $installed =~ ^[0-9a-f]{40}$ ]] || { echo 'Installed SHA is unknown; refusing production change.' >&2; return 1; }
    printf '%s\n' "$installed"
}

if [[ ${BASH_SOURCE[0]:-$0} == "$0" ]]; then
    set -euo pipefail
    installed_sha "${1:?server directory required}"
fi
