#!/usr/bin/env bash

set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
script="$repo_root/provision-vf-prod-deploy.sh"
test_root=$(mktemp -d)
trap 'rm -rf -- "$test_root"' EXIT
behavioral_skips=0

fail() {
    printf 'FAIL: %s\n' "$*" >&2
    exit 1
}

assert_contains() {
    local file=$1
    local expected=$2

    grep -Fq -- "$expected" "$file" || fail "$file does not contain: $expected"
}

assert_not_contains() {
    local file=$1
    local unexpected=$2

    if grep -Fq -- "$unexpected" "$file"; then
        fail "$file unexpectedly contains: $unexpected"
    fi
}

make_fake_ssh() {
    local bin_dir=$1

    mkdir -p "$bin_dir"
    printf '%s\n' \
        '#!/usr/bin/env bash' \
        'set -euo pipefail' \
        'if [[ "${1:-}" == "-G" ]]; then' \
        '    printf "hostname production.example.invalid\nport 22\nuser root\n"' \
        '    exit 0' \
        'fi' \
        'printf "CALL" >> "$SSH_TEST_LOG"' \
        'printf " <%s>" "$@" >> "$SSH_TEST_LOG"' \
        'printf "\n" >> "$SSH_TEST_LOG"' \
        'if [[ " $* " == *" bash -s -- "* ]]; then' \
        '    cat > "$SSH_TEST_REMOTE_SCRIPT"' \
        '    exit "${SSH_TEST_PROVISION_EXIT:-0}"' \
        'fi' \
        'printf "ACCESS_OK deploy\n"' \
        > "$bin_dir/ssh"
    chmod 0755 "$bin_dir/ssh"
}

test_diagnostics_wrapper_behavior() {
    local remote_script=$1
    local case_root=$2
    local wrapper="$case_root/deploy-diagnostics"
    local image=${PROVISION_TEST_IMAGE:-node:24.18.1-bookworm-slim}

    awk '
        /<<'\''DIAGNOSTICS_WRAPPER'\''/ { inside = 1; next }
        inside && $0 == "DIAGNOSTICS_WRAPPER" { exit }
        inside { print }
    ' "$remote_script" > "$wrapper"
    chmod 0755 "$wrapper"
    bash -n "$wrapper"

    if ! command -v docker >/dev/null 2>&1 || ! docker image inspect "$image" >/dev/null 2>&1; then
        printf 'SKIP: diagnostics wrapper behavior (%s is unavailable)\n' "$image"
        behavioral_skips=$((behavioral_skips + 1))
        return
    fi

    docker run --rm -i \
        -v "$wrapper:/usr/local/bin/deploy-diagnostics:ro" \
        "$image" bash -s <<'CONTAINER_TEST'
set -euo pipefail

printf '%s\n' \
    '#!/bin/bash' \
    'set -euo pipefail' \
    '[[ -z "${DOCKER_HOST:-}" && -z "${DOCKER_CONTEXT:-}" && -z "${DOCKER_CONFIG:-}" ]] || exit 44' \
    '[[ "${LC_ALL:-}" == "C" ]] || exit 47' \
    'printf "%s\n" "$*" >> /tmp/docker-calls' \
    > /usr/local/bin/docker
printf '%s\n' \
    '#!/bin/bash' \
    'set -euo pipefail' \
    '[[ "${PSQLRC:-}" == "/dev/null" && "${PSQL_PAGER:-}" == "/bin/cat" ]] || exit 45' \
    'if read -r _; then exit 46; fi' \
    'printf "%s\n" "$*" >> /tmp/psql-calls' \
    > /usr/local/bin/codex-psql-ro
chmod 0755 /usr/local/bin/docker /usr/local/bin/codex-psql-ro

DOCKER_HOST='tcp://attacker.invalid:2375' \
DOCKER_CONTEXT='attacker' \
DOCKER_CONFIG='/tmp/attacker' \
/usr/local/bin/deploy-diagnostics ps
/usr/local/bin/deploy-diagnostics images
/usr/local/bin/deploy-diagnostics logs app
/usr/local/bin/deploy-diagnostics logs app --tail=009 --since=10m --timestamps
/usr/local/bin/deploy-diagnostics psql -c 'SELECT 1'
/usr/local/bin/deploy-diagnostics psql -c 'SELECT 1;   '

grep -qxF 'ps --all --no-trunc' /tmp/docker-calls
grep -qxF 'image ls --all --digests --no-trunc' /tmp/docker-calls
grep -qxF 'logs --tail=500 app' /tmp/docker-calls
grep -qxF 'logs --tail=9 --since=10m --timestamps app' /tmp/docker-calls
grep -qxF -- '-c SELECT 1' /tmp/psql-calls

if /usr/local/bin/deploy-diagnostics exec app id >/dev/null 2>&1; then exit 51; fi
if /usr/local/bin/deploy-diagnostics inspect app >/dev/null 2>&1; then exit 52; fi
if /usr/local/bin/deploy-diagnostics logs app --tail=10001 >/dev/null 2>&1; then exit 53; fi
if /usr/local/bin/deploy-diagnostics logs ../bad >/dev/null 2>&1; then exit 54; fi
if /usr/local/bin/deploy-diagnostics logs app --follow >/dev/null 2>&1; then exit 55; fi
if /usr/local/bin/deploy-diagnostics psql -f /etc/passwd >/dev/null 2>&1; then exit 56; fi
if /usr/local/bin/deploy-diagnostics psql -c '\! id' >/dev/null 2>&1; then exit 57; fi
if /usr/local/bin/deploy-diagnostics psql -c 'SELECT 1; DELETE FROM users' >/dev/null 2>&1; then exit 58; fi
if /usr/local/bin/deploy-diagnostics psql -c 'SELECT 1 INTO forbidden_table' >/dev/null 2>&1; then exit 59; fi
if /usr/local/bin/deploy-diagnostics psql -c "SELECT nextval('forbidden_sequence')" >/dev/null 2>&1; then exit 60; fi
CONTAINER_TEST
}

