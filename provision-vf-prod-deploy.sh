#!/usr/bin/env bash
# Owner-run production provisioner. Codex/Claude agents must never execute it.
# The script is idempotent for its managed SSH key, wrapper, and sudoers entry.

set -Eeuo pipefail

readonly ssh_alias='vf-prod'
readonly deploy_user='deploy'
readonly source_ip='217.198.13.171'
readonly local_ssh_dir="$HOME/.ssh"
readonly key_path="$local_ssh_dir/vf_prod_deploy"

die() {
    printf 'Ошибка: %s\n' "$*" >&2
    exit 1
}

for required_command in ssh ssh-keygen base64 awk tr install chmod; do
    command -v "$required_command" >/dev/null 2>&1 || die "не найдена команда $required_command"
done

ssh_config=$(ssh -G "$ssh_alias" 2>/dev/null) || die "не удалось прочитать SSH-алиас $ssh_alias"
ssh_user=$(awk '$1 == "user" { print $2; exit }' <<< "$ssh_config")
[[ "$ssh_user" == 'root' ]] || die "SSH-алиас $ssh_alias должен быть настроен для пользователя root"

if [[ -e "$local_ssh_dir" || -L "$local_ssh_dir" ]]; then
    [[ ! -L "$local_ssh_dir" ]] || die "$local_ssh_dir не должен быть symlink"
    [[ -d "$local_ssh_dir" ]] || die "$local_ssh_dir не является каталогом"
else
    install -d -m 0700 "$local_ssh_dir"
fi

if [[ -e "$key_path" || -L "$key_path" || -e "${key_path}.pub" || -L "${key_path}.pub" ]]; then
    [[ ! -L "$key_path" ]] || die "$key_path не должен быть symlink"
    [[ ! -L "${key_path}.pub" ]] || die "${key_path}.pub не должен быть symlink"
    [[ -f "$key_path" && -f "${key_path}.pub" ]] || \
        die "ключ и его публичная часть должны быть обычными файлами"
    [[ -s "$key_path" && -s "${key_path}.pub" ]] || \
        die "найдена только одна часть ключа; проверьте $key_path и ${key_path}.pub"
else
    printf 'Создаю отдельный ключ %s (без passphrase, права 0600).\n' "$key_path"
    ssh-keygen \
        -q \
        -t ed25519 \
        -N '' \
        -C "${deploy_user}@${ssh_alias}" \
        -f "$key_path"
fi

chmod 0600 "$key_path"
chmod 0644 "${key_path}.pub"
ssh-keygen -l -f "${key_path}.pub" >/dev/null || die "публичный ключ повреждён"

