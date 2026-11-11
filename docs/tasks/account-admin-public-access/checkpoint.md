## Current checkpoint
**Phase:** handoff
**Status:** done (implementation/reviews; production awaits approval)
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
- internal fresh-session review clean: B0/I0/M0/F0; external round 1 running.

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

### Work item 2.1 complete
- RED: 10 failed matrix/filter/release assertions before workflow integration.
- GREEN: 6 release contract tests, actual Bash matrix execution for PR/master/
  dispatch/docs variants; publishing regression RED→GREEN (push after browser).
- spa-checks gate, selected PR images, all 9 master/dispatch images, loaded
  image browser smoke, post-test push; explicit pull/up --wait account/admin.
- Existing async_sync order test fixed: validates consumer before FPM;
  first test parser needed awk to consume its input under pipefail (SIGPIPE fixed).
- actionlint 1.7.12 passed for both modified workflows.
- make front-prod-build front-prod-test passed: 4 browser tests per real image.
- Public DNS now has answers for both domains; local resolver still negative cached.
- Full handoff PHP gates started once; no PHP integration paths changed.
### Exact next action
- Finish docs, full frontend checks and fresh internal/external handoff reviews.

### Handoff verification state
- Work item 2.2 docs complete, all frontend/release/image checks passed.
- Public DNS account/admin now match app; local cache may lag.
- Fresh internal review B0/I0/M0/F0; external round1 running with full new-path diff.
- Full PHP gates still running. Final external review log:
  /tmp/finance-public-external-review.log; gates /tmp/finance-public-handoff-php.log.
- Do not merge PR #2967 until owner merge+deploy approval. Next: collect checks,
  resolve review findings, Stage2 report/commit/push/update Ready/handoff.

### Stage 2 / handoff complete
- Full PHP gates all green: stan, cs, strict, unit 4611/25547.
- External round1: IMPORTANT1 (SPA interrupted backend rollout) fixed with
  regression RED→GREEN; MINOR2 fixed (runner path, registry access docs).
  Fixed without re-run; B0/I0 open. Fresh internal B0/I0, fixes self-review clean.
- Runner now bin/frontend-production-test.sh. Both final browser suites 4/4;
  wrong-image negative case proven red, correct account/admin identity green.
- Actionlint/release contracts/current frontend checks passed after fixes.
- Current CI status is in PR #2967 checks; implementation handoff is prepared.

### Exact next action at handoff
- Verify PR #2967 CI; ask owner once: merge and deploy #2967.
- Approval for #2965 belongs to the previous task, not this publication PR.
- After approval run merge→CI/build/deploy→read-only wrappers + real public
  HTTPS/HTTP redirect/browser acceptance. Preserve remote task branch.
