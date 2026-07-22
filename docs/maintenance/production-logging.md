# Production logging and operational artifacts

## Runtime logs

Symfony, Messenger workers and supercronic write production logs to container
stdout/stderr. Docker Compose applies the shared `x-logging` policy to every
service:

```yaml
driver: json-file
options:
    max-size: 10m
    max-file: 3
```

Use `docker compose -f docker-compose.prod.yml logs <service>` to read them.
Do not redirect cron output to a host file: a missing or unwritable target makes
the shell fail before the scheduled command starts.

Container retention is size-based, not time-based: each service keeps at most
three 10 MB JSON log files. On a busy worker this can be much less than 14 days.

### First rollout

Docker applies a changed logging policy only after recreating a container. On
the first deploy after this configuration change, the final Compose step also
recreates `traefik`, `site-redis` and `site-messenger-worker-wb-finance`.
Schedule the rollout for a maintenance window: expect a brief ingress
interruption, Redis connection resets while services reconnect, and worker
restart with redelivery if a message was in flight. Later deploys do not recreate
them unless their effective configuration changes again.

### Channel routing

The production `marketplace_ads` channel emits JSON at `info` level and above to
container stderr; debug-level payload dumps and poller heartbeats remain
development-only. The channel stays excluded from Sentry/GlitchTip. Super-admin
log instructions and direct operator diagnostics use the relevant containers.
This command is for an Owner or DevOps session; the restricted `codex-prod` user
must not run arbitrary Docker commands and only has the approved wrappers. Run
it from the deployed `current` directory that contains `docker-compose.prod.yml`:

```bash
docker compose -f docker-compose.prod.yml logs --since 1h --tail 200 \
    site-php-fpm site-messenger-worker-ads \
    site-messenger-worker-pipeline scheduler 2>&1 \
    | grep -F marketplace_ads
```

Manual `site-php-cli` runs write to the invoking terminal. Redirect them to the
maintenance log below when their output must be retained.

Existing `marketplace_ads-*.log` files in the `site_var_log` volume stop changing
after this switch. Their inventory and removal require a separate production
cleanup gate.

## Manual operations

Never run an operation with `/root` as its working directory and never redirect
output to a relative path. Use these locations:

| Artifact | Location | Retention |
|---|---|---|
| Manual command logs | `/var/log/app-service-finance/maintenance/maintenance.log` | 14 rotations |
| Database and queue backups | `/var/backups/app-service-finance/` | Set per operation |
| Temporary JSON/text audits | `/var/tmp/app-service-finance.*` | Remove after validation |

The following commands and examples run only in an Owner or DevOps root session,
after the production gate. The restricted `codex-prod` user cannot write these
files. Create the persistent directories with:

```bash
install -d -o root -g root -m 0750 /var/log/app-service-finance/maintenance
install -d -o root -g root -m 0700 /var/backups/app-service-finance
```

Use one append target with a timestamped run marker and restrictive permissions.
Replace `your_command` and `operation-name` before running the example:

```bash
umask 027
run_id=$(date -u +%Y%m%dT%H%M%SZ)-$$
log_file="/var/log/app-service-finance/maintenance/maintenance.log"
{
    printf '\n=== %s %s ===\n' "$run_id" 'operation-name'
    your_command
} >>"$log_file" 2>&1
```

Backups remain separate timestamped files under
`/var/backups/app-service-finance/`; they are not written into the maintenance
log and require an operation-specific retention decision.

## Host log rotation

After the production gate, install the repository configuration from the
deployed `current` directory:

```bash
install -o root -g root -m 0644 \
    docker/logrotate/app-service-finance-maintenance \
    /etc/logrotate.d/app-service-finance-maintenance
```

Validate it without rotating files:

```bash
logrotate --debug /etc/logrotate.d/app-service-finance-maintenance
logrotate --debug /etc/logrotate.conf
```

The first command checks the fragment; the second checks it together with the
host defaults. `copytruncate` keeps a long-running command attached to the same
append target across rotation. `maxsize 10M` is evaluated when logrotate runs;
it is not a hard intra-day cap for a runaway manual command.

## Existing files in `/root`

Do not delete or overwrite them during logging setup. Before a separate cleanup
gate, record filename, size, modification time and checksum; classify logs,
backups, scripts and temporary audits; then move approved files to a dated
quarantine. Deletion requires its own explicit production approval.
