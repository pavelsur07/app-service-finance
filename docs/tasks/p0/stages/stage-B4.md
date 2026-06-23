## Stage B4: NormalizationCompletedEvent не публикуется впустую — DONE

**Риск:** 🟢 LOW
**Следующее действие:** continue autonomously (B7)

### Что сделано
- `NormalizeRawRecordAction::__invoke` — событие `NormalizationCompletedEvent` строится и
  диспатчится только если `$affectedPeriods !== []`. Если все upsert'ы вернули `null` (B3,
  контент не изменился) — подписчики (`PLDirtyPeriod`-marking) не дёргаются.
- Перестановка: `markNormalizationDone()` вызывается **до** `recordControlSumIssues()`
  (внутри того же transaction-блока — §4.1 спеки требует сохранить транзакционность).
  Issues (диагностика) не должны оставлять raw-record доступным для повторной нормализации.

### Затронутые файлы
- `site/src/Ingestion/Application/Action/NormalizeRawRecordAction.php` — modified
- `site/tests/Integration/Ingestion/Application/NormalizeRawRecordActionTest.php` — modified (+2 теста)

### Self-review
- [x] Scope compliance — только B4 (gate + reorder)
- [x] Patterns / naming — без изменений структуры
- [x] Forbidden actions — none; транзакционный блок сохранён (single tx, как и было)
- [x] Security — companyId протекает без изменений
- [x] CS-Fixer — green на изменённых файлах
- [x] Tests — integration green: NormalizeRawRecordActionTest 5/5,
      NormalizationCompletedSubscriberTest + WbFinanceNormalizationFlowTest 4/4
- [x] PHPStan — N/A (не установлен)
- [x] ARCHITECTURE.md — N/A

### Команды для проверки
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite integration --filter 'NormalizeRawRecordActionTest|NormalizationCompletedSubscriberTest|WbFinanceNormalizationFlowTest'`

### Риски / на что обратить внимание ревьюеру
- Спека §4.1 содержала противоречие (сохранить single-transaction блок vs «issues в отдельной
  транзакции best-effort»). Выбран консервативный вариант: тот же transaction-блок, только swap
  порядка `markNormalizationDone`/`recordControlSumIssues`. Полное вынесение issues в отдельную
  транзакцию — отдельная задача при необходимости.

### Открытые вопросы
- нет
