## Stage 3: Production PHP CLI opcache cleanup and release readiness — DONE

**Риск:** 🟠 HIGH-LOCAL
**Owner gate:** yes
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** 🛑 STOP, ждать Владельца

### Scope Stage

- Stage base commit:
  `597c5514bba6832ab81d4cea4de9fbe62c907dae`.
- Work items completed: `3.1`, `3.2`, `3.3`, `3.4`.

### Что сделано

- В production PHP CLI runtime отключена только динамическая загрузка
  несовместимого `opcache.so`; production PHP-FPM не изменён.
- Docker build fail-closed проверяет, что opcache entry закомментирован и
  `php -v` не выводит `Failed loading Zend extension`.
- Локально собран финальный CLI image и проверены `php -v`, `php -m`,
  Symfony command help, настоящий root-to-`app` entrypoint и `supercronic`.
- Operations guide содержит автоматизируемый local smoke и отделяет
  owner/DevOps production-команды от разрешённых Codex wrappers.
- `ARCHITECTURE.md` фиксирует общий runtime-контракт workers/scheduler.

### Затронутые файлы

- `site/docker/production/php-cli/Dockerfile` — CLI-only opcache fix и
  build-time assertion.
- `docs/tasks/wb-ad-daily-spend/operations.md` — local/production acceptance.
- `ARCHITECTURE.md` — runtime contract и changelog 1.68.
- `docs/tasks/wb-ad-daily-spend-hardening/plan.md` — Stage base и точный
  Production Gate.
- `docs/tasks/wb-ad-daily-spend-hardening/checkpoint.md` — Stage checkpoint.

### Проверки

- Production `site-php-cli` image build: green, PHP 8.3.32.
- Final image smoke:
  - direct `php -v`;
  - direct `php -m`;
  - `app:marketplace-ads:wb-daily-spend --help`;
  - настоящий CLI entrypoint с переключением на `app`;
  - `supercronic -version`;
  - opcache ini entry assertion;
  - результат: green, startup warning отсутствует.
- MarketplaceAds unit: 346 tests / 2203 assertions, green.
- MarketplaceAds integration: 173 tests / 697 assertions, green.
- Task-scoped PHP CS Fixer: 11 files, green.
- PHP lint: 11 files, green.
- Symfony container lint: green.
- `git diff --check`: green.
- `make site-test`: PHPUnit не стартовал из-за предсуществующего drift
  test-схемы — `Version20250219120000` повторно добавляет
  `bot_links.updated_at`.
- `make site-cs-check`: 585 предсуществующих repository-wide нарушений; все
  task-owned PHP-файлы проходят тот же formatter.

### Self-review

- [x] Scope compliance: только CLI image и task documentation.
- [x] PHP-FPM, cron schedule, financial formulas, Ozon Ads не изменены.
- [x] Worker/scheduler entrypoint compatibility проверена.
- [x] Production actions отсутствуют.
- [x] Owner-owned dirty/untracked files не изменены и не включены.
- [x] Tests / lint / image smoke — green с документированными baseline
  ограничениями полного репозитория.
- [x] `ARCHITECTURE.md` updated.

### External Claude Code review

- Iterations: 3.
- Iteration 1: `REVIEW_GREEN`, пять safe MINOR подтверждены и исправлены:
  build assertion, автоматизируемый smoke, real entrypoint, operator/Codex
  boundary, architecture placement.
- Iteration 2: `REVIEW_GREEN`, три safe MINOR подтверждены и исправлены:
  `.so`-совместимый assertion, актуальный resume checkpoint, точный wrapper
  contract.
- Iteration 3: `REVIEW_GREEN`; BLOCKER/IMPORTANT отсутствуют.
- Unresolved BLOCKER/IMPORTANT: none.

### Follow-up вне Stage

- Отдельно проверить production PHP-FPM opcache на Alpine/musl; текущий Stage
  намеренно не меняет FPM.
- В отдельной инфраструктурной задаче решить, стоит ли вообще компилировать
  opcache в CLI image и хранить неактивные `opcache.enable*` directives.
- При расширении image health checks рассмотреть общий guard для других
  dynamic-extension startup warnings; текущий guard намеренно opcache-only.

### Production Gate

- Не выполнялся и не разрешён.
- Merge, deploy, production checks и live WB rerun требуют отдельных явных
  решений Владельца.