mapfile -t public_key_lines < "${key_path}.pub"
((${#public_key_lines[@]} == 1)) || die "${key_path}.pub должен содержать ровно одну строку"
read -r public_key_type public_key_blob _ <<< "${public_key_lines[0]}"
[[ "$public_key_type" == 'ssh-ed25519' && "$public_key_blob" =~ ^[A-Za-z0-9+/=]+$ ]] || \
    die "${key_path}.pub не содержит валидный публичный ключ Ed25519"
canonical_public_key="$public_key_type $public_key_blob"
derived_public_key_line=$(ssh-keygen -y -f "$key_path" < /dev/null) || \
    die "не удалось прочитать приватный ключ $key_path без passphrase"
read -r derived_key_type derived_key_blob _ <<< "$derived_public_key_line"
derived_public_key="$derived_key_type $derived_key_blob"
[[ "$derived_public_key" == "$canonical_public_key" ]] || \
    die "приватный и публичный ключи $key_path не соответствуют друг другу"

public_key_b64=$(printf '%s' "$canonical_public_key" | base64 | tr -d '\r\n')
[[ -n "$public_key_b64" ]] || die "публичный ключ пуст"

printf '\nСейчас SSH запросит пароль root для алиаса %s.\n' "$ssh_alias"
printf 'Пароль обрабатывает только ssh: скрипт его не читает и не сохраняет.\n\n'

ssh \
    -o BatchMode=no \
    -o PreferredAuthentications=password,keyboard-interactive \
    -o PubkeyAuthentication=no \
    -o PasswordAuthentication=yes \
    -o KbdInteractiveAuthentication=yes \
    -o ControlMaster=no \
    -o ControlPath=none \
    -o ControlPersist=no \
    "$ssh_alias" \
    bash -s -- "$deploy_user" "$source_ip" "$public_key_b64" <<'REMOTE_SCRIPT'
set -Eeuo pipefail

deploy_user=$1
source_ip=$2
public_key_b64=$3

die() {
    printf 'Ошибка на сервере: %s\n' "$*" >&2
    exit 1
}

[[ $EUID -eq 0 ]] || die 'алиас должен подключать пользователя root'
[[ "$deploy_user" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die 'некорректное имя пользователя'
[[ "$source_ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || die 'некорректный IPv4-адрес'
IFS=. read -r ip_octet_1 ip_octet_2 ip_octet_3 ip_octet_4 <<< "$source_ip"
for ip_octet in "$ip_octet_1" "$ip_octet_2" "$ip_octet_3" "$ip_octet_4"; do
    ((10#$ip_octet <= 255)) || die 'IPv4-адрес содержит octet вне диапазона 0..255'
done
for required_command in base64 getent useradd passwd install docker visudo mktemp stat sudo; do
    command -v "$required_command" >/dev/null 2>&1 || die "не найдена команда $required_command"
done

readonly diagnostics_wrapper='/usr/local/bin/deploy-diagnostics'
readonly psql_wrapper='/usr/local/bin/codex-psql-ro'
readonly sudoers_file='/etc/sudoers.d/deploy-diagnostics'
readonly managed_user_marker='/etc/deploy-diagnostics.user'
readonly bin_dir='/usr/local/bin'
readonly sudoers_dir='/etc/sudoers.d'

for protected_dir in "$bin_dir" "$sudoers_dir"; do
    [[ ! -L "$protected_dir" && -d "$protected_dir" ]] || \
        die "$protected_dir отсутствует, не является каталогом или является symlink"
    [[ "$(stat -c '%U' "$protected_dir")" == 'root' ]] || die "$protected_dir должен принадлежать root"
    protected_dir_mode=$(stat -c '%a' "$protected_dir")
    [[ "$protected_dir_mode" =~ ^[0-7]{3,4}$ ]] || die "не удалось проверить права $protected_dir"
    (( (8#$protected_dir_mode & 0022) == 0 )) || die "$protected_dir доступен для записи не только root"
done

[[ ! -L "$psql_wrapper" && -f "$psql_wrapper" && -x "$psql_wrapper" ]] || \
    die "$psql_wrapper отсутствует, не является обычным исполняемым файлом или является symlink"
[[ "$(stat -c '%U' "$psql_wrapper")" == 'root' ]] || die "$psql_wrapper должен принадлежать root"
psql_wrapper_mode=$(stat -c '%a' "$psql_wrapper")
[[ "$psql_wrapper_mode" =~ ^[0-7]{3,4}$ ]] || die "не удалось проверить права $psql_wrapper"
(( (8#$psql_wrapper_mode & 0022) == 0 )) || die "$psql_wrapper доступен для записи не только root"

public_key=$(printf '%s' "$public_key_b64" | base64 -d) || die 'не удалось декодировать публичный ключ'
[[ "$public_key" != *[$'\n\r']* ]] || die 'публичный ключ содержит перенос строки'
[[ "$public_key" =~ ^ssh-ed25519[[:space:]][A-Za-z0-9+/=]+$ ]] || \
    die 'передан невалидный публичный ключ Ed25519'

user_created=0
if ! getent passwd "$deploy_user" >/dev/null; then
    [[ ! -e "$managed_user_marker" && ! -L "$managed_user_marker" ]] || \
        die "$managed_user_marker существует без пользователя $deploy_user"
    useradd --create-home --shell /bin/bash "$deploy_user"
    passwd --lock "$deploy_user" >/dev/null
    user_created=1
else
    password_status_line=$(passwd --status "$deploy_user") || \
        die "не удалось прочитать статус пароля $deploy_user"
    read -r _ password_status _ <<< "$password_status_line"
    [[ "$password_status" == 'L' ]] || \
        die "существующий пользователь $deploy_user имеет незаблокированный пароль"
fi

passwd_entry=$(getent passwd "$deploy_user") || die "пользователь $deploy_user не найден после создания"
IFS=: read -r _ _ deploy_uid _ _ deploy_home deploy_shell <<< "$passwd_entry"

[[ "$deploy_uid" != '0' ]] || die "пользователь $deploy_user не должен иметь UID 0"
[[ "$deploy_home" == "/home/$deploy_user" ]] || \
    die "неожиданный home пользователя $deploy_user: $deploy_home"
[[ "$deploy_shell" == '/bin/bash' ]] || \
    die "неожиданный shell пользователя $deploy_user: $deploy_shell"
[[ ! -L "$deploy_home" ]] || die "home пользователя $deploy_user не должен быть symlink"
[[ -d "$deploy_home" ]] || die "home пользователя $deploy_user не является каталогом"

managed_user_marker_value="user=$deploy_user uid=$deploy_uid"
if ((user_created)); then
    marker_tmp=$(mktemp '/etc/.deploy-diagnostics.user.XXXXXX')
    cleanup_marker() {
        rm -f -- "$marker_tmp"
    }
    trap cleanup_marker EXIT
    printf '%s\n' "$managed_user_marker_value" > "$marker_tmp"
    chown root:root "$marker_tmp"
    chmod 0644 "$marker_tmp"
    mv -f -- "$marker_tmp" "$managed_user_marker"
    trap - EXIT
else
    [[ ! -L "$managed_user_marker" && -f "$managed_user_marker" ]] || \
        die "существующий пользователь $deploy_user не был создан этим provisioner"
    [[ "$(stat -c '%U:%G' "$managed_user_marker")" == 'root:root' ]] || \
        die "$managed_user_marker должен принадлежать root:root"
    managed_user_marker_mode=$(stat -c '%a' "$managed_user_marker")
    [[ "$managed_user_marker_mode" =~ ^[0-7]{3,4}$ ]] || \
        die "не удалось проверить права $managed_user_marker"
    (( (8#$managed_user_marker_mode & 0022) == 0 )) || \
        die "$managed_user_marker доступен для записи не только root"
    [[ "$(< "$managed_user_marker")" == "$managed_user_marker_value" ]] || \
        die "$managed_user_marker не соответствует пользователю $deploy_user"
fi

expected_sudo_spec="(root) NOPASSWD: $diagnostics_wrapper"
validate_sudo_policy() {
    local require_expected=$1
    local sudo_policy_output
    local sudo_policy_status
    local -a sudo_specs

    if sudo_policy_output=$(LC_ALL=C sudo -n -l -U "$deploy_user" 2>&1); then
        sudo_policy_status=0
    else
        sudo_policy_status=$?
    fi
    mapfile -t sudo_specs < <(
        awk '/^[[:space:]]+\(/ { sub(/^[[:space:]]+/, ""); print }' <<< "$sudo_policy_output"
    )

    if ((sudo_policy_status != 0)); then
        if [[ "$require_expected" == 'no' && ${#sudo_specs[@]} -eq 0 && \
            "$sudo_policy_output" == *' is not allowed to run sudo on '* ]]; then
            return
        fi
        die "не удалось проверить эффективную sudo policy пользователя $deploy_user"
    fi

    if ((${#sudo_specs[@]} == 0)); then
        [[ "$require_expected" == 'no' ]] || \
            die "sudo policy пользователя $deploy_user не содержит ожидаемый wrapper"
        return
    fi

    ((${#sudo_specs[@]} == 1)) || \
        die "пользователь $deploy_user имеет дополнительные sudo-разрешения"
    [[ "${sudo_specs[0]}" == "$expected_sudo_spec" ]] || \
        die "пользователь $deploy_user имеет неожиданное sudo-разрешение: ${sudo_specs[0]}"
}

validate_sudo_policy no

deploy_groups=" $(id -nG "$deploy_user") "
for forbidden_group in docker sudo wheel; do
    [[ "$deploy_groups" != *" $forbidden_group "* ]] || \
        die "$deploy_user не должен состоять в привилегированной группе $forbidden_group"
done
ssh_dir="$deploy_home/.ssh"
auth_file="$ssh_dir/authorized_keys"
managed_key_tag='managed-by=provision-vf-prod-deploy'
authorized_entry="restrict,from=\"${source_ip}\",pty ${public_key} ${managed_key_tag}"

chown root:root "$deploy_home"
chmod 0755 "$deploy_home"

[[ ! -L "$ssh_dir" ]] || die "$ssh_dir не должен быть symlink"
[[ ! -e "$ssh_dir" || -d "$ssh_dir" ]] || die "$ssh_dir не является каталогом"
install -d -m 0755 -o root -g root "$ssh_dir"

[[ ! -L "$auth_file" ]] || die "$auth_file не должен быть symlink"
[[ ! -e "$auth_file" || -f "$auth_file" ]] || die "$auth_file не является обычным файлом"
auth_source='/dev/null'
[[ ! -e "$auth_file" ]] || auth_source="$auth_file"
auth_tmp=$(mktemp '/etc/.deploy-authorized_keys.XXXXXX')
cleanup_auth_file() {
    rm -f -- "$auth_tmp"
}
trap cleanup_auth_file EXIT

while IFS= read -r existing_entry || [[ -n "$existing_entry" ]]; do
    trimmed_entry=${existing_entry#"${existing_entry%%[![:space:]]*}"}
    if [[ -z "$trimmed_entry" || "${trimmed_entry:0:1}" == '#' ]]; then
        printf '%s\n' "$existing_entry" >> "$auth_tmp"
        continue
    fi
    [[ "$trimmed_entry" == *" $managed_key_tag" ]] && continue

    die "$auth_file содержит другой активный ключ; файл не изменён"
done < "$auth_source"

printf '%s\n' "$authorized_entry" >> "$auth_tmp"
chown root:root "$auth_tmp"
chmod 0644 "$auth_tmp"
mv -f -- "$auth_tmp" "$auth_file"
trap - EXIT

[[ ! -L "$diagnostics_wrapper" ]] || die "$diagnostics_wrapper не должен быть symlink"
[[ ! -e "$diagnostics_wrapper" || -f "$diagnostics_wrapper" ]] || \
    die "$diagnostics_wrapper не является обычным файлом"
[[ ! -L "$sudoers_file" ]] || die "$sudoers_file не должен быть symlink"
[[ ! -e "$sudoers_file" || -f "$sudoers_file" ]] || die "$sudoers_file не является обычным файлом"

wrapper_tmp=$(mktemp '/usr/local/bin/.deploy-diagnostics.XXXXXX')
sudoers_tmp=''
cleanup_diagnostics_files() {
    [[ -z "$wrapper_tmp" ]] || rm -f -- "$wrapper_tmp"
    [[ -z "$sudoers_tmp" ]] || rm -f -- "$sudoers_tmp"
}
trap cleanup_diagnostics_files EXIT
sudoers_tmp=$(mktemp '/etc/sudoers.d/.deploy-diagnostics.XXXXXX')

cat > "$wrapper_tmp" <<'DIAGNOSTICS_WRAPPER'
#!/bin/bash

set -Eeuo pipefail

readonly PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
readonly psql_wrapper='/usr/local/bin/codex-psql-ro'
readonly HOME='/root'
export LC_ALL='C'
IFS=$' \t\n'
unset DOCKER_HOST DOCKER_CONTEXT DOCKER_CONFIG DOCKER_TLS_VERIFY DOCKER_CERT_PATH DOCKER_API_VERSION
unset BASH_ENV ENV CDPATH GLOBIGNORE

die() {
    printf 'deploy-diagnostics: %s\n' "$*" >&2
    exit 2
}

usage() {
    cat >&2 <<'USAGE'
Использование:
  sudo /usr/local/bin/deploy-diagnostics ps
  sudo /usr/local/bin/deploy-diagnostics images
  sudo /usr/local/bin/deploy-diagnostics logs <container> [--tail=N] [--since=VALUE] [--until=VALUE] [--timestamps]
  sudo /usr/local/bin/deploy-diagnostics psql -c '<SELECT или WITH>'
USAGE
    exit 2
}

[[ $EUID -eq 0 ]] || die 'запускайте wrapper только через sudo'

docker_bin=$(command -v docker) || die 'docker не найден'
[[ "$docker_bin" == /* && ! -L "$docker_bin" && -x "$docker_bin" ]] || die 'небезопасный путь к docker'
[[ "$(stat -c '%U' "$docker_bin")" == 'root' ]] || die 'docker должен принадлежать root'
docker_mode=$(stat -c '%a' "$docker_bin")
[[ "$docker_mode" =~ ^[0-7]{3,4}$ ]] || die 'не удалось проверить права docker'
(( (8#$docker_mode & 0022) == 0 )) || die 'docker доступен для записи не только root'

docker() {
    "$docker_bin" "$@"
}

command_name=${1:-}
[[ -n "$command_name" ]] || usage
shift

if command -v logger >/dev/null 2>&1; then
    logger -p authpriv.info -t deploy-diagnostics -- \
        "user=${SUDO_USER:-root} command=$command_name" || true
fi

case "$command_name" in
    ps)
        (($# == 0)) || usage
        docker ps --all --no-trunc
        ;;
    images)
        (($# == 0)) || usage
        docker image ls --all --digests --no-trunc
        ;;
    logs)
        (($# >= 1)) || usage
        container=$1
        shift
        [[ "$container" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$ ]] || die 'некорректное имя или ID контейнера'

        tail_arg='--tail=500'
        log_args=()
        for log_arg in "$@"; do
            case "$log_arg" in
                --tail=*)
                    tail_lines=${log_arg#--tail=}
                    [[ "$tail_lines" =~ ^[0-9]+$ ]] || die 'tail должен быть целым числом'
                    ((${#tail_lines} <= 5)) || die 'tail не должен превышать 10000 строк'
                    tail_lines_decimal=$((10#$tail_lines))
                    ((tail_lines_decimal <= 10000)) || die 'tail не должен превышать 10000 строк'
                    tail_arg="--tail=$tail_lines_decimal"
                    ;;
                --since=*|--until=*)
                    time_value=${log_arg#*=}
                    [[ "$time_value" =~ ^[A-Za-z0-9][A-Za-z0-9:TZ+._-]{0,63}$ ]] || \
                        die 'некорректное значение since/until'
                    log_args+=("$log_arg")
                    ;;
                --timestamps)
                    log_args+=("$log_arg")
                    ;;
                *)
                    die "запрещённый аргумент logs: $log_arg"
                    ;;
            esac
        done

        if ((${#log_args[@]})); then
            docker logs "$tail_arg" "${log_args[@]}" "$container"
        else
            docker logs "$tail_arg" "$container"
        fi
        ;;
    psql)
        (($# == 2)) || usage
        [[ "$1" == '-c' ]] || die 'psql допускает только -c с одним SQL-запросом'
        readonly_sql=$2
        [[ "$readonly_sql" != *\\* ]] || die 'psql meta-команды запрещены'
        normalized_sql=${readonly_sql^^}
        [[ "$normalized_sql" =~ ^[[:space:]]*(SELECT|WITH)[[:space:]] ]] || \
            die 'разрешены только SELECT или WITH запросы'
        sql_for_semicolon_check=$readonly_sql
        while [[ "$sql_for_semicolon_check" == *[[:space:]] ]]; do
            sql_for_semicolon_check=${sql_for_semicolon_check%?}
        done
        sql_without_trailing_semicolon=${sql_for_semicolon_check%;}
        [[ "$sql_without_trailing_semicolon" != *';'* ]] || die 'разрешён только один SQL statement'
        [[ ! "$normalized_sql" =~ (^|[^A-Z_])(INSERT|UPDATE|DELETE|MERGE|CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE|COPY|CALL|DO|VACUUM|ANALYZE|REFRESH|REINDEX|CLUSTER|INTO|SETVAL|NEXTVAL|LO_IMPORT|LO_EXPORT|PG_TERMINATE_BACKEND|PG_CANCEL_BACKEND|PG_NOTIFY|PG_LOGICAL_EMIT_MESSAGE|DBLINK_EXEC)([^A-Z_]|$) ]] || \
            die 'SQL содержит запрещённую операцию'
        [[ ! -L "$psql_wrapper" && -f "$psql_wrapper" && -x "$psql_wrapper" ]] || \
            die "$psql_wrapper недоступен"
        [[ "$(stat -c '%U' "$psql_wrapper")" == 'root' ]] || die "$psql_wrapper должен принадлежать root"
        psql_mode=$(stat -c '%a' "$psql_wrapper")
        [[ "$psql_mode" =~ ^[0-7]{3,4}$ ]] || die "не удалось проверить права $psql_wrapper"
        (( (8#$psql_mode & 0022) == 0 )) || die "$psql_wrapper доступен для записи не только root"
        export PSQLRC='/dev/null'
        export PSQL_PAGER='/bin/cat'
        export PAGER='/bin/cat'
        unset PSQL_EDITOR PSQL_EDITOR_LINENUMBER_ARG PGPASSFILE PGSERVICEFILE
        # codex-psql-ro и роль codex_ro остаются основной DB-границей;
        # локальный denylist только отсекает psql meta/shell и очевидные write-вызовы.
        exec "$psql_wrapper" -c "$readonly_sql" < /dev/null
        ;;
    *)
        die "запрещённая команда: $command_name"
        ;;
esac
DIAGNOSTICS_WRAPPER

bash -n "$wrapper_tmp"
chown root:root "$wrapper_tmp"
chmod 0755 "$wrapper_tmp"

printf '%s\n' \
    "Defaults!$diagnostics_wrapper env_reset, secure_path=\"/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\"" \
    "Defaults!$diagnostics_wrapper env_delete+=\"BASH_ENV ENV CDPATH BASHOPTS SHELLOPTS PS4 DOCKER_HOST DOCKER_CONTEXT DOCKER_CONFIG DOCKER_TLS_VERIFY DOCKER_CERT_PATH DOCKER_API_VERSION PSQLRC PSQL_PAGER PAGER LANG LANGUAGE LC_ALL LC_CTYPE LC_COLLATE\"" \
    "$deploy_user ALL=(root) NOPASSWD: $diagnostics_wrapper" \
    > "$sudoers_tmp"
chown root:root "$sudoers_tmp"
chmod 0440 "$sudoers_tmp"
visudo --check --file="$sudoers_tmp" >/dev/null

mv -f -- "$wrapper_tmp" "$diagnostics_wrapper"
mv -f -- "$sudoers_tmp" "$sudoers_file"
trap - EXIT

validate_sudo_policy yes

printf 'Пользователь %s, SSH-ключ и read-only diagnostics wrapper настроены.\n' "$deploy_user"
REMOTE_SCRIPT

printf '\nПроверяю вход пользователем %s с новым ключом...\n' "$deploy_user"

if ! access_result=$(ssh \
    -o BatchMode=yes \
    -o PasswordAuthentication=no \
    -o IdentitiesOnly=yes \
    -o ConnectTimeout=10 \
    -i "$key_path" \
    -l "$deploy_user" \
    "$ssh_alias" \
    'test "$(id -un)" = deploy && sudo -n /usr/local/bin/deploy-diagnostics ps >/dev/null && printf "ACCESS_OK deploy\n"' \
    < /dev/null); then
    die "настройка выполнена, но проверка входа или diagnostics wrapper не прошла; проверьте source IP $source_ip, sshd/StrictModes и доступность Docker"
fi

[[ "$access_result" == 'ACCESS_OK deploy' ]] || die "сервер вернул неожиданный результат проверки: $access_result"

printf '\nГотово: пользователь %s входит на %s ключом %s только с IP %s.\n' \
    "$deploy_user" "$ssh_alias" "$key_path" "$source_ip"
printf 'Диагностика: sudo /usr/local/bin/deploy-diagnostics {ps|images|logs|psql}.\n'
