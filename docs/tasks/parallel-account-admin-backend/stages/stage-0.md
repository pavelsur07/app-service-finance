### Stage 0: подготовка фронтэндов account/admin — DONE

**Risk:** HIGH-LOCAL (выделение общего UI/API).
**Stage base commit:** `ee50c9870`.
**Work items:** 0.1–0.5. Один Stage Report по поручению владельца.

#### What was done
- Два независимых React 19 SPA: home/about/404, ErrorBoundary, никакой
  фиктивной авторизации, компаний и финансовых данных; запросов с экранов нет.
- Общие file:-пакеты `site/packages/ui` и `site/packages/api`;
  старые импорты и сигнатуры совместимы, React 18 не обновлён.
- Button/cn переиспользованы; CSS подключён из существующего finance kit.
  Токены, значения и классы кита не менялись. Новых Vite entry или точек
  монтирования в старом Symfony нет; SPA монтируются в собственные `#root`.
- Standalone локальный Compose: Node 24 Debian, Chromium/Playwright 1.56.1,
  loopback 5174/5175 и 4174/4175, strictPort, fallback вложенных адресов.
- Make front-install/dev/typecheck/lint/test/build/e2e с APP и MODE.
- Документация: `apps/README.md`, актуализация `CLAUDE.frontend.md`.

#### Files changed
- `apps/account/`, `apps/admin/` — точки входа, страницы, конфиги, тесты, locks.
- `site/packages/` — общий транспорт/UI и MSW-контракт тестирования.
- `site/assets/api/client*`, старые Button/cn — адаптеры, реэкспорты, тесты.
- `site/vite.config.js`, `site/vitest.config.ts` — собственный React старого site.
- `docker-compose.frontend.yml`, `bin/frontend.sh`, `Makefile`, `.gitignore`.

#### Checks
- Baseline старого frontend: lint/build, 76/76 tests — passed.
- До извлечения API: characterization 14/14 — passed.
- После: старые lint/build, 92/92 tests — passed (14 API + 2 React18 UI).
- Новые typecheck, lint/Prettier, Vitest/MSW 13/13 на приложение, build — passed.
- Production SPA bundles: CSS 73.63 kB; JS ~267.44 kB (~84.15 kB gzip),
  единая версия React 19.3.1 в каждом bundle. Новые сборки не имели baseline.
  Изменения размеров старого bundle несущественны: основной общий JS
  ~142.92 kB, legacy_app ~123.92 kB, без дополнительного runtime React.
- Первичные browser проверки dev/preview desktop/mobile: 8/8 passed.
- Повтор browser под конкурирующим полным PHPStan упёрся в 30s timeout;
  финальный прогон после завершения PHP gates — 8/8 passed.
  Для холодного старта добавлены readiness 30s и общий test timeout 60s.
- PHP handoff gates: PHPStan (3991 files), CS (4027 files), strict-types
  (4027 files), unit (4611 tests, 25546 assertions) — все passed.
  Временный standalone Compose использовал существующий CLI-образ и этот
  worktree, без production credentials и без изменений схемы/БД.
- Browser smoke старого React18 Button + canonical kit прошёл одновременно
  с обоими новыми dev-приложениями; ошибок JavaScript нет.
- Суженные mount проверены: корневой .gitignore, site/var и .secret отсутствуют
  в app-контейнере; запрос root file не раскрывает файл (SPA fallback).
- Временный legacy smoke сначала не подключал CSS как Vite-модуль; исправлен
  fixture и сброшен его stale HTML proxy новым путём (var игнорируется watch).
  Это оснастка smoke, tracked источники не менялись.
- Новые app typecheck/lint/format и 13/13 MSW повторены после fixes — passed.
- `git diff --cached --check`, `bash -n bin/frontend.sh` — passed.

#### Definition of Done
- [x] Самостоятельная установка, сборка, home/about/404/error в обоих apps.
- [x] Общие UI/API с совместимостью старого site и единым CSS/React runtime.
- [x] Контейнерный запуск, loopback порты, APP/MODE, fallback и независимые dist.
- [x] MSW quarantine RED→GREEN; все frontend, browser и PHP gates пройдены.
- [x] Документация, review; PHP/БД/workers/production/CI не изменены.

#### Internal review
- Одна независимая свежая сессия по полному staged diff и checklist.
- BLOCKER / IMPORTANT / MINOR: 0 / 0 / 0.
- Дополнительно reviewer проверил запреты импортов/fetch через ESLint probes.

#### External review
- Required: yes (Large handoff и закрытие единственного HIGH-LOCAL Stage).
- Round 1: `REVIEW_GREEN`. Скрипту передан дополнительный полный diff новых путей,
  поскольку его стандартный список не включает apps и site/packages.
- Четыре замечания MINOR исправлены: fs.allow/volumes сужены; относительный
  вызов frontend.sh исправлен (preview account проверен из apps/); cold-start
  poll 30s; добавлен MSW quarantine test.
- MSW-замечание повышено до IMPORTANT после воспроизведения: версия 2.15
  print.error() только логирует, не останавливая сеть. Встроенная стратегия
  onUnhandledRequest: error блокирует запрос; event request:unhandled
  сохраняет afterEach-проверку неожиданных запросов. Sentinel network fallback
  доказывает отсутствие обхода MSW. RED на прежнем guard → GREEN 13/13 в каждом
  приложении, включая полную suite. Fixed without re-run (BLOCKER не было).
- Внешние метрики после классификации: BLOCKER 0, IMPORTANT 1 (fixed),
  MINOR 3 (fixed); open 0. Внутренние: BLOCKER 0, IMPORTANT 0, MINOR 0.
- Полный внутренний review исправлений: без открытых находок.

#### Decisions / risks / follow-ups
- Общие пакеты размещены внутри site: существующий production Docker context
  не видит соседние корневые packages. Это сохраняет старую сборку и не требует
  исключённых поручением правок Docker/CI. file:-ссылки новых apps остаются локальными.
- Единственный Stage 0 вместо обычных 2–5 Stage — прямое указание владельца.
- npm ci неприменим к старому site (только yarn.lock); baseline установлен
  через npm install --package-lock=false, tracked зависимости не менялись.
- Настоящая авторизация, backend-интеграция и production/CI новых SPA — будущие
  этапы. Схема БД, workers, маршруты PHP и production-конфиги не затронуты.

#### Next
- Handoff PR #2965; ожидается только merge + deploy владельца.
