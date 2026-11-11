### Stage 1: production static images и Traefik routes — DONE

Risk: HIGH-LOCAL; stage_base_commit: `1d0383a4f4ae31058459268876507682cf3c06e5`.
Work items: 1.1, 1.2.

- Общий multi-stage Dockerfile: Node24 Debian → nginx той же версии, что
  finance. Два независимых images, только dist в runtime, build context allowlist.
- nginx: SPA fallback, /health проверяет наличие index.html, no-cache HTML,
  immutable assets, 404 для missing assets и скрытых файлов.
- Compose: account/admin на traefik-public, отдельные Host routers,
  letsEncrypt и HTTP redirect; нет host ports, backend network или API proxy;
  runtime read-only с tmpfs только для nginx cache/pid.
- Production browser mode и image runner в site/bin: тот же shell contract
  и дополнительные HTTP/cache/asset/private-path проверки для настоящего nginx.

Checks:
- RED: отсутствующий Dockerfile; GREEN: оба images built (APP=account/admin).
- Browser image checks: account 4/4, admin 4/4 passed (desktop/mobile).
- Существующий shell spec сначала ошибочно блокировал port 80: raw baseURL
  сравнивался с URL.origin без default port. Исправление нормализует origin;
  regression RED→GREEN на реальном image. UI/source bundle не менялся.
- typecheck/lint/Prettier, MSW tests 13/13 на app — passed.
- Compose isolation/Host/TLS/redirect проверены на config --no-interpolate;
  bash -n и git diff --check — passed.
- Baseline test-deploy-workflow red (устаревшее ожидание async_sync в [4/5]);
  исправляется вместе с release contracts в Stage 2.

Internal review: complete stage diff; BLOCKER 0 / IMPORTANT 0 / MINOR 0 open.
CSS tokens/classes и старые Vite entry не менялись; bundle sizes прежние
(~73.63 kB CSS, ~267.43 kB JS на app). Миграций/PHP/API нет.
External review: Stage source diff <500 lines; required at final handoff only.
Definition of Done: все пункты Stage 1 выполнены; DNS отдельно подтверждается
перед handoff (владелец сообщил, что добавил записи).
Next: Stage 2 автоматически.
