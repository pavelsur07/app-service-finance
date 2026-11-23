# Stage 2 — Tailwind v4 и стили apps

**Статус:** DONE — Stage2 Ready/handoff; ожидается решение merge+deploy #3030.
**Risk:** HIGH-LOCAL (apps), HIGH-EXTERNAL (production build/CI).
**Stage base commit:** `fbfcc71c88984d560b4cf80e56310031cdd11eff`.
**Branch:** `feat/ds-v3-stage-2`.
**PR:** [#3030](https://github.com/pavelsur07/app-service-finance/pull/3030) — Ready, base master.
**Implementation commit:** `404c35c8b`.
**Approved pipeline patch:** `f2df0325f`.
**Integration base:** `87e6a7338254cc2942ac908b679e8e390817eec3`.
**Tested code head:** `f82ec6d78f1055147056128fd24cddd21c6e2f8b`.
**Work items:** 2.1–2.3 DONE, 2.4 approval/application/CI pending.

## Изменения

- Tailwind/@tailwindcss/vite 4.3.5, DS dependency обоих apps; preflight и тема
  по v3/tailwind/app.css, namespaces цветов/кеглей/радиусов/теней/breakpoints
  очищены; значения только через --ds- переменные.
- App CSS entry импортирует DS и сканирует только свой src; тесты исключены.
  DS сканирует components, также исключая test.ts/tsx. Нет sibling/site scan.
- Manrope variable 200–800 self-hosted (164700 B), кириллица/латиница,
  public OFL через общий DS publicDir. Body 13px, tabular-nums, rem 16px.
- Login/home/about/404/error/loading используют CSS DS. UI JS wrappers
  Button/EmptyState/ErrorBoundary временно прежние до Stage 3; их старый CSS
  не импортируется. Поля 38px, кнопки 30/36/44px — как в источнике.
- Vite больше не разрешает fs-доступ к старому CSS; auth/session/CSRF/API и
  финансовые правила не менялись. site/ tracked diff пуст.
- После отдельного одобрения Владельца 2026-11-16 применён [точный patch](../proposals/stage-2-production.patch)
  для Dockerfile/build allowlist, CI filter/install/cache, front-install и
  release-contract test. Пять файлов побайтно совпали с reviewed/tested candidate.
  [История согласования](../proposals/README.md).

## Файлы

`apps/packages/ds/{package*,src/styles*,src/fonts,public/fonts}`, оба apps
`package*,vite.config,index.html,src/styles.css,src/app,src/pages`, account
CompanyPicker, SessionStatus обоих; `apps/README.md`, `CLAUDE.frontend.md`,
документация задачи/proposal/review. Основные изменения — CSS и сборка;
данные/маршруты/бизнес-логика не менялись.

## DoD

- [x] Baseline + тесты до реализации, существующее поведение сохранено.
- [x] TW/preflight/DS theme, default utilities запрещены, discovery ограничен.
- [x] Локальный Manrope/OFL, light-only, body/rem/числа, видимый focus.
- [x] Полные локальные apps gates и preview/production-candidate E2E.
- [x] 15 screenshots before + 15 after, 1440×900, просмотр login/about/home.
- [x] Полный независимый internal review, фиксы, отдельный Codex REVIEW_GREEN.
- [x] Named approval §3.3 и применение production patch.
- [x] Фактический CI зелёный (43814469343).
- [x] Ready/handoff; merge+deploy ожидает решения Владельца.

## Проверки

Baseline `make front-test`: account 43/43, admin 39/39 PASS.
TDD: новые 5 Vitest RED (тема/шрифт/подключение); 2 browser RED на старом UI
(Inter вместо Manrope, граница input 1.67:1). После реализации GREEN.

- `make front-typecheck front-lint front-test front-build` — PASS, оба apps.
  Vitest: account 48, admin 44, включая 13 DS tests.
- После internal MINOR: contract RED2 → GREEN5; оба app build/typecheck
  повторены, compiled CSS не содержит `.visible` из colocated test fixtures.
  Focused Prettier и git diff --check PASS.
- Preview: на уже собранных preview servers, `FRONT_MODE=preview docker compose
  ... playwright npm run test:e2e` — account 14 PASS/4 skipped,
  admin 12 PASS/4 skipped. Skips: 4 production-only и 4 desktop-reference
  проверки в mobile project; прежние mobile tests проходят.
- Новые browser tests: Manrope loaded, no external font requests, OFL public,
  body 13px/tabular-nums/rem16, light при OS dark; link/input outline 2px,
  input border ≥3:1, body/primary text ≥4.5:1, button navy/36px.
- `npm run check:generated` — PASS; source JSON и generated CSS/TS не менялись.
- Production **candidate вне repo**, Dockerfile и .dockerignore из proposal:
  два `docker build ... -f /tmp/vf-ds-v3-stage2-proposal/docker/frontend/Dockerfile`
  — PASS, только выбранный app + DS, чистые npm ci внутри builder.
  `APP=... IMAGE=vf-finance/...:ds-v3-stage2 bin/frontend-production-test.sh`:
  account 16 PASS/2 skipped, admin 14 PASS/2 skipped. Последние builds/smoke
  повторены после исключения test sources; logs в /tmp proposal directory.
- CI filter contract расширен двумя DS paths в **candidate** тесте:
  RED на старом workflow → GREEN (6 tests) на candidate.
  `DEPLOY_WORKFLOW=/tmp/.../deploy.yml make test-deploy-workflow` PASS
  (Bash invariants + 6 existing tools tests), `bash -n` front script PASS,
  `git apply --check` proposal PASS. Реальный pipeline не менялся.

Исправленные проверки: первый E2E обращался к dev вместо preview; исправлен
режим. Скриншоты вынесены из очищаемого Playwright test-results. Compile-тест
учитывает inlining 4px spacing. Input height уточнён по токену 38px, button
medium отдельно 36px; токены не переписывались. В OFL удалён trailing space
без изменения текста условий; binary font не модифицирован.

## Визуал / bundle

15/15 before/after пар изменились ожидаемо. Артефакты вне Git:
`/tmp/vf-ds-v3-stage2-artifacts/{before,after}/{account,admin}/*-1440.png`,
`comparison.json`, `bundle-sizes.json`. Цвета/типографика/контролы по v3.4;
полный AppShell/Sidebar не реализуется до Stage 4. Auth/404/loading/error
без выделенных v3 листов — минимальные композиции, TODO(ds) в исходниках.

CSS обоих apps: 73766 B → 23189 B, JS вырос примерно на 170 B;
добавлен общий variable font 164700 B и OFL 4386 B. Новая сетевая стоимость
шрифта ожидаема и объяснена self-hosting, внешние запросы отсутствуют.
Шрифт и CSS/JS fingerprinted, Nginx smoke проверил реальную поставку/кэш.

## Ревью

- Internal: свежий независимый контекст полного diff + candidate;
  BLOCKER 0 / IMPORTANT 0 / MINOR 1, исправлено; повторная проверка — 0 open.
- Отдельная Codex-сессия (Claude недоступен), 1 раунд: REVIEW_GREEN,
  BLOCKER/IMPORTANT/MINOR 0/0/0. Полный текстовый diff, исходный эталон и
  production candidate переданы через stdin; тесты reviewer не запускал,
  lock/binary напрямую не проверены. [Дословный отчёт](../reviews/codex-stage-2.md).

Review metrics: internal BLOCKER/IMPORTANT 0/0, MINOR 1 fixed; external
BLOCKER/IMPORTANT 0/0, 1 round REVIEW_GREEN.

## Риски

- DS npm audit: HIGH/CRITICAL 0, moderate4/low2 — те же транзитивные build-only
  зависимости Stage 1, audit exit1 сохранён и не скрывается. Существующие
  версии пакетов apps в lock не изменялись; добавлены только DS/Tailwind deps.
- Proposal применён после approval, фактический CI зелёный; production
  rollout Stage2 ожидает решения Владельца.
- Источник эталона по-прежнему в PR #3022; provenance записан в plan/README.

## Применение согласованного patch / продолжение

2026-11-16 Владелец разрешил применение stage-2-production.patch.
`git apply --check` + `git apply` выполнены, пять файлов побайтно совпали
с ранее reviewed candidate. Actual `make test-deploy-workflow test-frontend-release`
PASS; `make front-install APP=admin` свежо установил DS перед app,
`make front-typecheck front-test APP=admin` PASS (44 tests).

Предыдущий CI43809997304 падал ожидаемо до согласования: TS2307 tailwindcss
в обоих apps, ERR_MODULE_NOT_FOUND style-dictionary в real-auth preview.
Корневая причина — отсутствие установки DS; patch её устраняет.
[CI43814469343](https://github.com/pavelsur07/app-service-finance/actions/runs/43814469343)
SUCCESS на f82ec6d78: оба SPA checks, images/browser smoke и real-auth
dev/preview/production HTTPS, PHP checks/functional/integration/API types/
empty-db migrations. Никаких новых findings. После review UI runtime/lock
не менялись; actual config побайтно совпал с candidate.
Ветка синхронизирована со свежим master, полный task diff к87e6a7338
не содержит site/ изменений. Последующие изменения — итоговая документация.
Stage2 Ready; следующий шаг — решение merge+deploy #3030. Stage 3–5 ещё не начаты, production deploy Stage 2
не выполнялся. Docs PR #3028 merged (87e6a7338), workflow SUCCESS;
build/deploy штатно skipped для docs-only изменений.