test_remote_provisioning_behavior() {
    local remote_script=$1
    local image=${PROVISION_TEST_IMAGE:-node:24.18.1-bookworm-slim}
    local key_one_b64
    local key_two_b64

    if ! command -v docker >/dev/null 2>&1 || ! docker image inspect "$image" >/dev/null 2>&1; then
        printf 'SKIP: remote provisioning behavior (%s is unavailable)\n' "$image"
        behavioral_skips=$((behavioral_skips + 1))
        return
    fi

    key_one_b64=$(printf '%s' \
        'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFirstManagedKeyForLocalTest000000000000000000' \
        | base64 | tr -d '\r\n')
    key_two_b64=$(printf '%s' \
        'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAISecondManagedKeyForLocalTest00000000000000000' \
        | base64 | tr -d '\r\n')

    docker run --rm -i \
        -v "$remote_script:/tmp/remote-provision:ro" \
        "$image" bash -s -- "$key_one_b64" "$key_two_b64" <<'CONTAINER_TEST'
set -euo pipefail

key_one_b64=$1
key_two_b64=$2

install -d -m 0755 /etc/sudoers.d /usr/local/bin /usr/local/sbin
printf '%s\n' '#!/bin/bash' 'exit 0' > /usr/local/bin/docker
printf '%s\n' '#!/bin/bash' 'exit 0' > /usr/local/bin/codex-psql-ro
printf '%s\n' '#!/bin/bash' 'exit 0' > /usr/local/sbin/visudo
printf '%s\n' \
    '#!/bin/bash' \
    'set -euo pipefail' \
    '[[ "$*" == "-n -l -U deploy" ]] || exit 91' \
    'if [[ -e /tmp/deploy-extra-sudo ]]; then' \
    '    printf "User deploy may run the following commands on test-host:\n    (ALL : ALL) ALL\n"' \
    'elif [[ -f /etc/sudoers.d/deploy-diagnostics ]]; then' \
    '    printf "User deploy may run the following commands on test-host:\n    (root) NOPASSWD: /usr/local/bin/deploy-diagnostics\n"' \
    'else' \
    '    printf "User deploy is not allowed to run sudo on test-host.\n"' \
    '    exit 1' \
    'fi' \
    > /usr/local/sbin/sudo
chmod 0755 \
    /usr/local/bin/docker \
    /usr/local/bin/codex-psql-ro \
    /usr/local/sbin/visudo \
    /usr/local/sbin/sudo

bash /tmp/remote-provision deploy 217.198.13.171 "$key_one_b64"
bash /tmp/remote-provision deploy 217.198.13.171 "$key_two_b64"

auth_file='/home/deploy/.ssh/authorized_keys'
[[ $(grep -c 'managed-by=provision-vf-prod-deploy' "$auth_file") -eq 1 ]]
grep -q 'SecondManagedKeyForLocalTest' "$auth_file"
if grep -q 'FirstManagedKeyForLocalTest' "$auth_file"; then exit 61; fi
grep -q '^restrict,from="217.198.13.171",pty ' "$auth_file"
[[ "$(stat -c '%U:%G %a' /home/deploy)" == 'root:root 755' ]]
[[ "$(stat -c '%U:%G %a' /home/deploy/.ssh)" == 'root:root 755' ]]
[[ "$(stat -c '%U:%G %a' "$auth_file")" == 'root:root 644' ]]
if runuser -u deploy -- bash -c 'printf "%s\n" attacker >> /home/deploy/.ssh/authorized_keys' \
    >/dev/null 2>&1; then
    exit 70
fi
[[ "$(stat -c '%U:%G %a' /etc/deploy-diagnostics.user)" == 'root:root 644' ]]
[[ "$(stat -c '%U:%G %a' /usr/local/bin/deploy-diagnostics)" == 'root:root 755' ]]
[[ "$(stat -c '%U:%G %a' /etc/sudoers.d/deploy-diagnostics)" == 'root:root 440' ]]
grep -qxF 'deploy ALL=(root) NOPASSWD: /usr/local/bin/deploy-diagnostics' \
    /etc/sudoers.d/deploy-diagnostics

if bash /tmp/remote-provision deploy 999.198.13.171 "$key_two_b64" >/dev/null 2>&1; then
    exit 64
fi

printf '%s\n' 'ssh-ed25519 AAAAForeignKey foreign@local' >> "$auth_file"
if bash /tmp/remote-provision deploy 217.198.13.171 "$key_two_b64" >/dev/null 2>&1; then
    exit 62
fi

sed -i '/AAAAForeignKey/d' "$auth_file"
mv /etc/deploy-diagnostics.user /etc/deploy-diagnostics.user.saved
if bash /tmp/remote-provision deploy 217.198.13.171 "$key_two_b64" >/dev/null 2>&1; then
    exit 68
fi
mv /etc/deploy-diagnostics.user.saved /etc/deploy-diagnostics.user

touch /tmp/deploy-extra-sudo
if bash /tmp/remote-provision deploy 217.198.13.171 "$key_two_b64" >/dev/null 2>&1; then
    exit 69
fi
rm /tmp/deploy-extra-sudo

groupadd docker
usermod -aG docker deploy
if bash /tmp/remote-provision deploy 217.198.13.171 "$key_two_b64" >/dev/null 2>&1; then
    exit 65
fi
gpasswd -d deploy docker >/dev/null

printf '%s\n' 'deploy:temporary-test-password' | chpasswd
if bash /tmp/remote-provision deploy 217.198.13.171 "$key_two_b64" >/dev/null 2>&1; then
    exit 66
fi
passwd --lock deploy >/dev/null

chmod 0775 /usr/local/bin
if bash /tmp/remote-provision deploy 217.198.13.171 "$key_two_b64" >/dev/null 2>&1; then
    exit 67
fi
CONTAINER_TEST
}

