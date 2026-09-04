# Plan: окно восстановления WB-отчётов на границе месяца

Статус: исполнен в Stage 1, ветка `fix/wb-empty-recovery-month-boundary`.

Уточнение после внутреннего ревью: исходная формулировка «пустой день больше не
перезапрашивался» была неточной. После PR #2409 скользящее обновление
(`planRefreshRecentDays`) работает по чисто скользящему окну без привязки к
месяцу и берёт в кандидаты любой SUCCESS/EMPTY день, поэтому пустой день внутри
14 суток оно подбирает — так 31.08 и восстановился в 10:20 04.09. На границе
месяца безвозвратно теряются два других класса дней, и они тяжелее:

- **пропущенные дни без строки статуса** — скользящее обновление их не создаёт
  (`continue` на отсутствующей строке, это зона `planMissing`), поэтому целые
  сутки финансовых данных не загружаются никогда;
- **строки QUEUED/FAILED в режиме, отличном от `refresh_14d`** — зона
  `planDueRetry`, скользящее обновление их пропускает.

Пустые дни при этом получают приоритетную обработку вместо ожидания общей
ротации. Формула окна и состав работ от уточнения не меняются.

## Дефект

`WbFinancialReportsOrchestrateCommand` (прод: ежечасно `20 * * * *`,
`--refresh-days-back=14`) строит окно оперативного восстановления как
`recoveryFrom = currentMonthStart() .. yesterday` (строки 94–96). В это окно
попадают due-retry, missing и пустые (`empty`) дни. Всё старше `recoveryFrom`
считается «историей» и берётся только с `--include-historical-retry`, которого в
cron нет.

Следствие: день, помеченный `empty` в ночь на первое число, уже утром выпадает
из окна и не перезапрашивается никогда. Планировщик `planEmptyRefresh` по
умолчанию тоже стартует с `currentMonthStart()` (строка 208).

Доказательство на проде (2026-09-04, read-only):

- ИП Лазарева и ИП Сухоносов, `business_date = 2026-08-31`: `empty`,
  `attempts = 2`, попытки 00:20 и 01:20 МСК 01.09, `updated_at` не менялся
  трое суток; у Вумджой тот же день `success`, 140 строк.
- Ingestion-канал (`ingest_raw_records`, `wb-sales-report-detailed:2026-08-31`)
  получил у ИП Лазарева 12 190 байт в 03:00 01.09 — WB данные имел, отчёт просто
  ещё не был сформирован в 00:20–01:20.
- После деплоя PR #2409 новое скользящее обновление подобрало 31.08 у ИП
  Лазарева в 10:20 МСК 04.09: 142 строки, `success`. Это компенсация с задержкой
  в часы и только внутри 14 дней; сам дефект окна остаётся.

Окно «с начала месяца» введено коммитом `e433d17b` (2026-06-30, «Fix WB
finance month recovery») без описания причины. Тест
`testCurrentMonthDueRetryOutsideLegacyWindowRunsBeforeRefresh` закрепляет, что
due-retry в начале текущего месяца старше 14 дней относится к оперативному
окну, а не к истории. Это свойство нужно сохранить.

## Решение

Окно восстановления = объединение «с начала месяца» и «последние N дней»:

```text
recoveryFrom = min(currentMonthStart, yesterday − (N − 1) дней)
N = refresh-days-back (14 в cron) — те же дни, которые WB ещё правит
```

Примеры при N = 14:

| Сегодня | currentMonthStart | yesterday − 13 | recoveryFrom |
|---|---|---|---|
| 01.09 | 01.09 | 18.08 | 18.08 |
| 05.09 | 01.09 | 22.08 | 22.08 |
| 20.09 | 01.09 | 06.09 | 01.09 (как сейчас) |

Историческая часть остаётся `currentYearStart .. recoveryFrom − 1` и по-прежнему
дополняет оперативную без перекрытия. Приоритет действий не меняется:
due-retry → missing → empty → rolling refresh.

Правило считается в одном месте — `WbFinancialReportPeriodResolver`, чтобы
оркестратор и `planEmptyRefresh` не разошлись (правило CLAUDE.md «одно
доменное понятие в одном месте»).

Отвергнутый вариант: чисто скользящее окно `yesterday − N .. yesterday` без
«с начала месяца». Ломает закреплённое тестом свойство про due-retry в начале
длинного месяца и без нужды переводит эти дни в «историю».

## Stage 1: окно восстановления с хвостом предыдущего месяца

