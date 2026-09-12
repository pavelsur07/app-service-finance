# Stage 2 — миграция

- **Риск:** HIGH-LOCAL (необратимое удаление данных)

## Definition of Done

Миграция применяется локально, `doctrine:schema:validate` не показывает
расхождений по удалённой таблице.

## Миграция

`site/migrations/Version20260912120000.php` — `DROP TABLE marketplace_reconciliation_sessions`.
`down()` восстанавливает структуру (копия `up()` из `Version20260410000000`), но
не данные — это указано в докблоке.

## Проверки

- `make site-migrations` → ✅ `Successfully migrated to version: Version20260912120000`.
- `doctrine:schema:validate` → mapping ✅; база «не в синхроне», но это
  **унаследованный** дрейф: 257 операций в `schema:update --dump-sql`, из них
  reconciliation-related только `marketplace_reconciliation_log`
  (мёртвая таблица вне scope, см. FOLLOW-UP). Удалённой
  `marketplace_reconciliation_sessions` в diff нет — дроп консистентен маппингу.
- `make site-test` → ✅ 4452 теста, включая `ModuleWriteGateCoverageTest`
  (порог `marketplace => 50` выдержан) и `ModuleMixedRouteGateTest`.

## Замеры «до» на проде — снять перед прогоном миграции

```sql
SELECT count(*), min(created_at), max(created_at) FROM marketplace_reconciliation_sessions;
SELECT stored_file_path FROM marketplace_reconciliation_sessions ORDER BY created_at;
```

Список путей нужен до дропа: после него связь «сессия → файл» восстановить нечем.

## Файлы в объектном хранилище — удалять ТОЛЬКО поштучно

**Префикс `marketplace/reconciliation/` — общий, удалять его целиком нельзя.**
В него пишет сохранённый `ReconcileCostsAction` («Закрытие месяца»,
`site/src/Marketplace/Application/ReconcileCostsAction.php:51-59`), а путь хранит в
`MarketplaceMonthClose.settings['costs_reconciliation']['file_path']`
(ключ в JSON — snake_case, см. `MarketplaceMonthClose::setCostsReconciliation()`).

Форматы путей совпадают посимвольно:

| Источник | Формат |
|---|---|
| удалённый `ReconciliationUploadController` | `marketplace/reconciliation/{marketplace}/{Y-m}/{uuid}.xlsx` |
| сохранённый `ReconcileCostsAction` | `marketplace/reconciliation/{marketplace}/{Y-m}/{uuid}.xlsx` |

Отличить файлы по пути невозможно — принадлежность знает только БД. Поэтому
удаляются **ровно те объекты**, что перечислены в `stored_file_path` таблицы
`marketplace_reconciliation_sessions`, и никакие другие.

Порядок (обязателен именно такой):

1. Выгрузить `stored_file_path` (раздел «Замеры до») — после дропа список взять негде.
2. Сверить, что ни один путь из списка не занят «Закрытием месяца». Готовый
   запрос (ключ `costs_reconciliation`, snake_case — не camelCase):

   ```sql
   SELECT s.stored_file_path
   FROM marketplace_reconciliation_sessions s
   JOIN marketplace_month_closes mc
     ON mc.settings -> 'costs_reconciliation' ->> 'file_path' = s.stored_file_path;
   ```

   Ожидаемый результат — ноль строк. Любая строка означает общий файл: его из
   списка на удаление исключить.
3. Удалить объекты по списку поштучно, сверив число удалённых со счётчиком строк.
4. Только после этого — дроп таблицы, затем деплой.

## Окно гонки между снимком и деплоем — закрывается пост-сверкой

Пока код не задеплоен, `ReconciliationUploadController` на проде жив, и порядок
конвейера это не меняет: миграция идёт отдельным `workflow_dispatch` **до** деплоя
(AGENTS.md §3.2). Значит на интервале «снимок → дроп → деплой» возможна загрузка,
которую снимок не увидит. Хуже того, контроллер пишет файл в хранилище **до**
вставки строки, поэтому после дропа загрузка оставит объект вообще без строки в БД.

Останавливать трафик ради этого не нужно — окно закрывается проверкой после деплоя:

1. Снимок `stored_file_path` снимать **непосредственно перед** запуском миграции,
   а не заранее, чтобы окно было минимальным.
2. После деплоя перечислить объекты под префиксом `marketplace/reconciliation/`
   средствами хранилища (S3 CLI; `ObjectStorageInterface` листать не умеет).
3. Сироты окна = объекты, которых нет ни в снимке (уже удалены), ни в
   `marketplace_month_closes.settings -> 'costs_reconciliation' ->> 'file_path'`.
   Их удалить тем же поштучным способом.

После деплоя новых писателей в этот префикс со стороны удалённого отчёта нет —
остаётся только «Закрытие месяца», чьи файлы защищены сверкой из шага 3.

Найдено внешним ревью: сначала процедура предписывала снос всего префикса
(уничтожила бы вложения «Закрытия месяца»), затем сверяла несуществующий
camelCase-ключ, затем не учитывала загрузки в окне до деплоя.