test_successful_provisioning_flow() {
    local case_root="$test_root/success"
    local output="$case_root/output"
    local log="$case_root/ssh.log"
    local remote_script="$case_root/remote-script"

    mkdir -p "$case_root/home"
    make_fake_ssh "$case_root/bin"

    if ! HOME="$case_root/home" \
        PATH="$case_root/bin:$PATH" \
        SSH_TEST_LOG="$log" \
        SSH_TEST_REMOTE_SCRIPT="$remote_script" \
            "$script" > "$output" 2>&1; then
        printf '%s\n' 'Provisioning output:' >&2
        sed 's/^/  /' "$output" >&2
        fail 'successful provisioning scenario failed'
    fi

    [[ -s "$case_root/home/.ssh/vf_prod_deploy" ]] || fail 'private key was not created'
    [[ -s "$case_root/home/.ssh/vf_prod_deploy.pub" ]] || fail 'public key was not created'
    [[ $(grep -c '^CALL' "$log") -eq 2 ]] || fail 'expected one provisioning call and one verification call'
    assert_contains "$log" '<vf-prod>'
    assert_contains "$log" '<217.198.13.171>'
    assert_contains "$log" '<PreferredAuthentications=password,keyboard-interactive>'
    assert_contains "$log" '<PubkeyAuthentication=no>'
    assert_contains "$log" '<PasswordAuthentication=yes>'
    assert_contains "$log" '<KbdInteractiveAuthentication=yes>'
    assert_contains "$log" '<ControlMaster=no>'
    assert_contains "$log" '<ControlPath=none>'
    assert_contains "$log" '<-l>'
    assert_contains "$log" '<deploy>'
    assert_contains "$remote_script" 'useradd --create-home --shell /bin/bash'
    assert_contains "$remote_script" 'passwd --lock "$deploy_user"'
    assert_contains "$remote_script" 'authorized_keys'
    assert_contains "$remote_script" 'restrict,from=\"${source_ip}\",pty'
    assert_contains "$remote_script" 'managed-by=provision-vf-prod-deploy'
    assert_contains "$remote_script" '[[ ! -L "$deploy_home" ]]'
    assert_contains "$remote_script" '[[ ! -L "$ssh_dir" ]]'
    assert_contains "$remote_script" '[[ ! -L "$auth_file" ]]'
    assert_contains "$remote_script" '/usr/local/bin/deploy-diagnostics'
    assert_contains "$remote_script" '#!/bin/bash'
    assert_contains "$remote_script" 'unset DOCKER_HOST DOCKER_CONTEXT DOCKER_CONFIG'
    assert_contains "$remote_script" 'secure_path=\"/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\"'
    assert_contains "$remote_script" 'protected_dir_mode='
    assert_contains "$remote_script" 'docker ps --all --no-trunc'
    assert_contains "$remote_script" 'docker image ls --all --digests --no-trunc'
    assert_contains "$remote_script" 'docker logs'
    assert_contains "$remote_script" '/usr/local/bin/codex-psql-ro'
    assert_contains "$remote_script" 'visudo --check'
    assert_contains "$remote_script" 'NOPASSWD: $diagnostics_wrapper'
    assert_contains "$remote_script" 'for forbidden_group in docker sudo wheel'
    assert_not_contains "$remote_script" 'usermod -aG docker'
    assert_not_contains "$remote_script" 'docker exec'
    assert_contains "$remote_script" '${#tail_lines} <= 5'
    assert_contains "$remote_script" '10#$tail_lines'
    assert_contains "$remote_script" 'command=$command_name" || true'
    assert_not_contains "$remote_script" '--follow'
    assert_not_contains "$remote_script" 'ufw '
    assert_contains "$log" 'deploy-diagnostics ps'
    assert_contains "$output" 'Готово: пользователь deploy'
    test_diagnostics_wrapper_behavior "$remote_script" "$case_root"
    test_remote_provisioning_behavior "$remote_script"
}