Risk: MEDIUM (планирование загрузки, без изменения данных и формул)
owner_gate: yes (финальный handoff)
release_candidate: yes
independently_deployable: yes
stage_base_commit: зафиксировать при старте (master после #2409, сейчас `45063ab9`)

### Work items

- **1.1 `WbFinancialReportPeriodResolver::recoveryWindowStart(int $daysBack)`**
  — `min(currentMonthStart(), yesterday()->modify('-(daysBack-1) days'))`,
  не раньше `currentYearStart()`. Unit-тесты: 1-е число, середина месяца,
  20-е число (совпадает с началом месяца), 1 января (упирается в начало года),
  `daysBack = 1`.
- **1.2 Оркестратор** — строка 94: `$recoveryFrom =
  $this->periodResolver->recoveryWindowStart($refreshDaysBack)`. `historicalTo`
  и `hasRecoveryWindow` без изменений (на 1-е число окно теперь всегда есть).
  Обновить текст deprecated-опции `--retry-window-days` и help
  `--refresh-days-back`: она теперь задаёт и глубину хвоста восстановления.
- **1.3 Планировщик** — `planEmptyRefresh`: `$from ??=
  $this->periodResolver->recoveryWindowStart(14)`; константу окна вынести в
  `WbFinancialReportSyncPlanner::DEFAULT_RECOVERY_DAYS_BACK = 14`, чтобы
  дефолт совпадал с cron.
- **1.4 Тесты оркестратора** (`WbFinancialReportsOrchestrateCommandTest`):
  - новый регрессионный тест: `now = 2026-09-01`, `countRetryableEmpty` по
    диапазону `2026-08-18..2026-08-31` возвращает 1 → ожидается ровно один
    вызов `planEmptyRefresh('company-a','conn-a',1, 2026-08-18, 2026-08-31, 24)`.
    На старом коде тест красный: `hasRecoveryWindow = false`, вызывается
    `planRefreshRecentDays`;
  - `testFirstBusinessDayOfMonthDoesNotQueryEmptyRecoveryWindow` переписать в
    `testFirstDayOfMonthRecoversPreviousMonthTail`: диапазон
    `2026-06-01..2026-05-31` по-прежнему не запрашивается, но `2026-05-18..
    2026-05-31` — запрашивается;
  - существующие тесты с `now = 2026-05-21` проверить: окно становится
    `2026-05-08..2026-05-20`? Нет — `min(05-01, 05-08) = 05-01`, диапазоны в
    тестах (`2026-05-01:2026-05-20`) не меняются. Ожидаемых правок там нет; если
    что-то покраснеет — это сигнал об ошибке в 1.1, а не повод менять тест.
- **1.5 Тесты планировщика** — `planEmptyRefresh` без явного `from` на 1-е
  число берёт хвост прошлого месяца.
- **1.6 Документация** — `docs/tasks/wb-empty-recovery-month-boundary/
  handoff.md`; в `docker/cron/app.cron` комментарий к строке оркестратора:
  `refresh-days-back` задаёт и хвост восстановления.

### Definition of Done

- Регрессионный тест 1.4 красный на `stage_base_commit`, зелёный после.
- `phpunit --filter 'WbFinancialReportsOrchestrateCommandTest|
  WbFinancialReportSyncPlannerTest|WbFinancialReportPeriodResolverTest'` зелёные;
  `composer test:unit` зелёный; integration `Marketplace` зелёные.
- `make site-stan` зелёный; `composer cs:check` (конфиг CI `.php-cs-fixer.php`,
  не dist) и `composer cs:strict-types` — `Found 0`.
- Внешнее ревью Codex — `REVIEW_GREEN`.
- Draft PR; merge и автодеплой — только по явному решению Владельца.

### Production Gate (после мержа, по отдельному разрешению)

Проверка на следующий 1-й день месяца невозможна сразу, поэтому доказательство
двухступенчатое:

1. Сразу после деплоя — read-only: вывод оркестратора в логах контейнера
   `scheduler` показывает `recovery empty refresh` для `empty`-дней прошлого
   месяца, если такие остались (сейчас: ИП Сухоносов 31.08, если скользящее
   обновление не успеет раньше).
2. 01.10 после 03:20 МСК — read-only SQL: строк `status = 'empty'` с
   `business_date = 2026-09-30` и `attempts < 24` быть не должно дольше одного
   часа:
   ```bash
   ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-psql-ro -c \"SELECT c.name, s.status, s.attempts, s.records_count, s.updated_at FROM marketplace_financial_report_sync_statuses s JOIN companies c ON c.id = s.company_id WHERE s.marketplace = 'wildberries' AND s.business_date = '2026-09-30' ORDER BY c.name;\"" < /dev/null
   ```

## Вне scope (решение Владельца)

- **Ранние попытки до формирования отчёта.** Оркестратор ставит «daily
  yesterday» уже в 00:20 МСК, а WB формирует отчёт позже (Ingestion-канал в
  03:00 стабильно получает данные). Каждую ночь это даёт 1–2 `empty` с расходом
  `attempts` и лимита API. Вариант: не планировать daily за вчера раньше
  03:00 МСК (порог в `WbFinancialReportPeriodResolver`), либо сдвинуть
  первый прогон. Меняет расписание — отдельная задача.
- `--retry-window-days` deprecated и ничего не делает: удалить в отдельном PR.
