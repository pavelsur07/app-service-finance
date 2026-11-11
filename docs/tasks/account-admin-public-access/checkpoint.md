## Current checkpoint
**Phase:** Stage 2 / Work item 2.1
**Status:** implementing
**Task base / Stage 1 base:** 1d0383a4f4ae31058459268876507682cf3c06e5

### Completed
- Новый task brief/plan, чистый изолированный worktree, новая ветка
  feat/account-admin-public-access на свежем origin/master.
- Найден рабочий Traefik в compose: providers.docker, http/https,
  letsEncrypt, redirect-to-https, traefik-public.
- Изучены существующие nginx/landing images, release matrix, deploy,
  browser specs и frontend CI.

### Checks and baseline
- front typecheck/lint/test/build: passed; 13 tests/app.
- test-deploy-workflow: pre-existing 1 failure (async_sync уже в шаге 0,
  тест ждёт его в шаге 4). Исправление включено в Stage 2.
- DNS отсутствовал; owner добавляет CNAME records.

### Review status
- internal pending; external pending.

### Exact next action
- Добавить runtime/image smoke contract, доказать RED, затем Docker/nginx
  и service routes. Продолжать к Stage 2 без промежуточного owner gate.

### Work item 1.1 complete
- Production Dockerfile/nginx/context allowlist созданы; оба images built.
- RED: Dockerfile absent; GREEN: account/admin builds, HTTP production contract
  (health/about/cache/CSS/JS/private paths/asset 404) passes for both viewports.
- Runtime read-only, no workspace/node_modules/package.json.
### Work item 1.2 checking
- Compose service routes/TLS/redirect and isolated network checks passed.
- Browser guard выявил assumption: http://host:80 normalizes to http://host.
  Existing spec compared raw baseURL and aborted valid requests; normalized
  origin applied, final image browser + app checks running.

### Stage 1 complete
- Оба real nginx browser suites: 4/4 each passed; typecheck/lint/MSW passed.
- Stage self-review clean, B0/I0/M0 open. Report stages/stage-1.md.
- Next: release contract tests RED, workflow integration и baseline test fix.
