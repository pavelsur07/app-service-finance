# Account/admin frontend Stage 0 — handoff

**Branch:** `docs/parallel-account-admin-backend-plan` · **PR:**
https://github.com/pavelsur07/app-service-finance/pull/2965

## Результат
Два React19 SPA в apps/account и apps/admin с home/about/404/error,
независимыми npm locks, строгими проверками и локальным Compose.
Общие file: UI/API в site/packages; canonical finance CSS и совместимые
старые импорты. React18 старого site и его login redirect сохранены.
Команды и структура: apps/README.md. Один Stage Report: stages/stage-0.md.

## Проверки
- Baseline site: lint/build, 76 tests; до API extraction 14 characterization tests.
- После extraction: site lint/build, 92 tests; React18 browser smoke совместно
  с двумя React19 dev-приложениями.
- front-install (npm ci each), typecheck, lint/Prettier, build, MSW 13 tests each.
- Playwright Chromium dev/preview, desktop/mobile, оба apps: 8/8 final passed.
  Preview account дополнительно запущен из apps/ относительным путём скрипта.
- site-stan, site-cs-check, site-cs-strict-types, site-test-unit — passed
  (3991 analyzed files, 4027 style files, 4611 tests/25546 assertions).
- Новых PHP integration paths нет; site-test не запускался.
- Первые e2e repeat timeout под PHPStan исправлены ожиданием и последовательной
  проверкой. Временная legacy fixture исправлена на CSS module import/fresh URL;
  корректный browser smoke passed. Артефакты не входят в Git.

## Review
Внутренний reviewer в свежей сессии: 0 BLOCKER/0 IMPORTANT/0 MINOR.
Внешний Claude Code, medium, один раунд: REVIEW_GREEN; полный supplemental
new-path diff через --context. Четыре замечания исправлены; MSW замечание
повышено до IMPORTANT по воспроизведению и закрыто RED→GREEN + полной suite.
Итоговые внешние метрики: 0 BLOCKER/1 IMPORTANT fixed/3 MINOR fixed; open 0.
Fixed without re-run; BLOCKER не было. Self-review исправлений clean.

## Границы и решения
- Пакеты внутри site, поскольку старые production Docker builds видят только
  этот context. Production/CI, PHP endpoints, migrations, БД и workers не менялись.
- Один Stage 0 вместо обычных 2–5 — прямое указание владельца.
- Старый site имеет yarn.lock, поэтому исходный npm ci неприменим; baseline
  установлен через npm install --package-lock=false без tracked dependency changes.
- Только localhost/loopback dev и preview. Авторизация, Symfony API integration,
  бизнес-экраны и размещение новых SPA в production — будущие этапы.
- Vite fs/mounts ограничены apps/packages/styles; CSS mounted read-only.
- Локальные dev account/admin оставлены запущенными на 5174/5175.

## Owner decision
Ready: PR #2965 "feat(frontend): подготовить приложения account и admin" — merge into master with automatic production deploy?
Reply: "merge and deploy #2965"
