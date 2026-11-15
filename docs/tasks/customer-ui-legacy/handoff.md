# customer-ui-legacy — handoff

**Branch:** `fix/customer-ui-legacy`.
**PR:** https://github.com/pavelsur07/app-service-finance/pull/3021 — Ready,
base `master` проверена. **Последний source commit:** `6f380a39e`.
Все CI checks этого source commit — pass/skipped, включая SPA real-auth isolation.

## Результат

- Stage 1 — `/finance` всегда legacy; cookie app/vf и прежний POST выбора UI
  больше не включают новый интерфейс. CSRF/redirect/cookie flags сохранены.
- Stage 2 — шесть API-маршрутов, результат создания и ошибки на Tabler;
  сохранены permissions presets, копирование/очистка секрета, paging и IDOR guards.
- Stage 3 — аудит, независимое внутреннее review, CI и отдельное Codex review
  завершены. REVIEW_GREEN; открытых BLOCKER/IMPORTANT нет.
- UI admin и apps не менялись; общие assets и app/_ui pagination/tabs для
  админки сохранены. Auth, бюджет и Unit уже legacy, остались без правок.

## Файлы

- `site/src/Finance/Controller/HomeController.php` — legacy dashboard.
- `site/src/Shared/{Controller/UiModeController,Service/UiModeResolver}.php`
  — совместимость прежних запросов и настроек интерфейса.
- `site/templates/api/settings/` — Tabler markup и default Bootstrap form theme.
- `site/templates/_layout/legacy.html.twig` — удалён переключатель UI.
- Удалены неиспользуемые customer app-layout/shell/dashboard/flash и API
  custom form theme; общие компоненты админки сохранены.
- `site/tests/` — regression UI/compatibility, актуальные billing/flash
  assertions; HTTPS-aware CSRF test helper с прежним default поведением.

## Проверки

- Baseline: 39 tests / 279 assertions — OK. Red/green доказательства — Stage reports.
- Module: 241 tests / 3763 assertions — OK; последние review fixes на fresh DB:
  17 tests / 207 assertions — OK.
- Frontend lint/build — OK; unit 152 tests — OK. Browser: 9 Symfony-rendered
  HTML fixtures × desktop/mobile — 18 OK; presets, Tab, copy, pagehide cleanup.
  Секрет в fixture заменён placeholder, DB transaction откатана.
- `make site-stan` — no errors, 4274 files.
- `make site-cs-check` / `make site-cs-strict-types` — 0/4310 files to fix.
- `make site-test-unit` — 4822 tests / 26764 assertions, OK.
- `make site-cs-twig` — no violations, 399 templates valid.
- `make site-test` initial attempt — vendor write permission в Composer.
  Пропуск install с установленными locked deps выявил пустой справочник тарифов
  в старой test DB и write permission var/storage. Изолированный DB diagnostic
  воспроизвёл storage failure в CashFileImportHandlerFlowTest. Первый неполный
  прогон завершился 143; второй остановлен после диагностики. Зелёными не считаются.
- Контрольный полный прогон в изолированной local DB под user 0 контейнера:
  **OK, 9271 tests / 52215 assertions, 07:45.022, exit 0**. Команда:
  `docker exec --user 0 -e APP_ENV=test -e APP_DEBUG=1 -e XDEBUG_MODE=off vf-customer-legacy-php php var/customer-ui-smoke/isolated-tests.php full`.
  Лог: `site/var/customer-ui-smoke/full-root.log` (ignored local artifact).
- CI source `6f380a39e`: все checks pass/skipped. Integration —
  2967 tests / 14111 assertions; unit и три functional shards — pass;
  frontend types, images, Twig, style и real-auth isolation — pass.

## Review

- Внутреннее: Stage 2 IMPORTANT 1 fixed (checkbox technical labels), финальное
  независимое IMPORTANT 1 fixed (retired flash-shell test). Fixes и поздние
  CI validation assertions проверены reviewer повторно; открытых B/I нет.
- Метрики review: internal BLOCKER 0 / IMPORTANT 2 found and fixed;
  external BLOCKER 0 / IMPORTANT 0, 1 завершённый раунд.
- Владелец явно поручил Codex в отдельной сессии вместо недоступного Claude.
  Первый Codex запуск был ограничен bwrap; исправленный повтор получил полный
  diff + dossier 52 файлов/правил через stdin. Sandbox оставлен read-only,
  ревьюер не запускал команды/тесты и не менял файлы. Ограничение: контекст и
  reference-scan предоставлены через stdin вместо самостоятельного чтения.
- Исправленная команда:
  `site/bin/external-review.sh 0658c9fc6a3c4c3519f6e254d6669200b6a38753 --reviewer codex --effort medium --context /tmp/customer-ui-codex-review-context.md --out site/var/external-review/0658c9fc-codex-stdin`.
- Результат: exit 0, **REVIEW_GREEN**; сессия
  `01a1e076-ec7d-7741-9f9f-560653ce0ea7`; отчёт
  `site/var/external-review/0658c9fc-codex-stdin/review.txt`.

## Ограничения и продолжение

Миграций, новых dependencies, public financial API/формул, production и CI
изменений нет. Старый POST выбора UI остаётся для совместимости, всегда legacy.
Права локальных каталогов не расширялись; для проверки использован user 0
в disposable test runner с изолированной test DB.

Обязательное review выполнено Codex по явному исключению владельца для текущей
задачи. Merge/deploy не выполнялись; ожидается owner decision для PR #3021.

## Owner decision

Ready: PR #3021 "fix: единый legacy UI для пользовательских страниц site" — merge into master with automatic production deploy?
Reply: "merge and deploy #3021"