test_provisioning_failure_is_not_masked() {
    local case_root="$test_root/failure"
    local output="$case_root/output"
    local log="$case_root/ssh.log"
    local remote_script="$case_root/remote-script"

    mkdir -p "$case_root/home"
    make_fake_ssh "$case_root/bin"

    if HOME="$case_root/home" \
        PATH="$case_root/bin:$PATH" \
        SSH_TEST_LOG="$log" \
        SSH_TEST_REMOTE_SCRIPT="$remote_script" \
        SSH_TEST_PROVISION_EXIT=23 \
            "$script" > "$output" 2>&1; then
        fail 'script succeeded after provisioning SSH failed'
    fi

    [[ $(grep -c '^CALL' "$log") -eq 1 ]] || fail 'verification ran after provisioning failure'
}

test_local_key_symlink_is_rejected_before_ssh() {
    local case_root="$test_root/key-symlink"
    local output="$case_root/output"

    mkdir -p "$case_root/home/.ssh" "$case_root/real-keys" "$case_root/bin"
    ssh-keygen -q -t ed25519 -N '' -f "$case_root/real-keys/deploy" -C 'test@local'
    ln -s "$case_root/real-keys/deploy" "$case_root/home/.ssh/vf_prod_deploy"
    ln -s "$case_root/real-keys/deploy.pub" "$case_root/home/.ssh/vf_prod_deploy.pub"
    make_fake_ssh "$case_root/bin"

    if HOME="$case_root/home" \
        PATH="$case_root/bin:$PATH" \
        SSH_TEST_LOG="$case_root/ssh.log" \
        SSH_TEST_REMOTE_SCRIPT="$case_root/remote-script" \
            "$script" > "$output" 2>&1; then
        fail 'script accepted symlinked local deployment keys'
    fi

    assert_contains "$output" 'не должен быть symlink'
    [[ ! -e "$case_root/ssh.log" ]] || fail 'SSH ran after local key symlink was detected'
}

