# Account/admin public access — implementation plan

**Goal:** опубликовать существующие заглушки account/admin через штатный Traefik.
**Spec:** TASK.md; утверждённые домены и production-подход из чата.
**Architecture:** Node24 production build каждого app, отдельный nginx static
image; существующий Traefik завершает TLS и маршрутизирует по Docker labels.
**Execution:** inline по executing-plans; один свежий final internal reviewer.
**Task base:** 1d0383a4f4ae31058459268876507682cf3c06e5.

## Constraints / rulings
- Только существующие заглушки, никакого PHP/API/auth или клиентских данных.
- Одна ветка/PR, отдельный финальный merge+deploy. Изменения production
  Docker/CI/Traefik прямо следуют из подтверждённого владельцем поручения.
- Nginx version соответствует существующим finance images; Node24 Debian
  и Playwright 1.56.1 соответствуют существующим приложениям.
- Один общий Dockerfile/config для двух независимых images — нет копий nginx.
- Runtime только dist; build context по allowlist, без env/credentials/var.
- Новый локальный image smoke использует настоящий nginx и существующие
  browser specs, не Vite. Dev/preview команды сохраняются.
- Baseline deploy shell test устарел: sync уже перенесён на отдельный ранний
  шаг до FPM. Исправить эту проверку в Stage 2 вместе с release contract tests.

## Baseline
- make front-typecheck front-lint front-test front-build: passed, 13 tests/app.
- make test-deploy-workflow: pre-existing FAIL 1 — async_sync ожидается на
  шаге 4 вместо существующего раннего шага 0.
- DNS account/admin: absent at first read; owner is adding records.

## Stage 1: production static images и Traefik routes
Risk: HIGH-LOCAL (конфигурация для запрошенной публикации).
stage_base_commit: 1d0383a4f4ae31058459268876507682cf3c06e5
Definition of Done:
- [x] Независимые account/admin images собираются из root context allowlist.
- [x] В images только dist; home/about/fallback/assets/health и cache headers
      работают через nginx. Неизвестный asset даёт 404, HTML не кэшируется надолго.
- [x] Compose routes обоих domains на https, HTTP redirect, ACME, без host ports,
      без подключения frontend к DB/default network и без backend proxy.
- [x] Browser specs проходят против каждого nginx image desktop/mobile.
Work items:
- 1.1 — сначала runtime contract test и RED отсутствующей production image;
      Dockerfile/nginx/context; build/HTTP проверки GREEN.
- 1.2 — service labels/health, production mode browser smoke; оба apps GREEN;
      self-review Stage, report, commit/push/Draft PR.
Checks: docker build each app; image HTTP smoke; browser production mode;
compose config with fake local substitutions (never production secrets).
Reviewer focus: file:-packages/styles in build context; SPA fallback vs assets;
React runtime, exposed files, labels collision, no published dev server.

## Stage 2: build/release integration и handoff
Risk: HIGH-LOCAL (CI/CD behavior внутри явного поручения).
stage_base_commit: d5cfcecfc.
Definition of Done:
- [x] Path filters видят apps, packages, kit, Dockerfile; PR matrix и master/
      dispatch matrix включают нужные SPA images по тому же SHA.
- [x] Typecheck/lint/test и реальный image browser smoke гейтят публикацию.
- [x] Deploy явно pull/up --wait оба SPA, ошибки блокируют завершение.
- [x] Исправлен старый проверочный contract async_sync, прочие invariants сохранены.
- [x] Docs/DNS preflight, full gates, fresh internal/external review, Ready PR.
Work items:
- 2.1 — RED tests для path/matrix/deploy, затем workflow и regression test fix.
- 2.2 — документация, DNS, all checks, review/fixes, отчёт, handoff.
Checks: workflow shell tests + matrix executions with stub GitHub env; image
browser smoke; all front-* relevant checks; site-stan/cs/strict/unit at handoff.
Reviewer focus: fail-closed CI, skipped jobs, frontend-only master builds all
images, manual dispatch, cache invalidation, stdin in ssh heredoc, DNS/TLS.
