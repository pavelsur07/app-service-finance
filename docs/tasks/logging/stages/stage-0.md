## Stage 0: Reconnaissance — DONE

**Риск:** 🟢 LOW
**Следующее действие:** 🛑 STOP, ждать Владельца (следующий Stage 1 — 🔴 HIGH + есть вопросы, требующие решения)

### Что сделано
Read-only разведка по 4 открытым вопросам плана. Кода не менял, контейнеры не поднимал. Ниже — факты и выводы по каждому.

---

### Q1 — staging vs prod DSN, environment, release

**Факты:**
- `site/.env` (dev): `APP_ENV=dev`, `SENTRY_DSN=https://…@glitchtip.staging.vashfindir.ru/1` — хардкод на **staging GlitchTip, проект id=1**.
- `docker-compose.prod.yml` (корень репо): `APP_ENV: prod`, `SENTRY_DSN: ${SENTRY_DSN}` — подставляется из секрета окружения деплоя, **в репозитории отсутствует**.
- `SENTRY_RELEASE` не упоминается **нигде**; `environment` / `server_name` в `sentry.yaml` не заданы.

**Выводы:**
- `APP_ENV` реально различается по средам в рантайме (`dev` / `prod`) → `environment: '%env(APP_ENV)%'` на Stage 2 сработает и даёт ценность сразу.
- Куда указывает **prod** DSN (тот же проект id=1, что и staging, или отдельный) — **из репозитория не определить** (секрет деплоя). → **вопрос Владельцу.**
- Release-трекинг отсутствует полностью → регрессии не привязать к деплою. На Stage 2 добавляем `release` (git sha из CI/деплоя).

---

### Q2 — 4xx-исключения долетают в GlitchTip?

**Факты:**
- `config/packages/framework.yaml` — блок `framework.exceptions` **не настроен** (grep пуст).
- Symfony 7.4 default `ErrorListener`: `HttpException` со статусом <500 логируется на уровне `ERROR`; ≥500 — `CRITICAL`.
- `sentry`-handler в `monolog.yaml` — отдельный top-level, `level: error` → ловит и эти 4xx.
- `excluded_http_codes: [404,405]` стоит на `main`/`fingers_crossed`, **не** на `sentry`-handler.

**Вывод:** поведение «4xx → GlitchTip как ERROR» подтверждается анализом конфигурации. Эмпирически дампом `debug:config framework exceptions` не проверил — `php` есть только внутри docker (`site-php-cli`), поднимать контейнеры ради recon не стал. → **первое действие Stage 1: один запрос на dev (404/403) с тестовым Monolog-handler, чтобы зафиксировать факт перед правкой.** Риск №1 подтверждён как реальный.

---

### Q3 — legacy_wb_sync карантин: актуален?

**Факты:** канал используется в 3 местах, все логируют `->error()` с маркером `legacy_event => legacy_wb_sync_fail_fast`:
- `src/Marketplace/MessageHandler/SyncWbReportHandler.php:32`
- `src/Marketplace/Command/MarketplaceSyncCommand.php:72` — класс помечен `@deprecated Legacy CLI sync command`
- `src/Marketplace/Facade/MarketplaceSyncFacade.php:93` — методы `@deprecated No active callers`

**Вывод:** это **намеренные fail-fast tripwire'ы** — срабатывают, только если вызван устаревший legacy-путь. Не спам по своей природе (нулевой объём, если legacy не дёргается); ERROR в GlitchTip здесь — это сигнал «кто-то всё ещё ходит в legacy». **Оставляем как есть.**
**Follow-up (вне scope):** если за окно карантина в GlitchTip 0 событий `legacy_wb_sync_fail_fast` — legacy-код можно удалять отдельной задачей.

---

### Q4 — marketplace_ads blind spot

**Факты:**
- Канал `marketplace_ads` целиком исключён из `sentry`-handler (`channels: ["!marketplace_ads"]`), пишется только в `rotating_file` (14 дней).
- В канале **много** `->error()` — смешанные по смыслу:
  - **Реальные инциденты** (сейчас невидимы в алертах): `AdBatchSchedulerCommand:161` «permanent Ozon failure, marking FAILED», `ExtractBatchesToRawDocumentsAction:84` «Batch extraction failed», `AdJobFinalizerCommand:92` «job processing failed», `OzonAdReportPoller:92`, `OzonAdClient:403`.
  - **Транзиентное, помеченное error ошибочно**: `AdBatchSchedulerCommand:185` «transient failure, batch stays PLANNED», `FetchOzonAdStatisticsHandler:210` «Transient failure…» — это по смыслу `warning`.

**Вывод:** blind spot реальный — настоящие перманентные сбои ad-sync не видны в GlitchTip. Но «просто включить канал» нельзя: poller висит до 10 мин и сыпет транзиентными ошибками (часть из них ещё и мислейблена как error) → будет спам. **Правильный порядок: сначала почистить уровни (transient → warning) в `MarketplaceAds` [Stage 6 audit], затем выборочно роутить настоящие ERROR в GlitchTip.** → **вопрос Владельцу:** поднимать ли ads-инциденты в алерты сейчас или оставить файловый канал.

---

### Затронутые файлы
- `docs/tasks/logging/stages/stage-0.md` — new (только отчёт)
- Кода/конфигов не трогал.

### Self-review
- [x] Scope compliance — только разведка, без изменений кода
- [x] Patterns / naming — N/A (нет кода)
- [x] Forbidden actions — none (контейнеры не поднимал, ничего не запускал на staging/prod)
- [x] Security — секреты не печатал в отчёт сверх того, что уже в `.env` репозитория
- [x] PHPStan / CS / tests — N/A (нет изменений кода)
- [x] ARCHITECTURE.md — N/A

### Команды для проверки (для Владельца / Stage 1)
- Эмпирика 4xx: dev-запрос на несуществующий роут + проверка, что для `sentry`-handler создалась ERROR-запись.
- `grep -rn legacy_wb_sync_fail_fast src/` — подтвердить 3 tripwire-сайта.

### Риски / на что обратить внимание ревьюеру
- Куда указывает prod `SENTRY_DSN` — не видно из репо; решение по разделению проектов staging/prod за Владельцем (влияет на Stage 2).
- `marketplace_ads`: включение в алерты требует предварительной чистки уровней, иначе спам.

### Открытые вопросы (требуют решения перед Stage 1/2)
1. **Prod GlitchTip:** prod `SENTRY_DSN` = тот же проект id=1 (staging), или отдельный? Разводим окружения тегом `environment` в одном проекте или физически разными проектами?
2. **marketplace_ads:** поднимаем реальные ERROR-инциденты ads в GlitchTip (после чистки уровней на Stage 6) или сознательно оставляем только файловый канал?
3. **legacy_wb_sync:** подтвердить, что карантин ещё нужен (оставляю как есть по умолчанию).
4. **Release-источник:** откуда брать `SENTRY_RELEASE` — git sha из CI/деплоя? Есть ли в пайплайне переменная, которую можно прокинуть?