test_multiline_public_key_is_rejected_before_ssh() {
    local case_root="$test_root/multiline-key"
    local output="$case_root/output"

    mkdir -p "$case_root/home/.ssh" "$case_root/bin"
    ssh-keygen -q -t ed25519 -N '' -f "$case_root/home/.ssh/vf_prod_deploy" -C 'test@local'
    printf '%s\n' 'ssh-ed25519 AAAAInjectedKey unrestricted@local' >> \
        "$case_root/home/.ssh/vf_prod_deploy.pub"
    make_fake_ssh "$case_root/bin"

    if HOME="$case_root/home" \
        PATH="$case_root/bin:$PATH" \
        SSH_TEST_LOG="$case_root/ssh.log" \
        SSH_TEST_REMOTE_SCRIPT="$case_root/remote-script" \
            "$script" > "$output" 2>&1; then
        fail 'script accepted a multiline public key file'
    fi

    assert_contains "$output" 'ровно одну строку'
    [[ ! -e "$case_root/ssh.log" ]] || fail 'SSH ran after multiline public key was detected'
}

test_successful_provisioning_flow
test_provisioning_failure_is_not_masked
test_local_key_symlink_is_rejected_before_ssh
test_multiline_public_key_is_rejected_before_ssh

if ((behavioral_skips > 0)); then
    if [[ "${ALLOW_PROVISION_TEST_SKIPS:-0}" != '1' ]]; then
        fail "$behavioral_skips security behavior case(s) skipped; preload PROVISION_TEST_IMAGE or explicitly set ALLOW_PROVISION_TEST_SKIPS=1"
    fi
    printf 'PASS: provision-vf-prod-deploy.sh (%d behavioral cases explicitly allowed to skip)\n' "$behavioral_skips"
else
    printf 'PASS: provision-vf-prod-deploy.sh\n'
fi
